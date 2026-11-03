<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\User;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistrement d'audit du module Event (D5) : réutilise AuditTrail (aucun second système) ; l'écriture part avec le
 * flush du TransactionRunner, donc dans la MÊME transaction que la modification et annulée avec elle.
 *
 * Les instantanés ne contiennent que des champs métier structurés : jamais de texte libre (nom, description, consignes,
 * libellé de zone, message d'invitation, motif) ni de donnée personnelle ; dates en UTC.
 */
final readonly class EventAuditRecorder
{
    public function __construct(private AuditTrail $audit)
    {
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>      $new
     */
    public function record(
        AuditAction $action,
        AuditSubjectType $subject,
        Uuid $subjectId,
        Uuid $municipalityId,
        User $actor,
        \DateTimeImmutable $now,
        ?array $previous,
        array $new,
        ?Uuid $batchId = null,
    ): void {
        $this->audit->record(new AuditEvent($now, $action, $subject, $subjectId, $municipalityId, AuditActor::user($actor->id()), $previous, $new, $batchId));
    }

    /**
     * @return array<string, mixed>
     */
    public static function eventState(Event $event): array
    {
        return [
            'reference' => $event->publicReference()->value,
            'type' => $event->type()->code(),
            'timezone' => $event->timezone()->identifier(),
            'startsAt' => self::utc($event->startsAt()),
            'endsAt' => null === $event->endsAt() ? null : self::utc($event->endsAt()),
            'status' => $event->status()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function participationState(EventParticipation $participation): array
    {
        return [
            'event' => $participation->event()->id()->toRfc4122(),
            'municipality' => $participation->municipality()->id()->toRfc4122(),
            'status' => $participation->status()->value,
            'organizer' => $participation->isOrganizer(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function zoneState(EventZone $zone, ?string $origin = null): array
    {
        $state = [
            'participation' => $zone->participation()->id()->toRfc4122(),
            'type' => $zone->type()->value,
            'status' => $zone->status()->value,
            'confirmed' => $zone->isConfirmed(),
            'validityMode' => $zone->validityMode()->value,
            'deleted' => $zone->isDeleted(),
        ];
        if (null !== $origin) {
            $state['origin'] = $origin;
        }

        return $state;
    }

    private static function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
