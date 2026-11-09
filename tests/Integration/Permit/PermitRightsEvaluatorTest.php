<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Service\ZoneEffectivePeriodsResolver;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRights;
use App\Permit\Domain\RightState;
use App\Permit\Domain\ZoneRight;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\TimeFactory as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Droits effectifs d'un laissez-passer à un instant donné (LOT 05.1, DM-112, DT-047) : un état par autorisation et par zone, jamais un
 * booléen global ; précédence des causes ; limites exactes ; cinq durées ; zones à périodes différentes ; commune, participation,
 * événement, zone, révocation. Horloge de décision : 12 juin 2026 09:00 UTC ; événement du 10 au 20 juin 2026 (heure de Paris).
 */
#[Group('requires-request-schema')]
final class PermitRightsEvaluatorTest extends MunicipalDecisionTestCase
{
    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    /**
     * @param list<EventZone> $zones
     */
    private function request(array $zones): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
            array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones),
            true,
        ), $this->key())->requestId;
    }

    private function approve(User $agent, Municipality $commune, string $id, ?ApprovalDuration $duration = null): void
    {
        $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, $duration ?? ApprovalDuration::fixed(DurationMode::H72));
    }

    private function evaluate(string $at): PermitRights
    {
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.');
        $evaluator = new PermitRightsEvaluator(new ZoneEffectivePeriodsResolver(self::getContainer()->get(EventZoneValidityRepository::class)));

        return $evaluator->evaluate($permit, $this->at($at));
    }

    /** @return list<RightState> */
    private function states(PermitRights $rights): array
    {
        return array_map(static fn (ZoneRight $z): RightState => $z->state, $rights->zones());
    }

    private function slot(EventZone $zone, string $date, string $from = '09:00', string $to = '17:00'): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $zone->id()->toBinary()]);
        $this->save(EventZoneValidity::fromSlot($this->em->find(EventZone::class, $zone->id()) ?? self::fail('Zone absente.'), ValiditySlot::punctual(T::date($date), T::time($from), T::time($to)), F::now()));
    }

    private function simple(?ApprovalDuration $duration = null): void
    {
        $this->approve($this->instructor, $this->organizer, $this->request([$this->organizerZone]), $duration);
    }

    public function testAnApprovedAuthorizationIsValidNowWithItsCurrentPeriodAndTheEventTimezone(): void
    {
        $this->simple();

        $rights = $this->evaluate('2026-06-12 10:00:00');

        self::assertSame('Europe/Paris', $rights->timezone);
        self::assertSame('LP-2026-0001', $rights->reference);
        self::assertSame([RightState::Valid], $this->states($rights));
        self::assertTrue($rights->isFullyValid());
        $zone = $rights->zones()[0];
        self::assertSame($this->organizerZone->label(), $zone->label);
        self::assertNotNull($zone->effectiveUntil);
    }

    /**
     * @return iterable<string, array{string, RightState}>
     */
    public static function exactLimits(): iterable
    {
        yield 'avant la décision (fenêtre pas commencée)' => ['2026-06-12 08:59:59', RightState::NotYetValid];
        yield 'à l\'instant exact de la décision' => ['2026-06-12 09:00:00', RightState::Valid];
        yield 'une seconde avant la fin' => ['2026-06-15 08:59:59', RightState::Valid];
        yield 'à l\'instant exact de la fin (intervalle semi-ouvert)' => ['2026-06-15 09:00:00', RightState::Expired];
        yield 'bien après la fin' => ['2026-06-18 09:00:00', RightState::Expired];
    }

    #[DataProvider('exactLimits')]
    public function testTheExactStartAndEndOfTheWindowAreHonoured(string $at, RightState $expected): void
    {
        $this->simple(ApprovalDuration::fixed(DurationMode::H72));

        self::assertSame([$expected], $this->states($this->evaluate($at)));
    }

    /**
     * @return iterable<string, array{DurationMode, string, string, RightState, RightState}>
     */
    public static function durations(): iterable
    {
        yield 'H24' => [DurationMode::H24, '2026-06-13 08:59:59', '2026-06-13 09:00:00', RightState::Valid, RightState::Expired];
        yield 'H48' => [DurationMode::H48, '2026-06-14 08:59:59', '2026-06-14 09:00:00', RightState::Valid, RightState::Expired];
        yield 'H72' => [DurationMode::H72, '2026-06-15 08:59:59', '2026-06-15 09:00:00', RightState::Valid, RightState::Expired];
    }

    #[DataProvider('durations')]
    public function testEachFixedDurationExpiresExactlyAfterItsHours(DurationMode $mode, string $before, string $after, RightState $beforeState, RightState $afterState): void
    {
        $this->simple(ApprovalDuration::fixed($mode));

        self::assertSame([$beforeState], $this->states($this->evaluate($before)));
        self::assertSame([$afterState], $this->states($this->evaluate($after)));
    }

    public function testACustomEndIsResolvedInTheEventTimezone(): void
    {
        $this->simple(ApprovalDuration::custom('2026-06-15', '18:30')); // 16:30 UTC (heure d'été de Paris)

        self::assertSame([RightState::Valid], $this->states($this->evaluate('2026-06-15 16:29:59')));
        self::assertSame([RightState::Expired], $this->states($this->evaluate('2026-06-15 16:30:00')));
    }

    public function testUntilClosureHasNoCopiedEndAndExpiresWithTheEvent(): void
    {
        $this->simple(ApprovalDuration::untilClosure());
        $end = $this->event->endsAt() ?? self::fail('Fin d\'événement attendue.');

        self::assertSame([RightState::Valid], $this->states($this->evaluate($end->modify('-1 second')->format('Y-m-d H:i:s'))));
        self::assertSame([RightState::Expired], $this->states($this->evaluate($end->format('Y-m-d H:i:s'))));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT valid_until FROM permit_authorization'), 'aucune fin n\'est copiée');
    }

    public function testZonesWithDifferentPeriodsAreEvaluatedIndependentlyAndTheResultIsNeverGloballyValid(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $id = $this->request([$this->organizerZone, $second]); // déposée avant la restriction du créneau (horloge réelle)
        $this->slot($second, '2026-06-14'); // 09:00–17:00 Paris le 14 juin
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());

        $rights = $this->evaluate('2026-06-12 10:00:00');

        $byZone = [];
        foreach ($rights->zones() as $zone) {
            $byZone[$zone->zoneId->toRfc4122()] = $zone;
        }
        self::assertSame(RightState::Valid, $byZone[$this->organizerZone->id()->toRfc4122()]->state);
        self::assertSame(RightState::NotYetValid, $byZone[$second->id()->toRfc4122()]->state);
        self::assertEquals($this->at('2026-06-14 07:00:00'), $byZone[$second->id()->toRfc4122()]->nextStart);
        self::assertSame(1, $rights->validZoneCount());
        self::assertFalse($rights->isFullyValid(), 'un laissez-passer valide pour une zone seulement n\'est pas « entièrement valide »');
        // Pendant le créneau de la seconde zone, les deux sont valides.
        self::assertTrue($this->evaluate('2026-06-14 08:00:00')->isFullyValid());
    }

    public function testBetweenTwoPeriodsTheStateIsOutsidePeriodWithTheNextStartAndAfterTheLastItIsExpired(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-12'); // 07:00–15:00 UTC
        $this->slot($this->organizerZone, '2026-06-14'); // 07:00–15:00 UTC
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());

        $between = $this->evaluate('2026-06-12 16:00:00');
        self::assertSame([RightState::OutsidePeriod], $this->states($between));
        self::assertEquals($this->at('2026-06-14 07:00:00'), $between->zones()[0]->nextStart);
        self::assertSame([RightState::Valid], $this->states($this->evaluate('2026-06-12 10:00:00')));
        self::assertSame([RightState::Expired], $this->states($this->evaluate('2026-06-15 00:00:00')));
    }

    public function testAZoneSlotStartingAfterTheDecisionGivesNoImmediateAccess(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-14');
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());

        self::assertSame([RightState::NotYetValid], $this->states($this->evaluate('2026-06-12 10:00:00')));
    }

    public function testARevokedAuthorizationHasNoValidZone(): void
    {
        $this->simple();
        $this->sql('UPDATE permit_authorization SET revoked_at = :t', ['t' => '2026-06-12 09:30:00']);

        $rights = $this->evaluate('2026-06-12 10:00:00');

        self::assertSame([RightState::Revoked], $this->states($rights));
        self::assertNotNull($rights->authorizations[0]->revokedAt);
        self::assertFalse($rights->isFullyValid());
    }

    /**
     * @return iterable<string, array{string, string, RightState}>
     */
    public static function inactive(): iterable
    {
        yield 'commune suspendue' => ["UPDATE municipality SET status = 'SUSPENDED'", '', RightState::CommuneInactive];
        yield 'commune inactive' => ["UPDATE municipality SET status = 'INACTIVE'", '', RightState::CommuneInactive];
        yield 'événement clôturé' => ["UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 09:30:00'", '', RightState::EventClosed];
        yield 'événement suspendu' => ["UPDATE event_event SET status = 'SUSPENDED'", '', RightState::EventNotActive];
        yield 'participation suspendue' => ["UPDATE event_participation SET status = 'SUSPENDED'", '', RightState::ParticipationInactive];
        yield 'participation clôturée' => ["UPDATE event_participation SET status = 'CLOSED'", '', RightState::ParticipationInactive];
        yield 'zone suspendue' => ["UPDATE event_zone SET status = 'SUSPENDED'", '', RightState::ZoneSuspended];
    }

    #[DataProvider('inactive')]
    public function testEveryAdministrativeStateOfTheCommuneEventParticipationOrZoneClosesTheRight(string $update, string $unused, RightState $expected): void
    {
        $this->simple();
        $this->sql($update);

        self::assertSame([$expected], $this->states($this->evaluate('2026-06-12 10:00:00')));
    }

    public function testTheCommuneStatusEntersTheEvaluationAndIsNeverCopied(): void
    {
        $this->simple();
        $this->sql('UPDATE municipality SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizer->id()->toBinary()]);
        self::assertSame([RightState::CommuneInactive], $this->states($this->evaluate('2026-06-12 10:00:00')));

        $this->sql('UPDATE municipality SET status = \'ACTIVE\' WHERE id = :id', ['id' => $this->organizer->id()->toBinary()]);
        self::assertSame([RightState::Valid], $this->states($this->evaluate('2026-06-12 10:00:00')), 'une commune réactivée retrouve ses autorisations encore valides (DM-035)');
    }

    public function testCausesFollowTheDocumentedPrecedence(): void
    {
        $this->simple();
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED'");
        $this->sql("UPDATE event_participation SET status = 'SUSPENDED'");
        self::assertSame([RightState::ParticipationInactive], $this->states($this->evaluate('2026-06-12 10:00:00')), 'participation avant zone');
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        self::assertSame([RightState::EventNotActive], $this->states($this->evaluate('2026-06-12 10:00:00')), 'événement avant participation');
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 09:30:00'");
        self::assertSame([RightState::EventClosed], $this->states($this->evaluate('2026-06-12 10:00:00')));
        $this->sql("UPDATE municipality SET status = 'SUSPENDED'");
        self::assertSame([RightState::CommuneInactive], $this->states($this->evaluate('2026-06-12 10:00:00')), 'commune avant événement');
        $this->sql("UPDATE permit_authorization SET revoked_at = '2026-06-12 09:30:00'");
        self::assertSame([RightState::Revoked], $this->states($this->evaluate('2026-06-12 10:00:00')), 'révocation avant tout');
    }

    public function testInAMulticommunalPermitOneCommuneInactiveDoesNotCloseTheOthers(): void
    {
        $id = $this->request([$this->organizerZone, $this->partnerZone]);
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);
        $this->sql('UPDATE municipality SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->partner->id()->toBinary()]);

        $rights = $this->evaluate('2026-06-12 10:00:00');

        self::assertCount(2, $rights->authorizations);
        $byCommune = [];
        foreach ($rights->authorizations as $authorization) {
            $byCommune[$authorization->municipalityId->toRfc4122()] = $authorization->zones[0]->state;
        }
        self::assertSame(RightState::Valid, $byCommune[$this->organizer->id()->toRfc4122()]);
        self::assertSame(RightState::CommuneInactive, $byCommune[$this->partner->id()->toRfc4122()]);
        self::assertFalse($rights->isFullyValid());
        self::assertSame(1, $rights->validZoneCount());
    }

    public function testAZoneAbsentFromThePermitIsNotAuthorizedAndARemovedZoneDisappears(): void
    {
        $this->simple();
        $rights = $this->evaluate('2026-06-12 10:00:00');

        $unknown = $rights->forZone(Uuid::v7());
        self::assertSame([RightState::ZoneNotAuthorized], array_map(static fn (ZoneRight $z): RightState => $z->state, $unknown));
        self::assertSame([RightState::Valid], array_map(static fn (ZoneRight $z): RightState => $z->state, $rights->forZone($this->organizerZone->id())));

        $this->sql('UPDATE permit_authorization_zone SET removed_at = NOW()');
        self::assertSame([], $this->evaluate('2026-06-12 10:00:00')->zones());
        self::assertFalse($this->evaluate('2026-06-12 10:00:00')->isFullyValid(), 'une autorisation sans zone n\'accorde aucun accès');
    }

    public function testTheEvaluationWritesNothingAndHasNoGlobalValidityFlag(): void
    {
        $this->simple();
        $before = [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('audit_log'), $this->rows('request_permit_request_decision')];

        $this->evaluate('2026-06-12 10:00:00');
        $this->evaluate('2026-06-20 10:00:00');

        self::assertSame($before, [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('audit_log'), $this->rows('request_permit_request_decision')]);
        self::assertFalse((new \ReflectionClass(PermitRights::class))->hasMethod('isValid'), 'aucun booléen « valide » global');
        self::assertFalse((new \ReflectionClass(Permit::class))->hasProperty('status'), 'aucun statut de validité persisté');
        self::assertFalse((new \ReflectionClass(\App\Permit\Domain\PermitAuthorization::class))->hasProperty('status'));
    }
}
