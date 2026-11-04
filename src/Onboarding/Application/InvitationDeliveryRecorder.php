<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationLink;
use App\Onboarding\Domain\InvitationLinkRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Envoie l'e-mail d'un lien et en note le résultat (statut du lien + audit), sans jamais perdre le lien : un échec d'envoi est
 * attrapé, journalisé SANS jeton, lien ni adresse, puis le statut FAILED permet de renvoyer. Écriture hors de la transaction qui a
 * émis le lien : le lien est déjà enregistré quand on tente l'envoi.
 */
final readonly class InvitationDeliveryRecorder
{
    public function __construct(
        private InvitationLinkRepository $links,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(): void $send envoie l'e-mail ; lève en cas d'échec
     */
    public function sendAndRecord(InvitationLink $link, AuditActor $actor, \Closure $send): bool
    {
        $sent = false;
        try {
            $send();
            $sent = true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec d\'envoi d\'un e-mail d\'invitation.', ['exception' => $e::class, 'linkId' => $link->id()->toRfc4122()]);
        }
        $linkId = $link->id();
        $this->transaction->run(function () use ($linkId, $actor, $sent): void {
            $link = $this->links->find($linkId) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $now = $this->clock->now();
            $link->markDelivery($sent ? InvitationDeliveryStatus::Sent : InvitationDeliveryStatus::Failed, $now);
            $this->audit->record(new AuditEvent($now, $sent ? AuditAction::InvitationEmailSent : AuditAction::InvitationEmailFailed, AuditSubjectType::InvitationLink, $link->id(), $link->participation()->municipality()->id(), $actor, null, ['kind' => $link->kind()->value, 'recipient' => InvitationDispatcher::mask($link->recipientEmail())]));
        });

        return $sent;
    }
}
