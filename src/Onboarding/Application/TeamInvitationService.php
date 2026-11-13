<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Application\MembershipAccessPolicy;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityRole;
use App\Municipality\Domain\MunicipalityStatus;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Invitations de collaborateurs émises par un ADMINISTRATEUR COMMUNAL (LOT 07.2) : émettre avec des rôles, renvoyer, révoquer. Même
 * mécanisme sécurisé que CWS (DT-053) : jeton haché, usage unique, 7 jours, un seul lien utilisable par (commune, adresse), renvoi
 * invalidant l'ancien lien, aucun droit avant acceptation, aucun jeton ni adresse dans l'audit.
 *
 * Spécificités d'un acteur communal : administrateur effectif de CETTE commune ACTIVE (relu sous le verrou de la commune : un
 * administrateur rétrogradé à l'instant n'invite plus) ; toute invitation ciblée doit appartenir à la commune ; refus NEUTRES qui ne
 * révèlent rien d'un compte hors de la commune (compte CWS, désactivé ou inconnu = même message) ; débit limité par commune et par
 * administrateur.
 */
final readonly class TeamInvitationService
{
    private string $lifetime;

    public function __construct(
        private TransactionRunner $transaction,
        private MunicipalityRepository $municipalities,
        private MunicipalityAdminInvitationRepository $invitations,
        private MembershipRepository $memberships,
        private MembershipAccessPolicy $policy,
        private UserRepository $users,
        private InvitationDelivery $delivery,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[Autowire(service: 'limiter.team_invitation_commune')]
        private RateLimiterFactory $communeLimiter,
        #[Autowire(service: 'limiter.team_invitation_admin')]
        private RateLimiterFactory $adminLimiter,
        #[Autowire('%env(int:ADMIN_INVITATION_DAYS)%')]
        int $days = 7,
    ) {
        $this->lifetime = sprintf('P%dD', max(1, min(30, $days)));
    }

    /**
     * @param list<MunicipalityRole> $roles
     *
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function issue(Municipality $municipality, string $email, array $roles, User $actor): AdminInvitationIssued
    {
        $this->assertAdministrator($actor, $municipality);
        MunicipalityAdminInvitation::normalizeRoles($roles); // refuse un choix vide avant toute écriture
        $address = EmailAddress::fromString($email);
        $this->assertInvitable($municipality, $address);

        return $this->createAndSend($municipality, $address, $roles, $actor, null, AuditAction::AdminInvitationIssued);
    }

    /**
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function resend(Municipality $municipality, MunicipalityAdminInvitation $invitation, User $actor): AdminInvitationIssued
    {
        $this->assertAdministrator($actor, $municipality);
        $fresh = $this->owned($municipality, $invitation);
        if (null !== $fresh->consumedAt()) {
            throw new DomainRuleViolation('Cette invitation a déjà été acceptée.');
        }
        $address = EmailAddress::fromString($fresh->recipientEmail());
        $this->assertInvitable($municipality, $address);

        return $this->createAndSend($municipality, $address, $fresh->roles(), $actor, $fresh, AuditAction::AdminInvitationResent);
    }

    public function revoke(Municipality $municipality, MunicipalityAdminInvitation $invitation, User $actor): void
    {
        $this->assertAdministrator($actor, $municipality);
        $id = $this->owned($municipality, $invitation)->id();
        $municipalityId = $municipality->id();
        $this->transaction->run(function () use ($id, $municipalityId, $actor): void {
            $locked = $this->municipalities->lock($municipalityId) ?? throw new OperationNotAllowed('Commune introuvable.');
            $this->assertAdministratorLocked($actor, $locked);
            $now = $this->clock->now();
            if (!$this->invitations->revokeIfOpen($id, $actor->id(), $now)) {
                throw new DomainRuleViolation('Cette invitation n\'est plus en attente.');
            }
            $this->audit->record(new AuditEvent($now, AuditAction::AdminInvitationRevoked, AuditSubjectType::MunicipalityAdminInvitation, $id, $municipalityId, AuditActor::user($actor->id()), null, ['state' => 'REVOKED']));
        });
    }

    /**
     * @param list<MunicipalityRole> $roles
     */
    private function createAndSend(Municipality $municipality, EmailAddress $address, array $roles, User $actor, ?MunicipalityAdminInvitation $parent, AuditAction $action): AdminInvitationIssued
    {
        $this->consumeQuota($municipality, $actor);
        $token = InvitationToken::generate();
        $invitation = $this->transaction->run(function () use ($municipality, $address, $roles, $actor, $parent, $action, $token): MunicipalityAdminInvitation {
            $locked = $this->municipalities->lock($municipality->id()) ?? throw new OperationNotAllowed('Commune introuvable.');
            $this->assertAdministratorLocked($actor, $locked);
            $now = $this->clock->now();
            $this->invitations->revokeOpen($locked->id(), $address->value, $actor->id(), $now);
            $invitation = new MunicipalityAdminInvitation($locked, $address->value, $token->hash(), $actor, $now, $this->lifetime, $parent, $roles);
            $this->invitations->add($invitation);
            $this->audit->record(new AuditEvent($now, $action, AuditSubjectType::MunicipalityAdminInvitation, $invitation->id(), $locked->id(), AuditActor::user($actor->id()), null, [
                'expiresAt' => $invitation->expiresAt()->format(\DATE_ATOM),
                'roles' => array_map(static fn (MunicipalityRole $r): string => $r->value, $invitation->roles()),
            ]));

            return $invitation;
        });

        return new AdminInvitationIssued($invitation, $this->delivery->deliver($invitation, $token, $actor, trim($actor->firstName().' '.$actor->lastName())));
    }

    private function owned(Municipality $municipality, MunicipalityAdminInvitation $invitation): MunicipalityAdminInvitation
    {
        $fresh = $this->invitations->find($invitation->id());
        if (null === $fresh || !$fresh->municipality()->id()->equals($municipality->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.'); // identifiant forgé ou d'une autre commune
        }

        return $fresh;
    }

    private function consumeQuota(Municipality $municipality, User $actor): void
    {
        $byCommune = $this->communeLimiter->create($municipality->id()->toRfc4122())->consume();
        $byAdmin = $this->adminLimiter->create($municipality->id()->toRfc4122().'|'.$actor->id()->toRfc4122())->consume();
        if (!$byCommune->isAccepted() || !$byAdmin->isAccepted()) {
            throw new DomainRuleViolation('Trop d\'invitations envoyées récemment. Réessayez dans quelques minutes.');
        }
    }

    /** Refus NEUTRES : rien ne révèle la nature d'un compte hors de la commune. */
    private function assertInvitable(Municipality $municipality, EmailAddress $address): void
    {
        $existing = $this->users->findByEmail($address);
        if (null === $existing) {
            return;
        }
        if ($existing->isSuperAdmin() || !$existing->isActive()) {
            throw new DomainRuleViolation('Cette adresse ne peut pas être invitée.');
        }
        $membership = $this->memberships->findOneByUserAndMunicipality($existing, $municipality);
        if (null !== $membership && $membership->isActive()) {
            throw new DomainRuleViolation('Cette personne fait déjà partie de l\'équipe : modifiez ses rôles.');
        }
    }

    private function assertAdministrator(User $actor, Municipality $municipality): void
    {
        if (!$this->policy->hasRole($actor, $municipality, MunicipalityRole::MunicipalityAdmin)) {
            throw new OperationNotAllowed('Droit insuffisant pour gérer l\'équipe de cette commune.');
        }
    }

    /** Relecture COURANTE sous le verrou de la commune : commune ACTIVE et acteur toujours administrateur effectif. */
    private function assertAdministratorLocked(User $actor, Municipality $locked): void
    {
        if (MunicipalityStatus::Active !== $locked->status() || !$this->memberships->isEffectiveAdmin($actor, $locked)) {
            throw new OperationNotAllowed('Droit insuffisant pour gérer l\'équipe de cette commune.');
        }
    }
}
