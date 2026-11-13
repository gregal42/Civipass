<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\User;
use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Onboarding\Domain\MunicipalityAdminInvitationRepository;
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
 * Envoi de l'e-mail d'une invitation à rejoindre une commune et enregistrement du résultat (statut + audit), commun aux invitations
 * émises par CWS et par un administrateur communal. Hors de la transaction d'émission : le lien est déjà enregistré quand on tente
 * l'envoi ; un échec est attrapé et journalisé SANS jeton, lien ni adresse, puis le statut FAILED permet de renvoyer.
 */
final readonly class InvitationDelivery
{
    public function __construct(
        private AdminInvitationNotifier $notifier,
        private MunicipalityAdminInvitationRepository $invitations,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(MunicipalityAdminInvitation $invitation, InvitationToken $token, User $actor, ?string $inviterName = null): bool
    {
        $sent = false;
        try {
            $this->notifier->send($invitation, $token->plain, $inviterName);
            $sent = true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec d\'envoi d\'une invitation à rejoindre une commune.', ['exception' => $e::class, 'invitationId' => $invitation->id()->toRfc4122()]);
        }
        $id = $invitation->id();
        $this->transaction->run(function () use ($id, $sent, $actor): void {
            $fresh = $this->invitations->find($id) ?? throw new OperationNotAllowed('Invitation introuvable.');
            $now = $this->clock->now();
            $fresh->markDelivery($sent ? InvitationDeliveryStatus::Sent : InvitationDeliveryStatus::Failed, $now);
            $this->audit->record(new AuditEvent($now, $sent ? AuditAction::AdminInvitationEmailSent : AuditAction::AdminInvitationEmailFailed, AuditSubjectType::MunicipalityAdminInvitation, $fresh->id(), $fresh->municipality()->id(), AuditActor::user($actor->id()), null, ['delivery' => $sent ? 'SENT' : 'FAILED']));
        });

        return $sent;
    }
}
