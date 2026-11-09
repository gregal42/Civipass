<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\ManualRequestPolicy;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Permit\Application\ManualNotificationRequester;
use App\Permit\Domain\ManualNotificationOutcome;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistre la demande manuelle d'un agent (LOT 05.3.4) comme une NOUVELLE intention de mise à disposition. Aucun envoi, aucun secret : le lien
 * et son secret sont créés à l'envoi par le distributeur (jamais un secret supprimé), sans révoquer aucun lien existant. Appelé sous le verrou
 * du laissez-passer. Double clic : même identifiant de formulaire = même clé = une seule ligne. Un seul message de mise à disposition en attente à la fois ;
 * au plus une demande acceptée toutes les 5 minutes et trois par 24 heures (`ManualRequestPolicy`).
 */
#[AsAlias(ManualNotificationRequester::class)]
final readonly class ManualNotificationRecorder implements ManualNotificationRequester
{
    public function __construct(private NotificationOutboxRepository $outbox)
    {
    }

    public function request(Uuid $permitId, Uuid $nonce, \DateTimeImmutable $now): ManualNotificationOutcome
    {
        $row = NotificationOutbox::requestManual($permitId, $nonce, $now);
        // Lectures SOUS verrou (le verrou du laissez-passer sérialise les demandes ; une lecture simple ne verrait pas la demande qu'une autre vient de valider).
        $accepted = $this->outbox->findManualRequestsShared($permitId);
        foreach ($accepted as $earlier) {
            if ($earlier->deduplicationKey() === $row->deduplicationKey()) {
                return ManualNotificationOutcome::Duplicate; // même formulaire : double clic, jamais compté deux fois
            }
        }
        if ($this->outbox->hasActiveFirstMessage($permitId)) {
            return ManualNotificationOutcome::AlreadyPending;
        }
        $refusal = ManualRequestPolicy::refusal(array_map(static fn (NotificationOutbox $o): \DateTimeImmutable => $o->occurredAt(), $accepted), $now);
        if (null !== $refusal) {
            return $refusal;
        }

        return $this->outbox->addIfAbsent($row) ? ManualNotificationOutcome::Created : ManualNotificationOutcome::Duplicate;
    }
}
