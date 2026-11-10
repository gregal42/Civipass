<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Domain\Permit;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bornes exactes des périodes et fuseau horaire (LOT 05.4.4) : début INCLUS, fin EXCLUE, à la seconde près ; créneaux locaux (Europe/Paris)
 * convertis en UTC avec le bon décalage d'été ou d'hiver, y compris un créneau qui enjambe le changement d'heure ; changement d'heure de
 * printemps (29 mars 2026) et d'automne (25 octobre 2026). La présentation en heure locale est vérifiée par PublicVerificationExtensionTest.
 * Horloge de décision : 12 juin 2026 09:00 UTC ; événement du 10 au 20 juin 2026 (heure de Paris).
 */
#[Group('requires-request-schema')]
final class PublicVerificationTimeTest extends MunicipalDecisionTestCase
{
    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function approve(ApprovalDuration $duration): void
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            null,
            'camille@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['GH-482-KT'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $agent = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $this->instruction()->approve($agent, $this->organizer->id()->toRfc4122(), $id, $duration);
    }

    private function verification(string $at): PublicVerification
    {
        $token = ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
        $this->em->clear();
        $lookup = $this->service(PublicVerificationReader::class)->find($token, $this->at($at));
        self::assertSame(PublicVerificationOutcome::Found, $lookup->outcome);

        return $lookup->verification ?? self::fail('Vérification absente.');
    }

    private function state(string $at): RightState
    {
        $zones = $this->verification($at)->authorizations[0]->zones;
        self::assertCount(1, $zones);

        return $zones[0]->state;
    }

    /** @return list<array{string, string}> périodes prévues de l'unique zone, en UTC */
    private function periods(string $at = '2026-06-12 10:00:00'): array
    {
        return array_map(
            static fn ($p): array => [$p->from->format('Y-m-d H:i:s'), null === $p->until ? '' : $p->until->format('Y-m-d H:i:s')],
            $this->verification($at)->authorizations[0]->zones[0]->periods,
        );
    }

    private function slot(string $date, string $from, string $to): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);
        $this->save(EventZoneValidity::fromSlot($this->em->find(EventZone::class, $this->organizerZone->id()) ?? self::fail('Zone absente.'), ValiditySlot::punctual(T::date($date), T::time($from), T::time($to)), \App\Tests\Support\EventFixtures::now()));
    }

    /** L'événement et l'autorisation sont déplacés autour d'une date de changement d'heure (la décision, elle, reste datée du 12 juin). */
    private function aroundDstChange(string $from, string $to): void
    {
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $from, 'e' => $to]);
        $this->sql('UPDATE permit_authorization SET valid_from = :s', ['s' => $from]);
    }

    // ------------------------------------------------------------------ bornes exactes

    /**
     * @return iterable<string, array{string, RightState}>
     */
    public static function fixedWindowBounds(): iterable
    {
        yield 'une seconde avant le début' => ['2026-06-12 08:59:59', RightState::NotYetValid];
        yield 'à la seconde du début (inclus)' => ['2026-06-12 09:00:00', RightState::Valid];
        yield 'une seconde après le début' => ['2026-06-12 09:00:01', RightState::Valid];
        yield 'une seconde avant la fin' => ['2026-06-15 08:59:59', RightState::Valid];
        yield 'à la seconde de la fin (exclue)' => ['2026-06-15 09:00:00', RightState::Expired];
        yield 'une seconde après la fin' => ['2026-06-15 09:00:01', RightState::Expired];
    }

    #[DataProvider('fixedWindowBounds')]
    public function testTheBoundsOfAFixedDurationAreExactToTheSecond(string $at, RightState $expected): void
    {
        $this->approve(ApprovalDuration::fixed(DurationMode::H72));

        self::assertSame($expected, $this->state($at));
    }

    /**
     * @return iterable<string, array{string, RightState}>
     */
    public static function slotBounds(): iterable
    {
        yield 'une seconde avant le créneau' => ['2026-06-13 06:59:59', RightState::NotYetValid];
        yield 'début du créneau (09:00 à Paris, inclus)' => ['2026-06-13 07:00:00', RightState::Valid];
        yield 'dernière seconde du créneau' => ['2026-06-13 14:59:59', RightState::Valid];
        yield 'fin du créneau (17:00 à Paris, exclue)' => ['2026-06-13 15:00:00', RightState::Expired];
    }

    #[DataProvider('slotBounds')]
    public function testTheBoundsOfALocalSlotAreExactAndFollowTheSummerOffset(string $at, RightState $expected): void
    {
        $this->approve(ApprovalDuration::fixed(DurationMode::H72));
        $this->slot('2026-06-13', '09:00', '17:00');

        self::assertSame($expected, $this->state($at));
    }

    public function testBetweenTwoSlotsTheStatusIsOutsidePeriodAndTheBoundariesAreExact(): void
    {
        $this->approve(ApprovalDuration::fixed(DurationMode::H72));
        $this->slot('2026-06-12', '14:00', '16:00'); // 12:00 à 14:00 UTC
        $this->slot('2026-06-13', '09:00', '17:00'); // 07:00 à 15:00 UTC

        self::assertSame(RightState::NotYetValid, $this->state('2026-06-12 11:59:59'), 'avant le premier créneau : à venir');
        self::assertSame(RightState::Valid, $this->state('2026-06-12 12:00:00'));
        self::assertSame(RightState::Valid, $this->state('2026-06-12 13:59:59'));
        self::assertSame(RightState::OutsidePeriod, $this->state('2026-06-12 14:00:00'), 'fin du premier créneau exclue : entre deux créneaux');
        self::assertSame(RightState::OutsidePeriod, $this->state('2026-06-13 06:59:59'));
        self::assertSame(RightState::Valid, $this->state('2026-06-13 07:00:00'));
    }

    // ------------------------------------------------------------------ changement d'heure

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function dstSlots(): iterable
    {
        yield 'veille du passage à l\'heure d\'été (CET, +1)' => ['2026-03-28', '09:00', '17:00', '2026-03-28 08:00:00', '2026-03-28 16:00:00'];
        yield 'jour du passage à l\'heure d\'été (CEST, +2)' => ['2026-03-29', '09:00', '17:00', '2026-03-29 07:00:00', '2026-03-29 15:00:00'];
        yield 'créneau enjambant le passage à l\'heure d\'été (3 h réelles)' => ['2026-03-29', '00:00', '04:00', '2026-03-28 23:00:00', '2026-03-29 02:00:00'];
    }

    #[DataProvider('dstSlots')]
    public function testSpringDstSlotsAreConvertedWithTheOffsetInForceAtTheirOwnInstants(string $date, string $from, string $to, string $startUtc, string $endUtc): void
    {
        $this->approve(ApprovalDuration::untilClosure());
        $this->aroundDstChange('2026-03-27 00:00:00', '2026-04-02 00:00:00');
        $this->slot($date, $from, $to);

        self::assertSame([[$startUtc, $endUtc]], $this->periods('2026-03-27 12:00:00'));
        self::assertSame(RightState::Valid, $this->state($startUtc), 'début inclus');
        self::assertSame(RightState::Expired, $this->state($endUtc), 'fin exclue');
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function autumnDstSlots(): iterable
    {
        yield 'veille du retour à l\'heure d\'hiver (CEST, +2)' => ['2026-10-24', '09:00', '17:00', '2026-10-24 07:00:00', '2026-10-24 15:00:00'];
        yield 'jour du retour à l\'heure d\'hiver (CET, +1)' => ['2026-10-25', '09:00', '17:00', '2026-10-25 08:00:00', '2026-10-25 16:00:00'];
        yield 'créneau enjambant le retour à l\'heure d\'hiver (5 h réelles)' => ['2026-10-25', '00:00', '04:00', '2026-10-24 22:00:00', '2026-10-25 03:00:00'];
    }

    #[DataProvider('autumnDstSlots')]
    public function testAutumnDstSlotsAreConvertedWithTheOffsetInForceAtTheirOwnInstants(string $date, string $from, string $to, string $startUtc, string $endUtc): void
    {
        $this->approve(ApprovalDuration::untilClosure());
        $this->aroundDstChange('2026-10-23 00:00:00', '2026-10-28 00:00:00');
        $this->slot($date, $from, $to);

        self::assertSame([[$startUtc, $endUtc]], $this->periods('2026-10-23 12:00:00'));
        self::assertSame(RightState::Valid, $this->state($startUtc));
        self::assertSame(RightState::Expired, $this->state($endUtc));
    }

    public function testTheEventTimezoneAccompaniesTheVerificationSoThePageCanWriteLocalTime(): void
    {
        $this->approve(ApprovalDuration::fixed(DurationMode::H72));

        self::assertSame('Europe/Paris', $this->verification('2026-06-12 10:00:00')->timezone);
    }
}
