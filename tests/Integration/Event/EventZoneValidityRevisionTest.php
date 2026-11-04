<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\Support\ValidityRevision;
use App\Event\Domain\Validity\ValidityMode;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ZoneServiceTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Empreinte de la configuration de validité vérifiée par le moteur SOUS les verrous de la zone (MySQL de test) : deux
 * administrateurs partent de la même configuration, le premier enregistre, le second reçoit un conflit (StaleState) sans rien
 * écrire ni écraser. Version séquentielle ; la version réellement concurrente est dans EventZoneValidityRevisionConcurrencyTest.
 */
#[Group('requires-event-schema')]
final class EventZoneValidityRevisionTest extends ZoneServiceTestCase
{
    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = $this->created->proposedZoneId;
    }

    private function revision(): string
    {
        $mode = ValidityMode::from((string) $this->zoneColumn($this->zone, 'validity_mode'));
        $ids = array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM event_zone_validity WHERE zone_id = UNHEX(?) AND deleted_at IS NULL', [$this->hex($this->zone)]));

        return ValidityRevision::of($mode, $ids);
    }

    /** @return list<string> dates de début des créneaux non supprimés */
    private function activeDates(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT DATE_FORMAT(start_date, \'%Y-%m-%d\') FROM event_zone_validity WHERE zone_id = UNHEX(?) AND deleted_at IS NULL ORDER BY start_date', [$this->hex($this->zone)]));
    }

    private function specific(string $date, ?string $expected): ZoneValidityCommand
    {
        return new ZoneValidityCommand('SPECIFIC_PERIODS', [new ValiditySlotInput('PUNCTUAL', $date, '09:00', '17:00')], $expected);
    }

    private function configure(ZoneValidityCommand $command): void
    {
        $this->zoneService()->configureValidity($this->as($this->w->adminO), $this->zone, $command);
    }

    public function testAMatchingRevisionAppliesTheReplacement(): void
    {
        $initial = $this->revision();

        $this->configure($this->specific('2026-12-02', $initial));

        self::assertSame(['2026-12-02'], $this->activeDates());
        self::assertNotSame($initial, $this->revision(), 'toute modification change l’empreinte');
    }

    public function testTwoAdministratorsFromTheSameConfigurationTheSecondGetsAConflictAndNothingIsOverwritten(): void
    {
        $seen = $this->revision(); // les deux administrateurs ont affiché la même configuration initiale

        $this->configure($this->specific('2026-12-02', $seen)); // le premier enregistre
        $afterFirst = $this->revision();
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $slots = $this->rows('SELECT COUNT(*) FROM event_zone_validity');

        try {
            $this->configure($this->specific('2026-12-03', $seen)); // le second part d'une configuration périmée
            self::fail('Le conflit était attendu.');
        } catch (OperationConflict $e) {
            self::assertSame(ConflictCause::StaleState, $e->cause);
        }

        self::assertSame(['2026-12-02'], $this->activeDates(), 'la modification du premier n’est pas écrasée');
        self::assertSame($afterFirst, $this->revision());
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'), 'aucun audit');
        self::assertSame($slots, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'aucune écriture');
    }

    public function testTheSecondAdministratorCanRetryExplicitlyFromTheCurrentConfiguration(): void
    {
        $seen = $this->revision();
        $this->configure($this->specific('2026-12-02', $seen));

        $this->configure($this->specific('2026-12-03', $this->revision())); // nouvelle intention, après relecture

        self::assertSame(['2026-12-03'], $this->activeDates());
    }

    public function testWithoutAnExpectedRevisionTheReplacementIsUnconditional(): void
    {
        $this->configure($this->specific('2026-12-02', null));
        $this->configure($this->specific('2026-12-03', null));

        self::assertSame(['2026-12-03'], $this->activeDates(), 'comportement historique inchangé (opérations groupées comprises)');
    }

    public function testAnIdenticalReplacementWritesNothingAndKeepsTheRevision(): void
    {
        $this->configure($this->specific('2026-12-02', $this->revision()));
        $current = $this->revision();
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->configure($this->specific('2026-12-02', $current));

        self::assertSame($current, $this->revision(), 'configuration identique : mêmes créneaux, même empreinte (DM-088)');
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testTheStaleCheckPrecedesTheValidationOfTheNewConfiguration(): void
    {
        $seen = $this->revision();
        $this->configure($this->specific('2026-12-02', $seen));

        $this->expectException(OperationConflict::class);
        $this->configure(new ZoneValidityCommand('SPECIFIC_PERIODS', [], $seen)); // invalide ET périmée : le conflit prime
    }

    public function testAnInvalidConfigurationStillFailsWithItsOwnMessageWhenTheRevisionIsCurrent(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->configure(new ZoneValidityCommand('SPECIFIC_PERIODS', [], $this->revision()));
    }

    public function testAnUnauthorizedActorNeverLearnsAboutTheConfiguration(): void
    {
        $seen = $this->revision();
        $this->configure($this->specific('2026-12-02', $seen));

        $this->expectException(OperationNotAllowed::class); // le refus d'accès précède la comparaison d'empreinte
        $this->zoneService()->configureValidity($this->as($this->w->adminX), $this->zone, $this->specific('2026-12-03', $seen));
    }

    public function testTheModeChangeIsCoveredByTheRevisionToo(): void
    {
        $seen = $this->revision();
        $this->configure(new ZoneValidityCommand('EVENT_DURATION', [], $seen));

        $this->expectException(OperationConflict::class);
        $this->configure(new ZoneValidityCommand('EVENT_DURATION', [], $seen));
    }
}
