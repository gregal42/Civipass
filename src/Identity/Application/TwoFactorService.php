<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\TwoFactorBackupCode;
use App\Identity\Domain\TwoFactorCredential;
use App\Identity\Domain\TwoFactorRepository;
use App\Identity\Domain\User;
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

/**
 * Second facteur TOTP des comptes CWS à privilèges élevés (SEC-006, LOT 11) : inscription (secret, premier code, dix codes de secours affichés UNE fois),
 * vérification à chaque session, réinitialisation tracée. Exigé de tout super-administrateur actif ; les comptes communaux ne sont pas concernés (décision
 * du 19 novembre 2026). Aucun secret, code ni empreinte n'entre dans l'audit ni dans une exception.
 */
final readonly class TwoFactorService
{
    public const BACKUP_CODE_COUNT = 10;

    public function __construct(
        private TwoFactorRepository $credentials,
        private TwoFactorSecretCipher $cipher,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[Autowire('%env(bool:TWO_FACTOR_REQUIRED)%')] private bool $required,
    ) {
    }

    public function isRequiredFor(User $user): bool
    {
        return $this->required && $user->isSuperAdmin() && $user->isActive();
    }

    public function state(User $user): TwoFactorState
    {
        $credential = $this->credentials->findByUser($user);

        return match (true) {
            null === $credential => TwoFactorState::None,
            $credential->isConfirmed() => TwoFactorState::Active,
            default => TwoFactorState::Pending,
        };
    }

    /**
     * Secret à afficher pour l'inscription : celui de l'inscription en cours s'il existe (rechargement de la page), sinon un nouveau.
     *
     * @throws OperationNotAllowed si un second facteur est déjà confirmé
     */
    public function enrollmentChallenge(User $user): EnrollmentChallenge
    {
        $this->assertEligible($user);
        $secret = $this->transaction->run(function () use ($user): string {
            $credential = $this->credentials->findByUser($user);
            if (null !== $credential && $credential->isConfirmed()) {
                throw new OperationNotAllowed('Un second facteur est déjà actif sur ce compte.');
            }
            if (null !== $credential) {
                return $this->cipher->decrypt($credential->encryptedSecret(), $user->id());
            }
            $secret = Totp::generateSecret();
            $this->credentials->add(new TwoFactorCredential($user, $this->cipher->encrypt($secret, $user->id()), $this->clock->now()));

            return $secret;
        });

        return new EnrollmentChallenge($secret, Totp::provisioningUri($secret, $user->email()));
    }

    /**
     * Confirme l'inscription par un premier code valide et retourne les codes de secours EN CLAIR (affichés une seule fois, jamais relisibles).
     *
     * @return list<string>
     *
     * @throws DomainRuleViolation|OperationNotAllowed
     */
    public function confirmEnrollment(User $user, string $input): array
    {
        $this->assertEligible($user);

        return $this->transaction->run(function () use ($user, $input): array {
            $credential = $this->credentials->findByUser($user);
            if (null === $credential || $credential->isConfirmed()) {
                throw new OperationNotAllowed('Aucune inscription en cours pour ce compte.');
            }
            $now = $this->clock->now();
            $step = Totp::match($this->cipher->decrypt($credential->encryptedSecret(), $user->id()), $input, $now);
            if (null === $step) {
                throw new DomainRuleViolation('Code incorrect.');
            }
            $credential->confirm($now, $step);
            $codes = [];
            for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
                $code = strtolower(substr(Totp::base32Encode(random_bytes(7)), 0, 10));
                $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
                $this->credentials->addBackupCode(new TwoFactorBackupCode($credential, $this->cipher->backupCodeHash($code), $now));
            }
            $this->audit->record(new AuditEvent($now, AuditAction::TwoFactorEnrolled, AuditSubjectType::User, $user->id(), null, AuditActor::user($user->id()), null, ['backupCodes' => self::BACKUP_CODE_COUNT]));

            return $codes;
        });
    }

    /** Vérifie un code TOTP (non rejoué) ou un code de secours (usage unique). Ne révèle pas lequel a échoué. */
    public function verify(User $user, string $input): TwoFactorOutcome
    {
        $credential = $this->credentials->findByUser($user);
        if (!$this->isRequiredFor($user) || null === $credential || !$credential->isConfirmed()) {
            return TwoFactorOutcome::Rejected;
        }
        $now = $this->clock->now();
        try {
            $step = Totp::match($this->cipher->decrypt($credential->encryptedSecret(), $user->id()), $input, $now);
        } catch (\RuntimeException) {
            return TwoFactorOutcome::Rejected; // secret illisible (autre APP_SECRET) : réinitialisation nécessaire
        }
        if (null !== $step) {
            return $this->credentials->acceptStep($credential, $step) ? TwoFactorOutcome::Accepted : TwoFactorOutcome::Rejected; // rejeu : refusé
        }
        $code = strtolower(preg_replace('/[^A-Za-z2-7]/', '', $input) ?? '');
        if (10 !== \strlen($code)) {
            return TwoFactorOutcome::Rejected;
        }

        return $this->transaction->run(function () use ($user, $credential, $code, $now): TwoFactorOutcome {
            if (!$this->credentials->consumeBackupCode($credential, $this->cipher->backupCodeHash($code), $now)) {
                return TwoFactorOutcome::Rejected;
            }
            $this->audit->record(new AuditEvent($now, AuditAction::TwoFactorBackupCodeUsed, AuditSubjectType::User, $user->id(), null, AuditActor::user($user->id()), null, ['remaining' => $this->credentials->remainingBackupCodes($credential)]));

            return TwoFactorOutcome::AcceptedBackupCode;
        });
    }

    public function remainingBackupCodes(User $user): int
    {
        $credential = $this->credentials->findByUser($user);

        return null === $credential ? 0 : $this->credentials->remainingBackupCodes($credential);
    }

    /**
     * Réinitialise le second facteur d'un super-administrateur (appareil perdu, codes épuisés) : supprime le secret et les codes ; le compte se réinscrit à
     * sa prochaine connexion. `$operator` est le super-administrateur qui agit ; `null` seulement pour la commande de console (accès au serveur).
     *
     * @throws OperationNotAllowed
     */
    public function reset(User $target, ?User $operator, string $reason): bool
    {
        if (!$target->isSuperAdmin()) {
            throw new OperationNotAllowed('Seuls les comptes CWS ont un second facteur.');
        }
        if (null !== $operator && (!$operator->isActive() || !$operator->isSuperAdmin() || $operator->id()->equals($target->id()))) {
            throw new OperationNotAllowed('Réservé à un autre super-administrateur CWS actif.');
        }
        if ('' === trim($reason)) {
            throw new OperationNotAllowed('Un motif est obligatoire.');
        }

        return $this->transaction->run(function () use ($target, $operator, $reason): bool {
            $removed = $this->credentials->remove($target);
            $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::TwoFactorReset, AuditSubjectType::User, $target->id(), null, null === $operator ? AuditActor::console() : AuditActor::user($operator->id()), null, ['hadSecondFactor' => $removed, 'reason' => mb_substr(trim($reason), 0, 200)]));

            return $removed;
        });
    }

    private function assertEligible(User $user): void
    {
        if (!$this->isRequiredFor($user)) {
            throw new OperationNotAllowed('Le second facteur ne concerne que les comptes CWS actifs.');
        }
    }
}
