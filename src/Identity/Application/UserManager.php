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

        $user = new User($address, 'pending', $firstName, $lastName, $this->clock->now());
        try {
            $user->changePassword($this->passwordHasher->hashPassword($user, $plainPassword), $this->clock->now());
        } catch (InvalidPasswordException $e) {
            throw new DomainRuleViolation('Mot de passe refusé par le hacheur.', 0, $e);
        }

        return $this->transaction->run(function () use ($address, $user): User {
            if (null !== $this->users->findByEmail($address)) {
                throw new DomainRuleViolation('Un compte existe déjà avec cette adresse email.');
            }
            $this->users->add($user);

            return $user;
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
