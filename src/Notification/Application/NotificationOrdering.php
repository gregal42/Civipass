<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationType;
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
 *     pour une RÉVISION, la preuve est l'identifiant de la révision annoncée, jamais l'autorisation (LOT 05.5.1) ;
 *  4. sinon (changement postérieur à la composition) → elle suit son cours.
 *
 * Une extension n'est jamais supprimée sans preuve : sans identité d'autorisation, aucune suppression pour redondance. La première mise à
 * disposition et les révocations ne sont pas soumises à cet ordre. Chaque suppression est auditée (identifiants et motif, jamais de donnée personnelle).
 */
final readonly class NotificationOrdering
{
    /** Durée maximale pendant laquelle une prolongation attend un message bloquant, comptée depuis la PREMIÈRE tentative de celui-ci (fenêtre des réessais SMTP). */
    public const HOLD_LIMIT = 'PT24H';

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
            if (NotificationType::QrReplaced === $row->type()) {
                // Remplacement du QR (prioritaire, jamais retardé) : seule la FUSION des notifications EN ATTENTE s'applique. Un message envoyé couvre les remplacements qui étaient
                // en attente quand il est parti ; il ne couvre JAMAIS un remplacement plus récent (le QR que le bénéficiaire vient d'obtenir serait de nouveau invalide).
                $coveredRevisions = [];
                foreach ($this->outbox->findSentShared($row->permitId()) as $sent) {
                    $coveredRevisions = [...$coveredRevisions, ...$sent->coveredRevisions()];
                }
                if (self::alreadyCovered($row, [], $coveredRevisions)) {
                    $row->suppress(OutboxErrorCode::AlreadyIncluded, $now);
                    self::auditSuppression($this->audit, $row, $now);

                    return OrderingDecision::Suppressed;
                }

                return OrderingDecision::Proceed;
            }
            if (ChangeCategory::Ordinary !== $row->category()) {
                return OrderingDecision::Proceed;
            }
            // Une prolongation attend qu'une REPRISE ou un RACCOURCISSEMENT en attente de la MÊME autorisation soit traité : ils décrivent la période entière et la
            // couvriront ; annoncer d'abord « prolongé jusqu'à… » pour une période qui reprend ou qui a été raccourcie serait inexact. Cette attente est BORNÉE : un
            // message bloquant n'est attendu que pendant la fenêtre de ses essais (24 h après sa première tentative) ; au-delà (bail jamais repris, incident
            // prolongé) il ne retient plus rien : jamais de blocage indéfini.
            if (\in_array($row->type(), [NotificationType::PermitExtended, NotificationType::ZonesChanged, NotificationType::VehiclesChanged], true) && $this->blocked($row, $now)) {
                return OrderingDecision::Held;
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
            $coveredRevisions = [];
            foreach ($this->outbox->findSentShared($row->permitId()) as $sent) {
                $covered = [...$covered, ...$sent->coveredAuthorizations()];
                $coveredRevisions = [...$coveredRevisions, ...$sent->coveredRevisions()];
            }
            $reason = match (true) {
                !$anySent => OutboxErrorCode::FirstNotDelivered,
                self::alreadyCovered($row, $covered, $coveredRevisions) => OutboxErrorCode::AlreadyIncluded,
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

    private function blocked(NotificationOutbox $row, \DateTimeImmutable $now): bool
    {
        // Qui retient quoi : une prolongation attend une reprise ou un raccourcissement ; un ajout de zones attend un retrait de zones, un ajout de plaques attend un retrait ou remplacement de plaques (qui décrira l'état final).
        foreach (match ($row->type()) {
            NotificationType::ZonesChanged => [NotificationType::ZonesRemoved],
            NotificationType::VehiclesChanged => [NotificationType::VehiclesRemoved],
            default => [NotificationType::PermitResumed, NotificationType::PermitShortened],
        } as $type) {
            foreach ($this->outbox->activeOfType($row->permitId(), $type, $row->authorizationId()) as $blocker) {
                $first = $blocker->firstAttemptAt();
                if (null === $first || $now < $first->add(new \DateInterval(self::HOLD_LIMIT))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Preuve de redondance, DISTINCTE selon l'origine de la ligne (LOT 05.5.1, DM-119 C5) :
     *  - nouvelle commune (`AUTHORIZATION_ADDED`) : l'autorisation figurait déjà dans un message envoyé ;
     *  - révision (zones, plaques, période…) : UNIQUEMENT si un message envoyé a annoncé CETTE révision. Que l'autorisation ait déjà été
     *    notifiée ne prouve rien : une modification n'est jamais supprimée pour ce motif.
     *
     * @param list<string> $coveredAuthorizations
     * @param list<string> $coveredRevisions
     */
    private static function alreadyCovered(NotificationOutbox $row, array $coveredAuthorizations, array $coveredRevisions): bool
    {
        if (null !== $row->revisionId()) {
            return \in_array($row->revisionId()->toRfc4122(), $coveredRevisions, true);
        }
        $authorization = $row->authorizationId()?->toRfc4122();

        return NotificationType::AuthorizationAdded === $row->type() && null !== $authorization && \in_array($authorization, $coveredAuthorizations, true);
    }

    /** Audit d'une suppression : qui (le système), quoi, pourquoi ; jamais d'adresse, de nom ni de secret. */
    public static function auditSuppression(AuditTrail $audit, NotificationOutbox $row, \DateTimeImmutable $now): void
    {
        $audit->record(new AuditEvent($now, AuditAction::NotificationSuppressed, AuditSubjectType::Permit, $row->permitId(), null, AuditActor::system(), null, [
            'notification' => $row->type()->value,
            'authorization' => $row->authorizationId()?->toRfc4122(),
            'revision' => $row->revisionId()?->toRfc4122(),
            'reason' => $row->lastErrorCode(),
        ]));
    }
}
