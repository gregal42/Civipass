<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Notification\PasswordResetNotifier;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\PasswordResetRequest;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PlainPasswordPolicy;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réinitialisation du mot de passe : demande (sans rien révéler), contrôle du jeton, changement du mot de passe.
 * Les limitations de débit relèvent de la couche UI ; ce service ne suppose rien sur l'appelant.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private TransactionRunner $transaction,
        private UserRepository $users,
        private PasswordResetRepository $requests,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordResetNotifier $notifier,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Ne révèle jamais l'existence du compte : adresse invalide, inconnue ou compte désactivé = aucun effet, aucune
     * exception. Pour un compte actif : invalide les demandes ouvertes, crée une demande, notifie (jeton en clair
     * uniquement dans l'email).
     */
    public function requestReset(string $email): void
    {
        try {
            $address = EmailAddress::fromString($email);
        } catch (DomainRuleViolation) {
            return;
        }
        $user = $this->users->findByEmail($address);
        if (null === $user || !$user->isActive()) {
            return;
        }

        $token = PasswordResetToken::generate();
        $request = $this->transaction->run(function () use ($user, $token): ?PasswordResetRequest {
            $user = $this->users->lock($user);
            if (!$user->isActive()) {
                return null;
            }
            $now = $this->clock->now();
            $this->requests->invalidateOpenRequestsOf($user, $now);
            $request = new PasswordResetRequest($user, $token->hash(), $now);
            $this->requests->add($request);

            return $request;
        });

        if (null !== $request) {
            $this->notifier->sendResetLink($request->user(), $token->plain, $request->expiresAt());
        }
    }

    /**
     * Le jeton est-il utilisable (existe, non utilisé, non expiré, compte actif) ? Lecture seule, sans consommation.
     */
    public function isTokenUsable(string $plainToken): bool
    {
        return null !== $this->usableRequest($plainToken);
    }

    /**
     * Change le mot de passe. Atomique : consommation du jeton, invalidation des autres jetons, changement du mot de
     * passe et audit sont écrits dans UNE transaction (tout ou rien). La politique de mot de passe est contrôlée AVANT
     * (un mot de passe refusé ne consomme pas le jeton).
     *
     * @throws InvalidPasswordResetToken jeton invalide, expiré, déjà utilisé ou compte non éligible
     * @throws DomainRuleViolation       mot de passe refusé par la politique
     */
    public function resetPassword(string $plainToken, #[\SensitiveParameter] string $newPlainPassword): void
    {
        PlainPasswordPolicy::assertValid($newPlainPassword);

        $request = $this->usableRequest($plainToken) ?? throw new InvalidPasswordResetToken();
        try {
            $hash = $this->passwordHasher->hashPassword($request->user(), $newPlainPassword);
        } catch (InvalidPasswordException $e) {
            throw new DomainRuleViolation('Mot de passe refusé par le hacheur.', 0, $e);
        }
        $requestId = $request->id();
        $userId = $request->user()->id();

        $user = $this->transaction->run(function () use ($requestId, $userId, $hash): \App\Identity\Domain\User {
            $user = $this->users->find($userId) ?? throw new InvalidPasswordResetToken();
            $user = $this->users->lock($user);
            $now = $this->clock->now();
            if (!$user->isActive() || !$this->requests->consume($requestId, $now)) {
                throw new InvalidPasswordResetToken();
            }
            $this->requests->invalidateOpenRequestsOf($user, $now);
            $user->changePassword($hash, $now);
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::UserPasswordReset,
                AuditSubjectType::User,
                $user->id(),
                null,
                AuditActor::user($user->id()),
                null,
                ['passwordReset' => true],
            ));

            return $user;
        });

        $this->notifier->sendPasswordChanged($user);
    }

    private function usableRequest(string $plainToken): ?PasswordResetRequest
    {
        if (!PasswordResetToken::looksValid($plainToken)) {
            return null;
        }
        $request = $this->requests->findByTokenHash(PasswordResetToken::fromPlain($plainToken)->hash());
        if (null === $request || !$request->isUsableAt($this->clock->now()) || !$request->user()->isActive()) {
            return null;
        }

        return $request;
    }
}
