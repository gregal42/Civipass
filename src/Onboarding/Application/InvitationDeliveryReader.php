<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationDeliveryView;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * État du dernier e-mail d'invitation de chaque participation d'un événement (lecture seule, pour la commune organisatrice
 * qui a déjà le droit de voir ses invitations). Adresse masquée ; ni jeton ni empreinte.
 */
final readonly class InvitationDeliveryReader
{
    public function __construct(private Connection $connection, private ClockInterface $clock)
    {
    }

    /**
     * @return array<string, InvitationDeliveryView> indexé par identifiant de participation
     */
    public function forEvent(Uuid $eventId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT BIN_TO_UUID(l.participation_id) AS participation_id, l.recipient_email, l.delivery_status, l.delivery_attempted_at, l.expires_at'
            .' FROM onboarding_invitation_link l'
            .' JOIN event_participation p ON p.id = l.participation_id'
            .' WHERE p.event_id = :event AND l.kind = :kind'
            .' AND l.id = (SELECT MAX(l2.id) FROM onboarding_invitation_link l2 WHERE l2.participation_id = l.participation_id AND l2.kind = :kind)',
            ['event' => $eventId->toBinary(), 'kind' => 'CONTACT'],
        );
        $now = $this->clock->now();
        $views = [];
        foreach ($rows as $row) {
            $expires = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
            $views[(string) $row['participation_id']] = new InvitationDeliveryView(
                (string) $row['participation_id'],
                InvitationDispatcher::mask((string) $row['recipient_email']),
                InvitationDeliveryStatus::from((string) $row['delivery_status']),
                null === $row['delivery_attempted_at'] ? null : new \DateTimeImmutable((string) $row['delivery_attempted_at'], new \DateTimeZone('UTC')),
                $expires,
                $now >= $expires,
            );
        }

        return $views;
    }
}
