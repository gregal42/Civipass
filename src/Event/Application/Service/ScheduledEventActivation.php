<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventStatus;
use App\Event\Domain\EventZoneRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Activation automatique à l'heure de début (DM-123) : un événement en BROUILLON dont l'heure de début est atteinte passe ACTIVE, par le système, SI et seulement
 * si l'activation manuelle aurait été possible : au moins une zone prête (DM-059) et commune organisatrice ACTIVE. Sinon il reste en brouillon, sans erreur, et la
 * fiche le signale à la commune ; il sera activé au passage suivant dès que les conditions seront réunies. Un événement déjà terminé (fin passée) n'est jamais activé.
 *
 * Mêmes garanties que l'activation manuelle : verrou exclusif de l'événement, conditions RELUES sous verrou, audit dans la même transaction (acteur « système »,
 * `trigger: schedule`). Rejouable sans effet (un événement déjà actif n'est plus candidat). La suspension ou la clôture manuelle prime : seul le brouillon est concerné.
 */
final readonly class ScheduledEventActivation
{
    public function __construct(
        private Connection $connection,
        private EventTransactions $transactions,
        private EventLocker $locker,
        private EventZoneRepository $zones,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{due: int, activated: int, notReady: int, skipped: int}
     */
    public function run(bool $execute, int $limit = 100): array
    {
        $report = ['due' => 0, 'activated' => 0, 'notReady' => 0, 'skipped' => 0];
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $ids = $this->connection->fetchFirstColumn(
            'SELECT e.id FROM event_event e JOIN municipality m ON m.id = e.organizer_municipality_id'
            .' WHERE e.status = :draft AND e.starts_at <= :now AND (e.ends_at IS NULL OR e.ends_at > :now) AND m.status = :active'
            .' ORDER BY e.starts_at, e.id LIMIT '.max(1, $limit),
            ['draft' => EventStatus::Draft->value, 'now' => $now->format('Y-m-d H:i:s'), 'active' => MunicipalityStatus::Active->value],
        );
        foreach ($ids as $raw) {
            ++$report['due'];
            $id = Uuid::fromBinary((string) $raw);
            if (!$execute) {
                $this->zones->existsActivationReadyZone($id) ? ++$report['activated'] : ++$report['notReady'];
                continue;
            }
            ++$report[$this->activate($id)];
        }

        return $report;
    }

    /** @return 'activated'|'notReady'|'skipped' */
    private function activate(Uuid $id): string
    {
        return $this->transactions->run(function () use ($id): string {
            $event = $this->locker->exclusiveEvent($id);
            $now = $this->clock->now(); // après l'acquisition du verrou (DT-038)
            // Conditions relues SOUS verrou : un autre acteur a pu activer, clôturer ou modifier l'événement entre la sélection et le verrou.
            if (EventStatus::Draft !== $event->status()
                || $event->startsAt() > $now
                || (null !== $event->endsAt() && $event->endsAt() <= $now)
                || MunicipalityStatus::Active !== $event->organizerMunicipality()->status()) {
                return 'skipped';
            }
            if (!$this->zones->existsActivationReadyZone($event->id())) {
                return 'notReady';
            }
            $previous = EventAuditRecorder::eventState($event);
            $event->activate($now);
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::EventActivated,
                AuditSubjectType::Event,
                $event->id(),
                $event->organizerMunicipality()->id(),
                AuditActor::system(),
                $previous,
                [...EventAuditRecorder::eventState($event), 'trigger' => 'schedule'],
            ));

            return 'activated';
        });
    }
}
