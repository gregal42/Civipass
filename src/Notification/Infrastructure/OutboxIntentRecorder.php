<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SchedulePolicy;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Domain\NotificationIntent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Adaptateur du port de Permit (LOT 05.3.1, D11) : écrit l'intention dans l'outbox, dans la transaction métier de l'appelant. Aucun SMTP,
 * aucun flush, aucune donnée personnelle ; idempotent par clé de dédoublonnage.
 *
 * Regroupement (LOT 05.3.4, D5) : un changement ordinaire rejoint le groupe des changements ordinaires encore en attente du même laissez-passer ;
 * tout le groupe reçoit la MÊME échéance, `min(dernier + 10 min, premier + 60 min)`, calculée à partir des seules dates d'événement (donc
 * indépendante de l'ordre d'arrivée). Contrat : l'appelant tient déjà le verrou du laissez-passer (verrou de la demande à l'approbation), ce qui
 * sérialise les producteurs d'un même laissez-passer ; la première mise à disposition et les révocations ne sont jamais regroupées.
 */
#[AsAlias(NotificationIntentRecorder::class)]
final readonly class OutboxIntentRecorder implements NotificationIntentRecorder
{
    public function __construct(private NotificationOutboxRepository $outbox)
    {
    }

    public function record(NotificationIntent $intent): void
    {
        $row = NotificationOutbox::record(
            $intent->deduplicationKey(),
            NotificationType::from($intent->kind->value),
            $intent->permitId,
            $intent->authorizationId,
            $intent->occurredAt,
            $intent->hasRecipient,
            $intent->revisionId,
        );
        if (!$this->outbox->addIfAbsent($row) || ChangeCategory::Ordinary !== $row->category() || $row->status()->isFinal()) {
            return;
        }
        $group = [$row];
        foreach ($this->outbox->lockPendingOrdinary($intent->permitId) as $sibling) {
            if (!$sibling->id()->equals($row->id())) {
                $group[] = $sibling;
            }
        }
        $dates = array_map(static fn (NotificationOutbox $o): \DateTimeImmutable => $o->occurredAt(), $group);
        $at = SchedulePolicy::groupAvailableAt(min($dates), max($dates));
        foreach ($group as $member) {
            $member->rescheduleTo($at);
        }
    }
}
