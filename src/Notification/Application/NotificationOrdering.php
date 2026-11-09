<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Psr\Clock\ClockInterface;

/**
 * Ordre des notifications d'un même laissez-passer (LOT 05.3.4). La mise à disposition passe TOUJOURS en premier ; un changement ordinaire
 * (extension de commune, et plus tard zones, véhicules, prolongation) est décidé sous le verrou de sa ligne, les messages de mise à disposition
 * du laissez-passer étant lus sous verrou partagé (leur état ne peut pas changer pendant la décision) :
 *
 *  1. un message de mise à disposition est PENDING ou RESERVED → l'extension ATTEND (jamais supprimée parce que le premier est en attente) ;
 *  2. aucun message de mise à disposition n'a été ENVOYÉ (abandonné, sans destinataire, ou inexistant) → l'extension est SUPPRIMÉE
 *     (`FIRST_NOT_DELIVERED`) : annoncer « en plus de votre lien » serait faux ; la remise manuelle reste possible ;
 *  3. un message ENVOYÉ (mise à disposition ou extension) listait déjà cette autorisation → l'extension est SUPPRIMÉE (`ALREADY_INCLUDED`), preuve par les identifiants conservés ;
 *  4. sinon (changement postérieur à la composition) → elle suit son cours.
 *
 * Une extension n'est jamais supprimée sans preuve : sans identité d'autorisation, aucune suppression pour redondance. La première mise à
 * disposition et les révocations ne sont pas soumises à cet ordre. Chaque suppression est auditée (identifiants et motif, jamais de donnée personnelle).
 */
final readonly class NotificationOrdering
{
    public function __construct(
        private TransactionRunner $transactions,
        private NotificationOutboxRepository $outbox,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    public function decide(\Symfony\Component\Uid\Uuid $id): OrderingDecision
    {
        return $this->transactions->run(function () use ($id): OrderingDecision {
            $row = $this->outbox->lock($id);
            $now = $this->clock->now();
            if (null === $row || !$row->isDispatchable($now)) {
                return OrderingDecision::Skipped;
            }
            if (ChangeCategory::Ordinary !== $row->category()) {
                return OrderingDecision::Proceed;
            }
            $firsts = $this->outbox->findFirstMessagesShared($row->permitId());
            foreach ($firsts as $first) {
                if (\in_array($first->status(), [OutboxStatus::Pending, OutboxStatus::Reserved], true)) {
                    return OrderingDecision::Held;
                }
            }
            $anySent = [] !== array_filter($firsts, static fn (NotificationOutbox $f): bool => OutboxStatus::Sent === $f->status());
            // Tout message ENVOYÉ couvre ce qu'il a annoncé : un groupe de plusieurs extensions ne produit ainsi qu'UN message.
            $covered = [];
            foreach ($this->outbox->findSentShared($row->permitId()) as $sent) {
                $covered = [...$covered, ...$sent->coveredAuthorizations()];
            }
            $authorization = $row->authorizationId()?->toRfc4122();
            $reason = match (true) {
                !$anySent => OutboxErrorCode::FirstNotDelivered,
                null !== $authorization && \in_array($authorization, $covered, true) => OutboxErrorCode::AlreadyIncluded,
                default => null,
            };
            if (null === $reason) {
                return OrderingDecision::Proceed;
            }
            $row->suppress($reason, $now);
            self::auditSuppression($this->audit, $row, $now);

            return OrderingDecision::Suppressed;
        });
    }

    /** Audit d'une suppression : qui (le système), quoi, pourquoi ; jamais d'adresse, de nom ni de secret. */
    public static function auditSuppression(AuditTrail $audit, NotificationOutbox $row, \DateTimeImmutable $now): void
    {
        $audit->record(new AuditEvent($now, AuditAction::NotificationSuppressed, AuditSubjectType::Permit, $row->permitId(), null, AuditActor::system(), null, [
            'notification' => $row->type()->value,
            'authorization' => $row->authorizationId()?->toRfc4122(),
            'reason' => $row->lastErrorCode(),
        ]));
    }
}
