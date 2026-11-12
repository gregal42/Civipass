<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AccessLossObserver;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use App\Shared\Application\TransactionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Seul chemin de modification des rôles et de l'activation d'un membership.
 * Chaque opération : transaction + verrou sur la commune + audit dans la même transaction.
 * $actor = null désigne la console / un traitement système.
 */
final readonly class MembershipManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private MembershipRepository $memberships,
        private MembershipAccessPolicy $policy,
        private AuditTrail $audit,
        private ClockInterface $clock,
        /** @var iterable<AccessLossObserver> */
        #[AutowireIterator(AccessLossObserver::TAG)]
        private iterable $accessLossObservers = [],
    ) {
    }

    /**
     * Crée le membership, ou réactive la ligne existante (jamais de doublon).
     *
     * @param list<MunicipalityRole> $roles
     */
    public function grant(User $user, Municipality $municipality, array $roles, ?User $actor): MunicipalityMembership
    {
        return $this->transaction->run(function () use ($user, $municipality, $roles, $actor): MunicipalityMembership {
            $municipality = $this->lockedMunicipality($municipality);
            $this->assertActorCanManage($actor, $municipality);
            if (!$user->isActive()) {
                throw new OperationNotAllowed('Un compte désactivé ne peut pas être rattaché à une commune.');
            }

            $now = $this->clock->now();
            $existing = $this->memberships->findOneByUserAndMunicipality($user, $municipality);
            if (null === $existing) {
                $membership = new MunicipalityMembership($user, $municipality, $roles, $now);
                $this->memberships->add($membership);
                $this->record($membership, AuditAction::MembershipCreated, $actor, null, $now);

                return $membership;
            }
            if ($existing->isActive()) {
                throw new OperationNotAllowed('Cet utilisateur est déjà rattaché à la commune : modifiez ses rôles.');
            }
            $previous = $this->snapshot($existing);
            $existing->activate($roles, $now);
            $this->record($existing, AuditAction::MembershipActivated, $actor, $previous, $now);

            return $existing;
        });
    }

    /**
     * Inscrit `$user` comme PREMIER administrateur d'une commune qui n'en a aucun, à la suite d'un lien d'invitation (UI-02). Seul
     * chemin d'attribution du rôle sans administrateur existant ; il est volontairement étroit :
     *  - rôle MunicipalityAdmin UNIQUEMENT (jamais gestionnaire ni instructeur) ;
     *  - refusé dès que la commune a un administrateur effectif : un lien ne donne jamais de droits sur une commune déjà administrée
     *    (vérifié SOUS le verrou de la commune : deux inscriptions simultanées, une seule aboutit) ;
     *  - commune ACTIVE et compte actif exigés ; un compte déjà rattaché à la commune n'est pas modifié (refus : ses rôles se
     *    gèrent par un administrateur ou le superadmin) ;
     *  - aucun abonnement, aucun droit commercial.
     * `$withinTransaction` s'exécute DANS la transaction, avant l'écriture (consommation atomique du lien) : faux = refus, rien n'est écrit.
     * La vérification d'e-mail prouve le contrôle de la boîte, pas l'habilitation officielle à représenter la mairie (documenté).
     *
     * @param \Closure(): bool $withinTransaction
     *
     * @throws OperationNotAllowed
     */
    public function enrollFirstAdministrator(User $user, Municipality $municipality, \Closure $withinTransaction): MunicipalityMembership
    {
        return $this->transaction->run(function () use ($user, $municipality, $withinTransaction): MunicipalityMembership {
            $municipality = $this->lockedMunicipality($municipality);
            if (MunicipalityStatus::Active !== $municipality->status()) {
                throw new OperationNotAllowed('Cette commune n\'est pas disponible.');
            }
            if (!$user->isActive()) {
                throw new OperationNotAllowed('Un compte désactivé ne peut pas être rattaché à une commune.');
            }
            if ($this->memberships->countEffectiveAdmins($municipality) > 0) {
                throw new OperationNotAllowed('Cette commune dispose déjà d\'un administrateur.');
            }
            $existing = $this->memberships->findOneByUserAndMunicipality($user, $municipality);
            if (null !== $existing && $existing->isActive()) {
                throw new OperationNotAllowed('Ce compte est déjà rattaché à la commune.');
            }
            if (!$withinTransaction()) {
                throw new OperationNotAllowed('Ce lien n\'est plus utilisable.');
            }

            $now = $this->clock->now();
            if (null === $existing) {
                $membership = new MunicipalityMembership($user, $municipality, [MunicipalityRole::MunicipalityAdmin], $now);
                $this->memberships->add($membership);
                $action = AuditAction::MembershipCreated;
                $previous = null;
            } else {
                $previous = $this->snapshot($existing);
                $existing->activate([MunicipalityRole::MunicipalityAdmin], $now);
                $membership = $existing;
                $action = AuditAction::MembershipActivated;
            }
            $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::MunicipalityMembership, $membership->id(), $municipality->id(), AuditActor::user($user->id()), $previous, $this->snapshot($membership) + ['origin' => 'INVITATION_LINK']));

            return $membership;
        });
    }

    /**
     * @param list<MunicipalityRole> $roles
     */
    public function changeRoles(MunicipalityMembership $membership, array $roles, ?User $actor): void
    {
        $this->transaction->run(function () use ($membership, $roles, $actor): void {
            $municipality = $this->lockedMunicipality($membership->municipality());
            $this->entityManager->refresh($membership);
            $this->assertActorCanManage($actor, $municipality);
            if (null !== $actor && $actor->id()->equals($membership->user()->id())) {
                throw new OperationNotAllowed('Vous ne pouvez pas modifier vos propres rôles.');
            }
            if (!$membership->isActive()) {
                throw new OperationNotAllowed('Le membership est inactif : réactivez-le.');
            }

            $previous = $this->snapshot($membership);
            $now = $this->clock->now();
            $probe = clone $membership;
            $probe->changeRoles($roles, $now); // valide les rôles avant toute écriture
            if ($probe->roles() === $membership->roles()) {
                return;
            }
            if ($membership->hasRole(MunicipalityRole::MunicipalityAdmin) && !$probe->hasRole(MunicipalityRole::MunicipalityAdmin)) {
                $this->assertNotLastAdmin($municipality, $membership);
            }
            $lost = array_values(array_filter($membership->roles(), static fn (MunicipalityRole $r): bool => !\in_array($r, $probe->roles(), true)));
            $membership->changeRoles($roles, $now);
            $this->record($membership, AuditAction::MembershipRolesChanged, $actor, $previous, $now);
            foreach ($lost as $role) { // habilitations fines adossées au rôle : révoquées explicitement, jamais rétablies (LOT 05.6.3)
                foreach ($this->accessLossObservers as $observer) {
                    $observer->roleLost($membership->user(), $municipality, $role, $actor, $now);
                }
            }
        });
    }

    public function deactivate(MunicipalityMembership $membership, ?User $actor): void
    {
        $this->transaction->run(function () use ($membership, $actor): void {
            $municipality = $this->lockedMunicipality($membership->municipality());
            $this->entityManager->refresh($membership);
            $this->assertActorCanManage($actor, $municipality);
            if (!$membership->isActive()) {
                return;
            }
            if ($membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
                $this->assertNotLastAdmin($municipality, $membership);
            }
            $previous = $this->snapshot($membership);
            $now = $this->clock->now();
            $membership->deactivate($now);
            $this->record($membership, AuditAction::MembershipDeactivated, $actor, $previous, $now);
            foreach ($this->accessLossObservers as $observer) { // habilitations fines de la commune : révoquées explicitement (LOT 05.6.3)
                $observer->membershipDeactivated($membership->user(), $municipality, $actor, $now);
            }
        });
    }

    private function lockedMunicipality(Municipality $municipality): Municipality
    {
        $locked = $this->municipalities->lock($municipality->id());
        if (null === $locked) {
            throw new OperationNotAllowed('Commune introuvable.');
        }

        return $locked;
    }

    private function assertActorCanManage(?User $actor, Municipality $municipality): void
    {
        if (null !== $actor && !$this->policy->canManageMembers($actor, $municipality)) {
            throw new OperationNotAllowed('Droit insuffisant pour gérer les membres de cette commune.');
        }
    }

    /**
     * Le membership $leaving cesse d'être administrateur : il doit rester au moins un administrateur EFFECTIF
     * (membership actif ET compte actif). Un membership déjà inopérant (compte désactivé) ne compte pas :
     * le retirer ne change rien. Le verrou posé sur la commune sérialise la vérification (une seule requête COUNT).
     */
    private function assertNotLastAdmin(Municipality $municipality, MunicipalityMembership $leaving): void
    {
        if (!$leaving->isActive() || !$leaving->user()->isActive()) {
            return;
        }
        if (0 === $this->memberships->countEffectiveAdmins($municipality, excludingMembershipId: $leaving->id())) {
            throw new OperationNotAllowed('Le dernier administrateur actif de la commune ne peut pas être retiré ni désactivé.');
        }
    }

    /**
     * @return array{roles: list<string>, active: bool}
     */
    private function snapshot(MunicipalityMembership $membership): array
    {
        return [
            'roles' => array_map(static fn (MunicipalityRole $r): string => $r->value, $membership->roles()),
            'active' => $membership->isActive(),
        ];
    }

    /**
     * @param array{roles: list<string>, active: bool}|null $previous
     */
    private function record(MunicipalityMembership $membership, AuditAction $action, ?User $actor, ?array $previous, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditEvent(
            $now,
            $action,
            AuditSubjectType::MunicipalityMembership,
            $membership->id(),
            $membership->municipality()->id(),
            null === $actor ? AuditActor::console() : AuditActor::user($actor->id()),
            $previous,
            $this->snapshot($membership),
        ));
    }
}
