<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Récupération d'accès d'un compte communal par CWS (LOT 07.1) : envoie le lien de réinitialisation EXISTANT (30 minutes, usage
 * unique, envoyé seulement à l'adresse du compte). CWS ne voit, ne choisit ni ne reçoit jamais le lien ni le mot de passe.
 * Jamais pour un compte CWS ni pour son propre compte ; compte désactivé : refusé (l'activer d'abord).
 */
final readonly class CwsAccountRecovery
{
    public function __construct(
        private PasswordResetService $reset,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function sendResetLink(User $target, User $actor): void
    {
        if (!$actor->isActive() || !$actor->isSuperAdmin()) {
            throw new OperationNotAllowed('Réservé à un super-administrateur CWS actif.');
        }
        if ($target->isSuperAdmin() || $target->id()->equals($actor->id())) {
            throw new OperationNotAllowed('Ce compte ne peut pas être modifié depuis le back-office.');
        }
        if (!$target->isActive()) {
            throw new OperationNotAllowed('Ce compte est désactivé : réactivez-le avant d\'envoyer un lien.');
        }
        $this->transaction->run(function () use ($target, $actor): void {
            $now = $this->clock->now();
            $this->audit->record(new AuditEvent($now, AuditAction::UserResetRequestedByCws, AuditSubjectType::User, $target->id(), null, AuditActor::user($actor->id()), null, ['requested' => true]));
        });
        $this->reset->requestReset(EmailAddress::fromString($target->email())->value);
    }
}
