<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\PlainPasswordPolicy;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seul chemin de modification de User::superAdmin et de l'activation des comptes.
 * Chaque opération : transaction + verrou et rafraîchissement de l'utilisateur ciblé + audit.
 * $actor = null désigne la console (procédure d'exploitation) ; il n'autorise AUCUN contournement
 * des protections (dernier superadmin actif, dernier administrateur de commune).
 */
final readonly class UserManager
{
    /**
     * @param iterable<UserDeactivationGuard> $deactivationGuards
     */
    public function __construct(
        private TransactionRunner $transaction,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[AutowireIterator(UserDeactivationGuard::TAG)]
        private iterable $deactivationGuards,
    ) {
    }

    public function createUser(string $email, #[\SensitiveParameter] string $plainPassword, string $firstName, string $lastName): User
    {
        $address = EmailAddress::fromString($email);
        PlainPasswordPolicy::assertValid($plainPassword);

        $user = $this->newUserWithHashedPassword($address, $plainPassword, $firstName, $lastName);

        return $this->transaction->run(function () use ($address, $user): User {
            if (null !== $this->users->findByEmail($address)) {
                throw new DomainRuleViolation('Un compte existe déjà avec cette adresse email.');
            }
            $this->users->add($user);

            return $user;
        });
    }

    /**
     * Initialise le premier superadmin (installation, ou récupération d'incident) depuis la console.
     *
     * - Réservé à la situation « aucun superadmin actif » : sinon refus. Ce n'est PAS un contournement de la
     *   protection du dernier superadmin : elle ne permet ni retrait ni désactivation, seulement une désignation.
     * - Idempotente : si le compte visé est déjà l'unique superadmin actif, rien n'est modifié ni audité.
     * - Compte existant : promu s'il est actif (son mot de passe n'est jamais modifié) ; refusé s'il est désactivé.
     * - Compte inexistant : créé avec le mot de passe fourni (politique de mot de passe appliquée).
     * - Audit `USER_SUPER_ADMIN_CHANGED` avec origine CONSOLE.
     * La lecture verrouillée des superadmins actifs sérialise deux initialisations concurrentes.
     */
    public function initializeSuperAdmin(string $email, #[\SensitiveParameter] ?string $plainPassword, string $firstName, string $lastName): User
    {
        $address = EmailAddress::fromString($email);

        $newUser = null;
        if (null !== $plainPassword) {
            PlainPasswordPolicy::assertValid($plainPassword);
            $newUser = $this->newUserWithHashedPassword($address, $plainPassword, $firstName, $lastName);
        }

        return $this->transaction->run(function () use ($address, $newUser): User {
            $activeSuperAdmins = $this->users->lockActiveSuperAdmins();
            foreach ($activeSuperAdmins as $existing) {
                if ($existing->email() === $address->value) {
                    return $existing; // déjà initialisé : idempotent
                }
            }
            if ([] !== $activeSuperAdmins) {
                throw new OperationNotAllowed('Un superadmin actif existe déjà : cette commande ne sert qu\'à l\'initialisation ou à la récupération.');
            }

            $existing = $this->users->findByEmail($address);
            if (null !== $existing) {
                $existing = $this->users->lock($existing);
                if (!$existing->isActive()) {
                    throw new OperationNotAllowed('Ce compte existe mais est désactivé : réactivez-le avant de le promouvoir.');
                }
                $this->applySuperAdmin($existing, true, null);

                return $existing;
            }
            if (null === $newUser) {
                throw new DomainRuleViolation('Aucun compte n\'existe avec cette adresse : un mot de passe est requis pour le créer.');
            }
            $this->users->add($newUser);
            $this->applySuperAdmin($newUser, true, null);

            return $newUser;
        });
    }

    public function grantSuperAdmin(User $target, ?User $actor): void
    {
        $this->assertActorIsSuperAdminOrConsole($actor);
        $this->transaction->run(function () use ($target, $actor): void {
            $target = $this->users->lock($target);
            if ($target->isSuperAdmin()) {
                return;
            }
            if (!$target->isActive()) {
                throw new OperationNotAllowed('Un compte désactivé ne peut pas devenir superadmin.');
            }
            $this->applySuperAdmin($target, true, $actor);
        });
    }

    public function revokeSuperAdmin(User $target, ?User $actor): void
    {
        $this->assertActorIsSuperAdminOrConsole($actor);
        $this->transaction->run(function () use ($target, $actor): void {
            $target = $this->users->lock($target);
            if (!$target->isSuperAdmin()) {
                return;
            }
            $this->assertNotLastActiveSuperAdmin($target, 'rétrogradé');
            $this->applySuperAdmin($target, false, $actor);
        });
    }

    public function deactivate(User $target, ?User $actor): void
    {
        $this->assertActorIsSuperAdminOrConsole($actor);
        $this->transaction->run(function () use ($target, $actor): void {
            $target = $this->users->lock($target);
            if (!$target->isActive()) {
                return;
            }
            if ($target->isSuperAdmin()) {
                $this->assertNotLastActiveSuperAdmin($target, 'désactivé');
            }
            foreach ($this->deactivationGuards as $guard) {
                $guard->assertCanDeactivate($target);
            }
            $now = $this->clock->now();
            $target->deactivate($now);
            $this->recordActivation($target, false, $actor, $now);
        });
    }

    public function activate(User $target, ?User $actor): void
    {
        $this->assertActorIsSuperAdminOrConsole($actor);
        $this->transaction->run(function () use ($target, $actor): void {
            $target = $this->users->lock($target);
            if ($target->isActive()) {
                return;
            }
            $now = $this->clock->now();
            $target->activate($now);
            $this->recordActivation($target, true, $actor, $now);
        });
    }

    private function newUserWithHashedPassword(EmailAddress $address, #[\SensitiveParameter] string $plainPassword, string $firstName, string $lastName): User
    {
        $user = new User($address, 'pending', $firstName, $lastName, $this->clock->now());
        try {
            $user->changePassword($this->passwordHasher->hashPassword($user, $plainPassword), $this->clock->now());
        } catch (InvalidPasswordException $e) {
            throw new DomainRuleViolation('Mot de passe refusé par le hacheur.', 0, $e);
        }

        return $user;
    }

    private function applySuperAdmin(User $target, bool $value, ?User $actor): void
    {
        $now = $this->clock->now();
        $previous = $target->isSuperAdmin();
        $target->changeSuperAdmin($value, $now);
        $this->audit->record(new AuditEvent(
            $now,
            AuditAction::UserSuperAdminChanged,
            AuditSubjectType::User,
            $target->id(),
            null,
            $this->auditActor($actor),
            ['superAdmin' => $previous],
            ['superAdmin' => $value],
        ));
    }

    private function recordActivation(User $target, bool $active, ?User $actor, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditEvent(
            $now,
            AuditAction::UserActivationChanged,
            AuditSubjectType::User,
            $target->id(),
            null,
            $this->auditActor($actor),
            ['active' => !$active],
            ['active' => $active],
        ));
    }

    private function auditActor(?User $actor): AuditActor
    {
        return null === $actor ? AuditActor::console() : AuditActor::user($actor->id());
    }

    private function assertNotLastActiveSuperAdmin(User $target, string $verb): void
    {
        $activeSuperAdmins = $this->users->lockActiveSuperAdmins();
        $others = array_filter($activeSuperAdmins, static fn (User $u): bool => !$u->id()->equals($target->id()));
        if ([] === $others) {
            throw new OperationNotAllowed(sprintf('Le dernier superadmin actif ne peut pas être %s.', $verb));
        }
    }

    private function assertActorIsSuperAdminOrConsole(?User $actor): void
    {
        if (null !== $actor && !($actor->isActive() && $actor->isSuperAdmin())) {
            throw new OperationNotAllowed('Action réservée à un superadmin actif.');
        }
    }
}
