<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Event\Application\ClosureNotificationNeutralizer;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatus;
use App\Permit\Application\SystemAccessLinks;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Neutralisation des notifications d'un événement qui se clôt (LOT 05.6.2, DM-120), DANS la transaction de clôture, après les verrous
 * événement → participations : les lignes d'outbox viennent EN DERNIER (ordre documenté), sans verrouiller les laissez-passer (`FOR UPDATE OF o`).
 *
 * Sont supprimés (`SUPPRESSED`, code `EVENT_CLOSED`, tout type, prioritaires compris) : les messages en attente et les réservations dont le bail a expiré
 * (le processus qui les tenait est mort). Leur secret chiffré est supprimé et le lien automatique jamais remis est révoqué. Un message RÉSERVÉ à bail
 * valide n'est pas touché : un distributeur l'envoie peut-être en ce moment ; son état restera exact (`SENT`), et la garde G2 du distributeur couvre le reste.
 * Idempotent : une ligne déjà définitive est ignorée. Aucune donnée personnelle n'est lue ni écrite.
 */
#[AsAlias(ClosureNotificationNeutralizer::class)]
final readonly class EventClosureNotifications implements ClosureNotificationNeutralizer
{
    public function __construct(
        private Connection $connection,
        private NotificationOutboxRepository $outbox,
        private NotificationSecretRepository $secrets,
        private SystemAccessLinks $links,
    ) {
    }

    public function neutralize(Uuid $eventId, \DateTimeImmutable $now): array
    {
        // Lecture VERROUILANTE (jamais l'instantané REPEATABLE READ de la transaction) : une ligne validée par un producteur avant la clôture est vue.
        $ids = $this->connection->fetchFirstColumn(
            'SELECT o.id FROM notification_outbox o JOIN permit_permit p ON p.id = o.permit_id'
            .' WHERE p.event_id = :event AND (o.status = :pending OR (o.status = :reserved AND o.leased_until <= :now))'
            .' ORDER BY o.id FOR UPDATE OF o',
            ['event' => $eventId->toBinary(), 'pending' => OutboxStatus::Pending->value, 'reserved' => OutboxStatus::Reserved->value, 'now' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        $report = ['cancelled' => 0, 'secrets' => 0, 'links' => 0];
        foreach ($ids as $binary) {
            $row = $this->outbox->lock(Uuid::fromBinary((string) $binary));
            if (null === $row || $row->status()->isFinal()) {
                continue;
            }
            if (OutboxStatus::Reserved === $row->status() && !$row->isDispatchable($now)) {
                continue; // bail repris entre-temps : envoi en cours
            }
            $row->suppress(OutboxErrorCode::EventClosed, $now);
            ++$report['cancelled'];
            $secret = $this->secrets->findByOutbox($row->id());
            if (null !== $secret) {
                if ($this->links->revoke($secret->accessTokenId(), $now)) {
                    ++$report['links'];
                }
                $this->secrets->remove($secret);
                ++$report['secrets'];
            }
        }

        return $report;
    }
}
