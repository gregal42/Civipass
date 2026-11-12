<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

use App\Event\Domain\DormancyReason;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Event\Application\EventPermission;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Report motivé de l'alerte de dormance (LOT 05.6.5) : 30, 60 ou 90 jours, motif codé OBLIGATOIRE, renouvelable (la fin est toujours « maintenant + durée », jamais cumulée). Réservé
 * au `MUNICIPALITY_ADMIN` et à l'`EVENT_MANAGER` de la commune ORGANISATRICE ; tout autre cas est le refus uniforme. N'agit QUE sur la date de report : ni statut, ni droit, ni date de l'événement.
 */
final class EventDormancySnoozeService
{
    public function __construct(
        private readonly MunicipalityContext $context,
        private readonly EventRepository $events,
        private readonly EventDormancyDetector $detector,
        private readonly TransactionRunner $transaction,
        private readonly Connection $connection,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed refus uniforme
     * @throws DomainRuleViolation durée ou motif invalide, événement non signalé
     */
    public function snooze(User $actor, string $municipalityId, string $eventId, int $days, string $reasonCode): \DateTimeImmutable
    {
        $id = $this->authorized($actor, $municipalityId, $eventId);
        if (!\in_array($days, DormancySettings::SNOOZE_DAYS, true)) {
            throw new DomainRuleViolation('La durée du report doit être de 30, 60 ou 90 jours.');
        }
        $reason = DormancyReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Indiquez le motif du report.');

        /** @var \DateTimeImmutable $until */
        $until = $this->transaction->run(function () use ($actor, $id, $days, $reason): \DateTimeImmutable {
            $row = $this->connection->fetchAssociative('SELECT status, ends_at, dormancy_snoozed_until FROM event_event WHERE id = :e FOR UPDATE', ['e' => $id->toBinary()]);
            if (false === $row || !\in_array($row['status'], ['ACTIVE', 'SUSPENDED'], true) || null !== $row['ends_at']) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            if (null === $this->detector->forEvent($id)) {
                throw new DomainRuleViolation('Cet événement n’est pas signalé comme inactif : aucun report n’est nécessaire.');
            }
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC')); // dates brutes SQL en UTC
            $until = $now->modify(sprintf('+%d days', $days));
            $this->connection->executeStatement('UPDATE event_event SET dormancy_snoozed_until = :u WHERE id = :e', ['u' => $until->format('Y-m-d H:i:s'), 'e' => $id->toBinary()]);
            $organizer = Uuid::fromBinary((string) $this->connection->fetchOne('SELECT organizer_municipality_id FROM event_event WHERE id = :e', ['e' => $id->toBinary()]));
            $this->audit->record(new AuditEvent($now, AuditAction::DormancySnoozed, AuditSubjectType::Event, $id, $organizer, AuditActor::user($actor->id()), ['until' => $row['dormancy_snoozed_until']], ['days' => $days, 'reason' => $reason->value, 'until' => $until->format(\DATE_ATOM)]));

            return $until;
        });

        return $until;
    }

    /** Droit relu côté serveur à chaque appel (jamais déduit de l'affichage du bandeau). */
    public function authorized(User $actor, string $municipalityId, string $eventId): Uuid
    {
        $membership = $this->context->requireMembership($actor, $municipalityId, write: true);
        $entitled = false;
        foreach (EventPermission::dormancyRoles() as $role) {
            $entitled = $entitled || $membership->hasRole($role);
        }
        if (!$entitled) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event || !$event->isOrganizedBy($membership->municipality())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $event->id();
    }
}
