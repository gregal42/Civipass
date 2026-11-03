<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Service\EventZoneService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Application\Support\ZoneValidityConfigurator;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\ZoneServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

#[Group('requires-event-schema')]
final class EventZoneServiceTest extends ZoneServiceTestCase
{
    private function admin(): User
    {
        return $this->as($this->w->adminO);
    }

    private function street(string $label = 'Route départementale 107'): string
    {
        return $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand($label, 'STREET', 'Description'))->zoneId;
    }

    private function organizerParticipation(): string
    {
        return $this->created->participationId;
    }

    // ── Création ─────────────────────────────────────────────────────────────

    public function testCreateWritesAConfirmedManualZoneAndItsAudit(): void
    {
        $result = $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Route départementale 107', 'STREET', 'Fermée'));

        self::assertTrue($result->changed);
        self::assertTrue($result->confirmed);
        self::assertSame('UNCONFIGURED', $result->validityMode->value);
        self::assertSame($this->w->org->id()->toRfc4122(), $result->municipalityId);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM event_zone WHERE label = 'Route départementale 107' AND confirmed_at IS NOT NULL AND type = 'STREET'"));
        $audit = $this->em->getConnection()->fetchAssociative("SELECT * FROM audit_log WHERE action = 'ZONE_CREATED' AND subject_id = UNHEX(?)", [$this->hex($result->zoneId)]);
        self::assertIsArray($audit);
        self::assertStringContainsString('MANUAL', $audit['new_value']);
        self::assertStringNotContainsString('Route départementale', $audit['new_value'], 'DT-037 : pas de texte libre');
        self::assertSame($this->w->org->id()->toBinary(), $audit['municipality_id']);
        self::assertSame($this->w->adminO->id()->toBinary(), $audit['actor_id']);
    }

    public function testWholeMunicipalityNeedsConfirmationAndIsUniqueUntilDeleted(): void
    {
        $this->expectViolation(fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Commune', 'WHOLE_MUNICIPALITY')), 'sans confirmation');
        $this->expectViolation(fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Commune', 'WHOLE_MUNICIPALITY', null, true)), 'doublon de la zone proposée');

        $this->zoneService()->delete($this->admin(), $this->created->proposedZoneId);
        $created = $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Commune entière — manuelle', 'WHOLE_MUNICIPALITY', null, true));

        self::assertTrue($created->confirmed, 'DM-068 : une nouvelle « Commune entière » est possible après suppression');
    }

    public function testInvalidCreationsAreRefusedWithoutAnyWrite(): void
    {
        $zones = $this->rows('SELECT COUNT(*) FROM event_zone');
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $this->expectViolation(fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('   ', 'STREET')), 'libellé vide');
        $this->expectViolation(fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Quartier', 'GEOMETRY')), 'type inconnu');
        $this->expectViolation(fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand(str_repeat('a', 151), 'STREET')), 'libellé trop long');

        self::assertSame($zones, $this->rows('SELECT COUNT(*) FROM event_zone'));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testANonSubscribedParticipatingMunicipalityManagesItsOwnZones(): void
    {
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM municipality_commercial_access WHERE municipality_id = UNHEX(\''.$this->hex($this->w->guestB->id()->toRfc4122()).'\')'));

        $zone = $this->zoneService()->create($this->as($this->w->adminB), $this->participationB, new CreateZoneCommand('Quartier nord', 'DISTRICT'));
        $this->zoneService()->configureValidity($this->as($this->w->adminB), $zone->zoneId, $this->eventDuration());

        self::assertSame($this->w->guestB->id()->toRfc4122(), $zone->municipalityId);
        self::assertSame('EVENT_DURATION', $this->zoneColumn($zone->zoneId, 'validity_mode'));
    }

    // ── Modification, confirmation, suspension, réactivation, suppression ────

    public function testUpdateChangesOnlyLabelAndDescriptionAndKeepsTheValidity(): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));
        $slots = $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL');

        $result = $this->zoneService()->update($this->admin(), $zone, new UpdateZoneCommand('Route renommée'));

        self::assertTrue($result->changed);
        self::assertSame('SPECIFIC_PERIODS', $this->zoneColumn($zone, 'validity_mode'), 'une modification descriptive ne réinitialise pas la validité');
        self::assertSame($slots, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame('STREET', $this->zoneColumn($zone, 'type'), 'type immuable (DM-080)');
        $audit = $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'ZONE_UPDATED'");
        self::assertStringContainsString('label', (string) $audit);
        self::assertStringNotContainsString('Route renommée', (string) $audit);
    }

    public function testUpdateWithoutRealChangeWritesNothing(): void
    {
        $zone = $this->street('Route');
        $updatedAt = $this->zoneColumn($zone, 'updated_at');

        $result = $this->zoneService()->update($this->admin(), $zone, new UpdateZoneCommand('  Route  ', 'Description'));

        self::assertFalse($result->changed);
        self::assertSame(0, $this->auditCount('ZONE_UPDATED'));
        self::assertSame($updatedAt, $this->zoneColumn($zone, 'updated_at'));
        $this->expectViolation(fn () => $this->zoneService()->update($this->admin(), $zone, new UpdateZoneCommand('')), 'libellé vide');
        self::assertSame('Route', $this->zoneColumn($zone, 'label'));
    }

    public function testConfirmOpensNothingByItselfAndIsIdempotent(): void
    {
        $proposed = $this->created->proposedZoneId;
        self::assertNull($this->zoneColumn($proposed, 'confirmed_at'));

        $first = $this->zoneService()->confirm($this->admin(), $proposed);
        $second = $this->zoneService()->confirm($this->admin(), $proposed);

        self::assertTrue($first->changed);
        self::assertTrue($first->confirmed);
        self::assertSame('UNCONFIGURED', $first->validityMode->value, 'confirmée mais pas opérationnelle : validité à configurer');
        self::assertFalse($second->changed);
        self::assertSame(1, $this->auditCount('ZONE_CONFIRMED'));
        $this->expectViolation(fn () => $this->lifecycle()->activate($this->admin(), $this->created->eventId), 'activation sans validité configurée');
    }

    public function testSuspendReactivateDoNotTouchTheEventNorTheOtherZones(): void
    {
        $zone = $this->street();
        $other = $this->street('Autre route');
        $this->activateEvent();

        $this->zoneService()->suspend($this->admin(), $zone);
        self::assertSame('SUSPENDED', $this->zoneColumn($zone, 'status'));
        self::assertSame('ACTIVE', $this->zoneColumn($other, 'status'));
        self::assertSame('ACTIVE', $this->eventStatus());
        $this->expectViolation(fn () => $this->zoneService()->suspend($this->admin(), $zone), 'déjà suspendue');

        $this->zoneService()->reactivate($this->admin(), $zone);
        self::assertSame('ACTIVE', $this->zoneColumn($zone, 'status'));
        $this->expectViolation(fn () => $this->zoneService()->reactivate($this->admin(), $zone), 'déjà active');
        self::assertSame(1, $this->auditCount('ZONE_SUSPENDED'));
        self::assertSame(1, $this->auditCount('ZONE_REACTIVATED'));
    }

    public function testDeleteIsLogicalKeepsTheSlotsAndIsNotReversible(): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));

        $result = $this->zoneService()->delete($this->admin(), $zone);

        self::assertTrue($result->deleted);
        self::assertNotNull($this->zoneColumn($zone, 'deleted_at'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'créneaux conservés (DM-052)');
        self::assertSame(1, $this->auditCount('ZONE_DELETED'));
        foreach ([fn () => $this->zoneService()->reactivate($this->admin(), $zone), fn () => $this->zoneService()->update($this->admin(), $zone, new UpdateZoneCommand('X')), fn () => $this->zoneService()->delete($this->admin(), $zone), fn () => $this->zoneService()->configureValidity($this->admin(), $zone, $this->eventDuration())] as $attempt) {
            $this->expectRefused($attempt, 'zone supprimée');
        }
    }

    public function testADeletedZoneNoLongerCountsForActivation(): void
    {
        $this->makeEventActivatable();
        $this->zoneService()->delete($this->admin(), $this->created->proposedZoneId);

        $this->expectViolation(fn () => $this->lifecycle()->activate($this->admin(), $this->created->eventId), 'seule zone prête supprimée');
        self::assertSame('DRAFT', $this->eventStatus());
    }

    public function testZonesOfAClosedEventOrAPendingInvitationAreNotEditable(): void
    {
        $zone = $this->street();
        $this->lifecycle()->close($this->admin(), $this->created->eventId);

        foreach ([fn () => $this->zoneService()->update($this->admin(), $zone, new UpdateZoneCommand('X')), fn () => $this->zoneService()->create($this->admin(), $this->organizerParticipation(), new CreateZoneCommand('Y', 'STREET')), fn () => $this->zoneService()->suspend($this->as($this->w->adminB), $this->proposedZoneB)] as $attempt) {
            $this->expectRefused($attempt, 'événement clôturé');
        }
    }

    public function testAPendingInvitationCannotEditItsZones(): void
    {
        $invited = $this->participations()->invite($this->admin(), new \App\Event\Application\Command\InviteMunicipalityCommand($this->created->eventId, $this->w->other->id()->toRfc4122()));

        $this->expectRefused(fn () => $this->zoneService()->confirm($this->as($this->w->adminX), $invited->proposedZoneId), 'invitation non acceptée');
    }

    // ── Validité ─────────────────────────────────────────────────────────────

    public function testConfigureValidityReplacesWithHistoryAndOneBatch(): void
    {
        $zone = $this->street();
        $first = $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-02'), $this->punctual('2026-12-03')));
        self::assertTrue($first->changed);
        self::assertCount(2, $first->createdSlotIds);
        self::assertSame([], $first->deletedSlotIds);

        $second = $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-05')));

        self::assertCount(1, $second->createdSlotIds);
        self::assertCount(2, $second->deletedSlotIds);
        self::assertSame(4 - 1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'historique : 2 supprimés + 1 actif');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame(2, $this->auditCount('ZONE_VALIDITY_SLOT_DELETED'));
        self::assertSame(1, $this->auditCount('ZONE_VALIDITY_MODE_CHANGED'), 'seulement lors du premier passage UNCONFIGURED → SPECIFIC_PERIODS');
        self::assertSame(1, $this->rows("SELECT COUNT(DISTINCT batch_id) FROM audit_log WHERE action IN ('ZONE_VALIDITY_SLOT_DELETED') AND batch_id IS NOT NULL"));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM (SELECT batch_id FROM audit_log WHERE action LIKE 'ZONE_VALIDITY_SLOT_%' AND batch_id IS NOT NULL GROUP BY batch_id HAVING SUM(action = 'ZONE_VALIDITY_SLOT_DELETED') = 2 AND SUM(action = 'ZONE_VALIDITY_SLOT_CREATED') = 1) t"), 'un remplacement = un batch commun');
    }

    public function testAnIdenticalReplacementWritesNothing(): void
    {
        $zone = $this->street();
        $command = $this->periods($this->punctual('2026-12-02'), $this->punctual('2026-12-03', '08:00', '12:00'));
        $this->zoneService()->configureValidity($this->admin(), $zone, $command);
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $slots = $this->rows('SELECT COUNT(*) FROM event_zone_validity');
        $updatedAt = $this->zoneColumn($zone, 'updated_at');

        $reordered = $this->periods($this->punctual('2026-12-03', '08:00', '12:00'), $this->punctual('2026-12-02'));
        $result = $this->zoneService()->configureValidity($this->admin(), $zone, $reordered);

        self::assertFalse($result->changed);
        self::assertSame([], $result->createdSlotIds);
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame($slots, $this->rows('SELECT COUNT(*) FROM event_zone_validity'));
        self::assertSame($updatedAt, $this->zoneColumn($zone, 'updated_at'));
    }

    public function testSwitchingTheModeIsARealModificationAndKeepsTheHistory(): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));

        $toDuration = $this->zoneService()->configureValidity($this->admin(), $zone, $this->eventDuration());
        self::assertTrue($toDuration->changed);
        self::assertSame('EVENT_DURATION', $this->zoneColumn($zone, 'validity_mode'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'le créneau est supprimé logiquement, pas physiquement');

        $back = $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));
        self::assertTrue($back->changed, 'un créneau supprimé n\'est jamais rétabli : nouveau créneau');
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM event_zone_validity'));
        self::assertSame(3, $this->auditCount('ZONE_VALIDITY_MODE_CHANGED'));
    }

    /**
     * @return iterable<string, array{string, list<\App\Event\Application\Command\ValiditySlotInput>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'mode inconnu' => ['ALWAYS', []];
        yield 'mode non configuré' => ['UNCONFIGURED', []];
        yield 'durée avec créneau' => ['EVENT_DURATION', [new \App\Event\Application\Command\ValiditySlotInput('PUNCTUAL', '2026-12-02', '09:00', '17:00')]];
        yield 'périodes sans créneau' => ['SPECIFIC_PERIODS', []];
        yield 'type de créneau inconnu' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('WEEKLY', '2026-12-02', '09:00', '17:00')]];
        yield 'date invalide' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('PUNCTUAL', '2026-02-30', '09:00', '17:00')]];
        yield 'fin avant début (ponctuel)' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('PUNCTUAL', '2026-12-02', '17:00', '09:00')]];
        yield 'continu sans date de fin' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('CONTINUOUS', '2026-12-02', '09:00', '17:00')]];
        yield 'continu fin avant début' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('CONTINUOUS', '2026-12-03', '09:00', '17:00', '2026-12-02')]];
        yield 'récurrence sans jour' => ['SPECIFIC_PERIODS', [new \App\Event\Application\Command\ValiditySlotInput('RECURRING', '2026-12-02', '09:00', '17:00', '2026-12-30', [])]];
    }

    /**
     * @param list<\App\Event\Application\Command\ValiditySlotInput> $slots
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationsAreRefusedAndLeaveTheZoneUntouched(string $mode, array $slots): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $zone, new \App\Event\Application\Command\ZoneValidityCommand($mode, $slots)), $mode);

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame('SPECIFIC_PERIODS', $this->zoneColumn($zone, 'validity_mode'));
    }

    // ── Temporalité (fuseau IANA de l'événement, DST) ────────────────────────

    public function testANonexistentLocalTimeIsRefusedWithTheDateAndTheTime(): void
    {
        $zone = $this->street();

        try {
            $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2027-03-28', '02:30', '04:00')));
            self::fail('Heure inexistante acceptée.');
        } catch (InvalidValiditySlot $e) {
            self::assertStringContainsString('02:30', $e->getMessage());
            self::assertStringContainsString('2027-03-28', $e->getMessage());
        }
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM event_zone_validity'));
    }

    public function testAnAmbiguousLocalTimeNeedsAnExplicitChoiceAndTheChoiceIsStored(): void
    {
        $zone = $this->street();
        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-10-25', '02:30', '04:00'))), 'choix manquant');
        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-10-25', '09:00', '10:00', [['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'first']]))), 'choix obsolète');

        foreach (['first', 'second'] as $occurrence) {
            $zone = $this->street('Route '.$occurrence);
            $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-10-25', '02:30', '04:00', [['date' => '2026-10-25', 'field' => 'start', 'occurrence' => $occurrence]])));
            $stored = $this->em->getConnection()->fetchOne('SELECT dst_choices FROM event_zone_validity WHERE zone_id = UNHEX(?) AND deleted_at IS NULL', [$this->hex($zone)]);
            self::assertStringContainsString($occurrence, (string) $stored);
            self::assertStringContainsString('02:30', (string) $this->em->getConnection()->fetchOne('SELECT start_time FROM event_zone_validity WHERE zone_id = UNHEX(?)', [$this->hex($zone)]), 'heure LOCALE stockée, jamais UTC');
        }
    }

    public function testTheEventTimezoneDecidesWhetherATimeExists(): void
    {
        // 2027-03-28 02:30 n'existe pas à Paris mais existe à Miquelon (changement d'heure le 14 mars, règles nord-américaines).
        $miquelon = $this->service(\App\Event\Application\Service\EventCreationService::class)->create(
            $this->admin(),
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand('fire', 'Événement à Miquelon', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC')), null, 'America/Miquelon'),
        );
        $parisZone = $this->street();

        $this->zoneService()->configureValidity($this->admin(), $miquelon->proposedZoneId, $this->periods($this->punctual('2027-03-28', '02:30', '04:00')));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $parisZone, $this->periods($this->punctual('2027-03-28', '02:30', '04:00'))), 'inexistante à Paris');
        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $miquelon->proposedZoneId, $this->periods($this->punctual('2027-03-14', '02:30', '04:00'))), 'inexistante à Miquelon');
    }

    public function testARecurrenceUpToTheLimitIsAcceptedAndBeyondIsRefused(): void
    {
        $zone = $this->street();
        $recurring = static fn (string $end): \App\Event\Application\Command\ValiditySlotInput => new \App\Event\Application\Command\ValiditySlotInput('RECURRING', '2026-12-01', '09:00', '17:00', $end, [1, 2, 3, 4, 5]);

        // 2026-12-01 + 3 660 jours = 2036-12-08 (années bissextiles 2028, 2032 et 2036 comprises).
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($recurring('2036-12-08')));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        $this->expectViolation(fn () => $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($recurring('2036-12-09'))), 'au-delà de 3 660 jours');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
    }

    // ── Plafond de créneaux (DM-096) ─────────────────────────────────────────

    /**
     * @return list<\App\Event\Application\Command\ValiditySlotInput>
     */
    private function manySlots(int $count): array
    {
        $slots = [];
        $day = new \DateTimeImmutable('2027-01-01');
        for ($i = 0; $i < $count; ++$i) {
            $slots[] = $this->punctual($day->modify(sprintf('+%d days', $i))->format('Y-m-d'));
        }

        return $slots;
    }

    public function testExactlyTheSlotLimitIsAccepted(): void
    {
        $zone = $this->street();

        $result = $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods(...$this->manySlots(500)));

        self::assertCount(500, $result->createdSlotIds);
        self::assertSame(500, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame(500, $this->auditCount('ZONE_VALIDITY_SLOT_CREATED'));
    }

    public function testBeyondTheSlotLimitIsRefusedBeforeAnyWriteAndKeepsTheExistingConfiguration(): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual()));
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        try {
            $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods(...$this->manySlots(501)));
            self::fail('Dépassement accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('500', $e->getMessage());
        }

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
    }

    public function testTheSlotLimitIsConfigurableAndWiredFromTheContainer(): void
    {
        $property = new \ReflectionProperty(ZoneValidityConfigurator::class, 'maxSlots');
        self::assertSame(500, $property->getValue($this->service(ZoneValidityConfigurator::class)));

        $configurator = new ZoneValidityConfigurator($this->service(EventZoneValidityRepository::class), $this->service(EventAuditRecorder::class), maxSlots: 2);
        $timezone = \App\Event\Domain\Time\EventTimezone::fromIdentifier('Europe/Paris');
        self::assertCount(2, $configurator->parse($this->periods(...$this->manySlots(2)), $timezone)->slots);
        $this->expectViolation(fn () => $configurator->parse($this->periods(...$this->manySlots(3)), $timezone), 'plafond abaissé à 2');

        $this->expectException(\LogicException::class);
        new ZoneValidityConfigurator($this->service(EventZoneValidityRepository::class), $this->service(EventAuditRecorder::class), maxSlots: 0);
    }

    // ── Permissions, isolation, identifiants forgés ──────────────────────────

    /**
     * @param array<string, User> $users
     */
    private function assertRefusedForAll(array $users, \Closure $operation, string $label): void
    {
        foreach ($users as $name => $user) {
            try {
                $operation($this->as($user));
                self::fail(sprintf('%s accepté pour %s.', $label, $name));
            } catch (OperationNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOnlyTheMunicipalAdministratorManagesTheZonesOfItsOwnParticipation(): void
    {
        $zone = $this->street();
        $w = $this->w;
        $actors = [
            'gestionnaire d\'événement' => $w->managerO,
            'instructeur' => $w->instructorO,
            'commune participante (B)' => $w->adminB,
            'commune étrangère' => $w->adminX,
            'super-admin CWS sans droit municipal' => $w->superAdmin,
        ];
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->create($u, $this->organizerParticipation(), new CreateZoneCommand('Pirate', 'STREET')), 'create');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->update($u, $zone, new UpdateZoneCommand('Pirate')), 'update');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->confirm($u, $this->created->proposedZoneId), 'confirm');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->suspend($u, $zone), 'suspend');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->reactivate($u, $zone), 'reactivate');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->delete($u, $zone), 'delete');
        $this->assertRefusedForAll($actors, fn (User $u) => $this->zoneService()->configureValidity($u, $zone, $this->eventDuration()), 'configureValidity');

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'), 'un refus n\'écrit aucun audit');
        self::assertSame('Route départementale 107', $this->zoneColumn($zone, 'label'));
        self::assertNull($this->zoneColumn($zone, 'deleted_at'));
    }

    public function testTheOrganizerCannotTouchTheZonesOfAParticipatingMunicipality(): void
    {
        $this->assertRefusedForAll(['administrateur organisateur' => $this->w->adminO], function (User $u): void {
            $this->zoneService()->suspend($u, $this->proposedZoneB);
        }, 'suspend de la zone de B');
        $this->assertRefusedForAll(['administrateur organisateur' => $this->w->adminO], fn (User $u) => $this->zoneService()->create($u, $this->participationB, new CreateZoneCommand('Intrusion', 'STREET')), 'create chez B');
        self::assertSame('ACTIVE', $this->zoneColumn($this->proposedZoneB, 'status'));
    }

    public function testForgedIdentifiersAreRefusedUniformly(): void
    {
        $unknown = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $admin = ['admin' => $this->w->adminO];
        foreach (['not-a-uuid', $unknown, $this->created->eventId, $this->created->participationId] as $forged) {
            $this->assertRefusedForAll($admin, fn (User $u) => $this->zoneService()->confirm($u, $forged), 'confirm '.$forged);
            $this->assertRefusedForAll($admin, fn (User $u) => $this->zoneService()->configureValidity($u, $forged, $this->eventDuration()), 'validity '.$forged);
        }
        foreach (['not-a-uuid', $unknown, $this->created->eventId, $this->created->proposedZoneId] as $forged) {
            $this->assertRefusedForAll($admin, fn (User $u) => $this->zoneService()->create($u, $forged, new CreateZoneCommand('X', 'STREET')), 'create '.$forged);
        }
    }

    // ── Rollback ─────────────────────────────────────────────────────────────

    private function failingService(int $failOnCall): EventZoneService
    {
        $c = self::getContainer();
        $audit = new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), $failOnCall));

        return new EventZoneService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(EventLocker::class),
            $c->get(EventZoneRepository::class),
            new ZoneValidityConfigurator($c->get(EventZoneValidityRepository::class), $audit),
            $audit,
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function rolledBackOperations(): iterable
    {
        yield 'create' => ['create', 1];
        yield 'update' => ['update', 1];
        yield 'confirm' => ['confirm', 1];
        yield 'suspend' => ['suspend', 1];
        yield 'delete' => ['delete', 1];
        yield 'configureValidity (échec au 2e audit)' => ['validity', 2];
        yield 'configureValidity (échec au 3e audit)' => ['validity', 3];
    }

    #[DataProvider('rolledBackOperations')]
    public function testAnAuditFailureRollsBackTheWholeOperation(string $operation, int $failOnCall): void
    {
        $zone = $this->street();
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-02')));
        $proposed = $this->created->proposedZoneId;
        $snapshot = fn (): array => [
            $this->rows('SELECT COUNT(*) FROM audit_log'),
            $this->rows('SELECT COUNT(*) FROM event_zone'),
            $this->rows('SELECT COUNT(*) FROM event_zone_validity'),
            $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'),
            $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(id)) id, label, status, confirmed_at, validity_mode, deleted_at, updated_at FROM event_zone ORDER BY id'),
        ];
        $before = $snapshot();
        $service = $this->failingService($failOnCall);
        $admin = $this->admin();

        try {
            match ($operation) {
                'create' => $service->create($admin, $this->organizerParticipation(), new CreateZoneCommand('Nouvelle', 'STREET')),
                'update' => $service->update($admin, $zone, new UpdateZoneCommand('Autre libellé')),
                'confirm' => $service->confirm($admin, $proposed),
                'suspend' => $service->suspend($admin, $zone),
                'delete' => $service->delete($admin, $zone),
                default => $service->configureValidity($admin, $zone, $this->periods($this->punctual('2026-12-09'), $this->punctual('2026-12-10'))),
            };
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        self::assertSame($before, $snapshot(), 'ni modification ni audit ne subsistent');
    }

    private function expectViolation(\Closure $attempt, string $label): void
    {
        try {
            $attempt();
            self::fail('Refus métier attendu : '.$label);
        } catch (DomainRuleViolation|OperationNotAllowed) {
            self::addToAssertionCount(1);
        }
    }

    private function expectRefused(\Closure $attempt, string $label): void
    {
        $this->expectViolation($attempt, $label);
    }
}
