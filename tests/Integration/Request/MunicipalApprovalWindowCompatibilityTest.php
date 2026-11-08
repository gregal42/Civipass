<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Request\Domain\ApprovalDuration;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tout ou rien (DM-109 §6) : CHAQUE zone demandée doit avoir au moins une période d'accès effective DANS la fenêtre d'autorisation
 * choisie. Décision simulée le 12 juin 2026 à 09:00 UTC (11:00 à Paris) ; événement du 10 au 20 juin ; H24 → fin le 13 juin 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class MunicipalApprovalWindowCompatibilityTest extends MunicipalDecisionTestCase
{
    private EventZone $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->second = F::readyZone($this->organizerParticipation);
        $this->save($this->second->createdBy(), $this->second);
    }

    /**
     * Passe la zone en « périodes spécifiques » avec ces créneaux [date, début local, fin local] (le dépôt, qui utilise la vraie
     * horloge, doit avoir eu lieu avant).
     *
     * @param list<array{string, string, string}> $slots
     */
    private function slots(EventZone $zone, array $slots): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $zone->id()->toBinary()]);
        $managed = $this->em->find(EventZone::class, $zone->id());
        foreach ($slots as [$date, $start, $end]) {
            $this->save(EventZoneValidity::fromSlot($managed, ValiditySlot::punctual(T::date($date), T::time($start), T::time($end)), F::now()));
        }
    }

    private function approve(string $requestId, ApprovalDuration $duration): void
    {
        $this->instruction()->approve($this->em->find(\App\Identity\Domain\User::class, $this->instructor->id()), $this->organizer->id()->toRfc4122(), $requestId, $duration);
    }

    private function assertRefused(string $requestId, ApprovalDuration $duration): void
    {
        try {
            $this->approve($requestId, $duration);
            self::fail('Approbation acceptée alors qu\'une zone n\'a aucune période dans la fenêtre.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('aucune période d\'accès dans la durée d\'autorisation choisie', $e->getMessage());
        }
        // Rollback complet : ni décision, ni audit, ni changement de statut.
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame([], $this->decisionAudits());
        self::assertSame('PENDING', $this->partStatus($requestId, $this->organizer));
    }

    private function assertApproved(string $requestId, ApprovalDuration $duration): void
    {
        $this->approve($requestId, $duration);
        self::assertSame('APPROVED', $this->partStatus($requestId, $this->organizer));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    public function testAZoneWithASlotInsideTheWindowIsCompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [['2026-06-12', '14:00', '18:00']]);

        $this->assertApproved($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    public function testAZoneWhoseFirstSlotStartsAfterTheAuthorizationExpiresIsIncompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [['2026-06-14', '09:00', '12:00']]);

        $this->assertRefused($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    public function testSeveralZonesWithDifferentNonOverlappingSlotsAreAllCompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone, $this->second]);
        $this->slots($this->organizerZone, [['2026-06-12', '14:00', '18:00']]);
        $this->slots($this->second, [['2026-06-13', '08:00', '09:00']]); // 06:00–07:00 UTC le 13, avant la fin de la fenêtre (09:00 UTC)

        $this->assertApproved($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    public function testASingleIncompatibleZoneRefusesTheWholeApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone, $this->second]);
        $this->slots($this->organizerZone, [['2026-06-12', '14:00', '18:00']]);
        $this->slots($this->second, [['2026-06-15', '09:00', '12:00']]);

        $this->assertRefused($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
        // Le refus de la demande reste possible : la vérification ne concerne que l'approbation.
        $this->instruction()->refuse($this->em->find(\App\Identity\Domain\User::class, $this->instructor->id()), $this->organizer->id()->toRfc4122(), $requestId, 'zone_not_concerned', null);
        self::assertSame('REFUSED', $this->partStatus($requestId, $this->organizer));
    }

    public function testASlotStartingExactlyWhenTheAuthorizationEndsIsIncompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [['2026-06-13', '11:00', '12:00']]); // 09:00 UTC = fin de la fenêtre H24

        $this->assertRefused($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    public function testASlotStartingOneMinuteBeforeTheEndIsCompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [['2026-06-13', '10:59', '12:00']]);

        $this->assertApproved($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    public function testASlotEndingExactlyWhenTheAuthorizationStartsIsIncompatible(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [['2026-06-12', '09:00', '11:00']]); // se termine à 09:00 UTC = décision

        $this->assertRefused($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }

    /**
     * @return iterable<string, array{string, string|null, string|null, string, bool}>
     */
    public static function modes(): iterable
    {
        // [mode, fin locale, heure, date du créneau (9 h – 17 h Paris), compatible ?]
        yield 'H24 compatible' => ['H24', null, null, '2026-06-12', true];
        yield 'H24 incompatible' => ['H24', null, null, '2026-06-14', false];
        yield 'H48 compatible' => ['H48', null, null, '2026-06-13', true];
        yield 'H48 incompatible' => ['H48', null, null, '2026-06-15', false];
        yield 'H72 compatible' => ['H72', null, null, '2026-06-14', true];
        yield 'H72 incompatible' => ['H72', null, null, '2026-06-16', false];
        yield 'CUSTOM compatible' => ['CUSTOM', '2026-06-13', '18:00', '2026-06-13', true];
        yield 'CUSTOM incompatible' => ['CUSTOM', '2026-06-13', '18:00', '2026-06-14', false];
        yield 'UNTIL_CLOSURE compatible (créneau lointain)' => ['UNTIL_CLOSURE', null, null, '2026-06-19', true];
        yield 'UNTIL_CLOSURE incompatible (créneau passé)' => ['UNTIL_CLOSURE', null, null, '2026-06-11', false];
    }

    #[DataProvider('modes')]
    public function testTheRuleHoldsForEachDurationMode(string $mode, ?string $endDate, ?string $endTime, string $slotDate, bool $compatible): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->slots($this->organizerZone, [[$slotDate, '09:00', '17:00']]);
        $duration = ApprovalDuration::fromInput($mode, $endDate, $endTime);

        $compatible ? $this->assertApproved($requestId, $duration) : $this->assertRefused($requestId, $duration);
    }

    public function testEventDurationZonesAreCompatibleAsLongAsTheEventStillRuns(): void
    {
        $requestId = $this->submit([$this->organizerZone, $this->second]);

        $this->assertApproved($requestId, ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));
    }
}
