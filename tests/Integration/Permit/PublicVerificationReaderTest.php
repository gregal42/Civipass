<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Read\PublicZone;
use App\Permit\Application\Result\PublicVerificationLookup;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
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

/**
 * Lecture publique d'un laissez-passer par son jeton (LOT 05.4.1, DM-115) : jetons actif, retiré, inconnu, mal formé ; droits par commune et
 * par zone ; zones retirées ; périodes prévues ; compteur N/M ; confidentialité. Horloge de décision : 12 juin 2026 09:00 UTC ; événement du
 * 10 au 20 juin 2026 (heure de Paris).
 */
#[Group('requires-request-schema')]
final class PublicVerificationReaderTest extends MunicipalDecisionTestCase
{
    private const SENTINELS = ['SENTINEL-ADRESSE', 'sentinel.email@exemple.test', '0611223344', 'SENTINEL-MOTIF-DETAIL'];

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
    private function request(array $zones, bool $sentinels = false): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            $sentinels ? self::SENTINELS[0] : null,
            $sentinels ? self::SENTINELS[1] : 'camille@exemple.fr',
            $sentinels ? self::SENTINELS[2] : null,
            'private_person',
            $sentinels ? 'other' : 'home',
            $sentinels ? self::SENTINELS[3] : null,
            TravelMode::Vehicle,
            ['GH-482-KT', 'ab123cd'],
            array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones),
            true,
        ), $this->key())->requestId;
    }

    private function approve(User $agent, Municipality $commune, string $id, ?ApprovalDuration $duration = null): void
    {
        $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, $duration ?? ApprovalDuration::fixed(DurationMode::H72));
    }

    private function simple(?ApprovalDuration $duration = null, bool $sentinels = false): void
    {
        $this->approve($this->instructor, $this->organizer, $this->request([$this->organizerZone], $sentinels), $duration);
    }

    private function token(): string
    {
        $this->em->clear();

        return ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
    }

    private function find(string $at = '2026-06-12 10:00:00', ?string $token = null): PublicVerificationLookup
    {
        $token ??= $this->token();
        $this->em->clear();

        return $this->service(PublicVerificationReader::class)->find($token, $this->at($at));
    }

    private function verification(string $at = '2026-06-12 10:00:00'): PublicVerification
    {
        $lookup = $this->find($at);
        self::assertSame(PublicVerificationOutcome::Found, $lookup->outcome);

        return $lookup->verification ?? self::fail('Vérification absente.');
    }

    /** @return list<RightState> */
    private function states(PublicVerification $v): array
    {
        $states = [];
        foreach ($v->authorizations as $authorization) {
            foreach ($authorization->zones as $zone) {
                $states[] = $zone->state;
            }
        }

        return $states;
    }

    private function slot(EventZone $zone, string $date, string $from = '09:00', string $to = '17:00'): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $zone->id()->toBinary()]);
        $this->save(EventZoneValidity::fromSlot($this->em->find(EventZone::class, $zone->id()) ?? self::fail('Zone absente.'), ValiditySlot::punctual(T::date($date), T::time($from), T::time($to)), F::now()));
    }

    // ------------------------------------------------------------------ jetons

    public function testAnActiveTokenGivesThePermit(): void
    {
        $this->simple();

        $lookup = $this->find();

        self::assertSame(PublicVerificationOutcome::Found, $lookup->outcome);
        $v = $lookup->verification ?? self::fail();
        self::assertSame('LP-2026-0001', $v->reference);
        self::assertSame('Martin', $v->lastName);
        self::assertSame('Camille', $v->firstName);
        self::assertSame($this->event->name(), $v->eventName);
        self::assertSame('Europe/Paris', $v->timezone);
        self::assertEquals($this->at('2026-06-12 10:00:00'), $v->checkedAt);
        self::assertSame(['GH-482-KT', 'AB-123-CD'], $v->plates);
    }

    public function testTheTokenIsAcceptedInUpperCase(): void
    {
        $this->simple();

        self::assertSame(PublicVerificationOutcome::Found, $this->find('2026-06-12 10:00:00', strtoupper($this->token()))->outcome);
    }

    public function testARetiredTokenGivesReplacedAndNothingElse(): void
    {
        $this->simple();
        $token = $this->token();
        $this->sql('UPDATE permit_verification_token SET retired_at = :t, active_permit_id = NULL', ['t' => '2026-06-12 09:30:00']);

        $lookup = $this->find('2026-06-12 10:00:00', $token);

        self::assertSame(PublicVerificationOutcome::Replaced, $lookup->outcome);
        self::assertNull($lookup->verification, 'aucune donnée pour un QR remplacé');
    }

    /**
     * @return iterable<string, array{string, PublicVerificationOutcome}>
     */
    public static function unreadableTokens(): iterable
    {
        yield 'bien formé mais inconnu' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', PublicVerificationOutcome::Unknown];
        yield 'bien formé (base32) inconnu' => ['abcdefghijklmnopqrstuvwxyz234567', PublicVerificationOutcome::Unknown];
        yield 'majuscules bien formées inconnues' => ['ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ', PublicVerificationOutcome::Unknown];
        yield 'vide' => ['', PublicVerificationOutcome::Malformed];
        yield 'trop court' => ['abc', PublicVerificationOutcome::Malformed];
        yield 'trop long' => [str_repeat('a', 5000), PublicVerificationOutcome::Malformed];
        yield 'caractères spéciaux' => ["../../etc/passwd\0' OR 1=1 --", PublicVerificationOutcome::Malformed];
        yield 'URL' => ['https://civipass.example/v/abcdefghijklmnopqrstuvwxyz234567', PublicVerificationOutcome::Malformed];
    }

    #[DataProvider('unreadableTokens')]
    public function testAMalformedOrUnknownTokenGivesNoDataAndIsDistinguishedOnlyByTheOutcome(string $token, PublicVerificationOutcome $expected): void
    {
        $this->simple();

        $lookup = $this->find('2026-06-12 10:00:00', $token);

        self::assertSame($expected, $lookup->outcome);
        self::assertNull($lookup->verification);
    }

    public function testAMalformedTokenNeverReachesTheDatabase(): void
    {
        $this->simple();
        $this->em->clear();
        $before = $this->queryCount();

        $this->service(PublicVerificationReader::class)->find('pas-un-jeton', $this->at('2026-06-12 10:00:00'));

        self::assertSame(0, $this->queryCount() - $before - 1, 'un jeton mal formé est rejeté sans requête (la lecture du compteur compte pour 1)');
    }

    // ------------------------------------------------------------------ statuts globaux et zones

    public function testOneActiveZoneGivesActiveRightsAndTheCounter(): void
    {
        $this->simple();

        $v = $this->verification();

        self::assertSame(PublicVerdict::ActiveRights, $v->verdict);
        self::assertSame([1, 1], [$v->activeZoneCount, $v->zoneCount]);
        self::assertSame([RightState::Valid], $this->states($v));
        self::assertSame($this->organizerZone->label(), $v->authorizations[0]->zones[0]->label);
    }

    public function testSeveralZonesWithDifferentStatesKeepActiveRightsAndTheirOwnDetail(): void
    {
        $id = $this->request([$this->organizerZone, $this->partnerZone]);
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 09:30:00', 'm' => $this->partner->id()->toBinary()]);

        $v = $this->verification();

        self::assertSame(PublicVerdict::ActiveRights, $v->verdict, 'une zone valide suffit, malgré la révocation de l\'autre commune');
        self::assertSame([1, 2], [$v->activeZoneCount, $v->zoneCount]);
        self::assertCount(2, $v->authorizations);
        $byCommune = [];
        foreach ($v->authorizations as $authorization) {
            $byCommune[$authorization->municipalityName] = $authorization;
        }
        self::assertSame(RightState::Valid, $byCommune[$this->organizer->name()]->zones[0]->state);
        self::assertSame(RightState::Revoked, $byCommune[$this->partner->name()]->zones[0]->state);
        self::assertTrue($byCommune[$this->partner->name()]->isRevoked());
        self::assertFalse($byCommune[$this->organizer->name()]->isRevoked());
    }

    /**
     * @return iterable<string, array{string, string, RightState, PublicVerdict}>
     */
    public static function noActiveZone(): iterable
    {
        yield 'avant la fenêtre' => ['2026-06-12 08:59:59', '', RightState::NotYetValid, PublicVerdict::Upcoming];
        yield 'après la fenêtre' => ['2026-06-15 09:00:00', '', RightState::Expired, PublicVerdict::Expired];
        yield 'révoquée' => ['2026-06-12 10:00:00', "UPDATE permit_authorization SET revoked_at = '2026-06-12 09:30:00'", RightState::Revoked, PublicVerdict::Revoked];
        // Événement clôturé : plus aucune donnée publique (décision du 10 novembre 2026), voir `testAClosedEventYieldsOnlyANeutralOutcome…`.
        yield 'commune suspendue' => ['2026-06-12 10:00:00', "UPDATE municipality SET status = 'SUSPENDED'", RightState::CommuneInactive, PublicVerdict::Suspended];
        yield 'commune inactive' => ['2026-06-12 10:00:00', "UPDATE municipality SET status = 'INACTIVE'", RightState::CommuneInactive, PublicVerdict::Suspended];
        yield 'zone suspendue' => ['2026-06-12 10:00:00', "UPDATE event_zone SET status = 'SUSPENDED'", RightState::ZoneSuspended, PublicVerdict::Suspended];
        yield 'participation suspendue' => ['2026-06-12 10:00:00', "UPDATE event_participation SET status = 'SUSPENDED'", RightState::ParticipationInactive, PublicVerdict::Suspended];
        yield 'événement suspendu' => ['2026-06-12 10:00:00', "UPDATE event_event SET status = 'SUSPENDED'", RightState::EventNotActive, PublicVerdict::Suspended];
    }

    #[DataProvider('noActiveZone')]
    public function testWhenNoZoneIsActiveTheStatusSaysWhyAndTheZoneStaysListed(string $at, string $update, RightState $state, PublicVerdict $verdict): void
    {
        $this->simple();
        if ('' !== $update) {
            $this->sql($update);
        }

        $v = $this->verification($at);

        self::assertSame($verdict, $v->verdict);
        self::assertSame([$state], $this->states($v));
        self::assertSame([0, 1], [$v->activeZoneCount, $v->zoneCount]);
    }

    public function testBetweenTwoPeriodsTheStatusIsUpcoming(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-12');
        $this->slot($this->organizerZone, '2026-06-14');
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());

        $v = $this->verification('2026-06-12 16:00:00');

        self::assertSame(PublicVerdict::Upcoming, $v->verdict);
        self::assertSame([RightState::OutsidePeriod], $this->states($v));
    }

    // ------------------------------------------------------------------ zones retirées

    public function testARemovedZoneStaysVisibleAsRemovedWithItsDateAndIsOutsideTheCounter(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->approve($this->instructor, $this->organizer, $this->request([$this->organizerZone, $second]));
        $this->sql('UPDATE permit_authorization_zone SET removed_at = :t WHERE zone_id = :z', ['t' => '2026-06-12 09:45:00', 'z' => $second->id()->toBinary()]);

        $v = $this->verification();

        self::assertSame(PublicVerdict::ActiveRights, $v->verdict);
        self::assertSame([1, 1], [$v->activeZoneCount, $v->zoneCount], 'la zone retirée n\'entre pas dans N/M');
        $zones = $v->authorizations[0]->zones;
        self::assertCount(2, $zones);
        $removed = array_values(array_filter($zones, static fn (PublicZone $z): bool => $z->isRemoved()));
        self::assertCount(1, $removed);
        self::assertSame($second->label(), $removed[0]->label);
        self::assertEquals($this->at('2026-06-12 09:45:00'), $removed[0]->removedAt);
        self::assertSame([], $removed[0]->periods, 'aucune période : aucun droit n\'est suggéré');
        self::assertFalse($v->authorizations[0]->isRevoked(), 'un retrait de zone n\'est pas une révocation');
        self::assertSame(RightState::Removed, $zones[\count($zones) - 1]->state, 'zones retirées après les zones actuelles');
    }

    public function testARemovedZoneStaysRemovedWhenTheAuthorizationIsRevokedLater(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->approve($this->instructor, $this->organizer, $this->request([$this->organizerZone, $second]));
        $this->sql('UPDATE permit_authorization_zone SET removed_at = :t WHERE zone_id = :z', ['t' => '2026-06-12 09:45:00', 'z' => $second->id()->toBinary()]);
        $this->sql('UPDATE permit_authorization SET revoked_at = :t', ['t' => '2026-06-12 09:50:00']);

        $v = $this->verification();

        self::assertSame([RightState::Revoked, RightState::Removed], $this->states($v), 'zone retirée conservée « Retirée », l\'autre « Révoquée »');
        self::assertSame(PublicVerdict::Revoked, $v->verdict, 'la zone retirée ne compte pas dans le statut global');
        self::assertSame([0, 1], [$v->activeZoneCount, $v->zoneCount]);
    }

    public function testOnlyRemovedZonesGiveNoActiveRights(): void
    {
        $this->simple();
        $this->sql('UPDATE permit_authorization_zone SET removed_at = :t', ['t' => '2026-06-12 09:45:00']);

        $v = $this->verification();

        self::assertSame(PublicVerdict::NoActiveRights, $v->verdict);
        self::assertSame([RightState::Removed], $this->states($v));
        self::assertSame([0, 0], [$v->activeZoneCount, $v->zoneCount]);
    }

    // ------------------------------------------------------------------ périodes

    public function testTheZonePeriodsAreTheEffectiveOnesNotTheGeneralWindow(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-12'); // 07:00–15:00 UTC
        $this->slot($this->organizerZone, '2026-06-14'); // 07:00–15:00 UTC
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());

        $v = $this->verification('2026-06-12 10:00:00');

        $zone = $v->authorizations[0]->zones[0];
        self::assertSame(RightState::Valid, $zone->state);
        self::assertCount(2, $zone->periods, 'les deux créneaux, pas la fenêtre générale');
        self::assertEquals($this->at('2026-06-12 09:00:00'), $zone->periods[0]->from, 'borné par la décision (09:00)');
        self::assertEquals($this->at('2026-06-12 15:00:00'), $zone->periods[0]->until);
        self::assertEquals($this->at('2026-06-14 07:00:00'), $zone->periods[1]->from);
        self::assertEquals($this->at('2026-06-14 15:00:00'), $zone->periods[1]->until);
        self::assertNotEquals($v->authorizations[0]->validFrom, $zone->periods[1]->from);
    }

    public function testHistoricalPeriodsOfAnExpiredZoneAreKept(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-12');
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::fixed(DurationMode::H24));

        $v = $this->verification('2026-06-16 10:00:00');

        $zone = $v->authorizations[0]->zones[0];
        self::assertSame(RightState::Expired, $zone->state);
        self::assertCount(1, $zone->periods);
        self::assertEquals($this->at('2026-06-12 15:00:00'), $zone->periods[0]->until);
    }

    public function testARevokedAuthorizationKeepsItsScheduledPeriodsWithAnExplicitRevokedState(): void
    {
        $id = $this->request([$this->organizerZone]);
        $this->slot($this->organizerZone, '2026-06-13');
        $this->approve($this->instructor, $this->organizer, $id, ApprovalDuration::untilClosure());
        $this->sql('UPDATE permit_authorization SET revoked_at = :t', ['t' => '2026-06-12 09:30:00']);

        $zone = $this->verification()->authorizations[0]->zones[0];

        self::assertSame(RightState::Revoked, $zone->state);
        self::assertCount(1, $zone->periods);
        self::assertEquals($this->at('2026-06-13 07:00:00'), $zone->periods[0]->from);
    }

    public function testASuspendedZoneAndAnInactiveCommuneStillShowTheirScheduledPeriods(): void
    {
        $this->simple();
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED'");

        self::assertNotSame([], $this->verification()->authorizations[0]->zones[0]->periods);
        $this->sql("UPDATE event_zone SET status = 'ACTIVE'");
        $this->sql("UPDATE municipality SET status = 'SUSPENDED'");

        self::assertNotSame([], $this->verification()->authorizations[0]->zones[0]->periods);
    }

    public function testAnEarlyClosureCutsTheScheduledPeriodsInTheInternalRights(): void
    {
        $this->simple(ApprovalDuration::untilClosure());
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 12:00:00'");
        $this->em->clear();

        // Le calcul interne des droits (page du bénéficiaire, PDF) reste exact ; seule la lecture PUBLIQUE est neutre après clôture.
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.');
        $zone = $this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at('2026-06-12 10:00:00'), true)->authorizations[0]->zones[0];

        self::assertSame(RightState::EventClosed, $zone->state);
        self::assertCount(1, $zone->periods);
        self::assertEquals($this->at('2026-06-12 12:00:00'), $zone->periods[0]->end);
    }

    public function testAClosedEventYieldsOnlyANeutralOutcomeWithNoPersonalDataWhateverTheInstant(): void
    {
        $this->simple(ApprovalDuration::untilClosure(), sentinels: true);
        $token = $this->token();
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 09:30:00'");

        foreach (['2026-06-12 09:00:00', '2026-06-12 10:00:00', '2026-06-20 10:00:00'] as $at) {
            $lookup = $this->find($at, $token);

            self::assertSame(PublicVerificationOutcome::EventClosed, $lookup->outcome);
            self::assertNull($lookup->verification, 'aucune donnée du laissez-passer');
            $dump = serialize($lookup);
            foreach (['Martin', 'Camille', 'GH-482-KT', 'GH482KT', 'LP-', $this->organizerParticipation->municipality()->name(), $this->event->name(), $this->organizerZone->label(), $token] as $leak) {
                self::assertStringNotContainsString($leak, $dump, 'après clôture : '.$leak);
            }
        }
    }

    public function testAReplacedTokenStaysGoneAndAnUnknownOneStaysNeutralEvenOnAClosedEvent(): void
    {
        $this->simple(ApprovalDuration::untilClosure());
        $token = $this->token();
        $this->sql("UPDATE permit_verification_token SET active_permit_id = NULL, retired_at = '2026-06-12 09:10:00'");
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 09:30:00'");

        self::assertSame(PublicVerificationOutcome::Replaced, $this->find('2026-06-12 10:00:00', $token)->outcome, 'ancien QR : toujours « remplacé » (410), même après clôture');
        self::assertSame(PublicVerificationOutcome::Unknown, $this->find('2026-06-12 10:00:00', str_repeat('a', 32))->outcome);
        self::assertSame(PublicVerificationOutcome::Malformed, $this->find('2026-06-12 10:00:00', 'pas-un-jeton')->outcome);
    }

    public function testASuspendedEventStillShowsASuspensionStatusAndNothingUsable(): void
    {
        $this->simple(ApprovalDuration::untilClosure());
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");

        $v = $this->verification();

        self::assertSame(PublicVerdict::Suspended, $v->verdict);
        self::assertSame(0, $v->activeZoneCount, 'aucun droit présenté comme utilisable');
        self::assertSame([RightState::EventNotActive], $this->states($v));
    }

    // ------------------------------------------------------------------ confidentialité

    public function testThePublicModelCarriesNoSensitiveDataNorAnyIdentifierNorEntity(): void
    {
        $this->simple(null, true);
        $token = $this->token();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();
        $forbidden = array_merge(self::SENTINELS, [
            $token,
            $permit->id()->toRfc4122(),
            $permit->request()->id()->toRfc4122(),
            $this->organizerZone->id()->toRfc4122(),
            $this->organizer->id()->toRfc4122(),
            $this->event->id()->toRfc4122(),
            $permit->authorizations()[0]->id()->toRfc4122(),
            'private_person',
            'Vehicle',
            'vehicle',
        ]);
        $v = $this->verification();

        $dump = var_export($v, true).json_encode($v, \JSON_THROW_ON_ERROR);

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $dump, 'donnée interdite dans le modèle public');
        }
        self::assertStringNotContainsString('Doctrine', $dump);
        self::assertStringNotContainsString('Uuid', $dump);
        $this->assertNoEntityOrUuid($v);
    }

    public function testThePublicDtoClassesDeclareOnlyAllowedFields(): void
    {
        $fields = [];
        foreach ([PublicVerification::class, \App\Permit\Application\Read\PublicAuthorization::class, PublicZone::class, \App\Permit\Application\Read\PublicPeriod::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                $fields[$class][] = $property->getName();
            }
        }

        self::assertSame(['reference', 'eventName', 'timezone', 'lastName', 'firstName', 'plates', 'verdict', 'activeZoneCount', 'zoneCount', 'authorizations', 'checkedAt'], $fields[PublicVerification::class]);
        self::assertSame(['municipalityName', 'validFrom', 'validUntil', 'revokedAt', 'zones'], $fields[\App\Permit\Application\Read\PublicAuthorization::class]);
        self::assertSame(['label', 'state', 'periods', 'removedAt'], $fields[PublicZone::class]);
        self::assertSame(['from', 'until'], $fields[\App\Permit\Application\Read\PublicPeriod::class]);
    }

    private function assertNoEntityOrUuid(mixed $value): void
    {
        if (\is_object($value)) {
            self::assertNotInstanceOf(\Symfony\Component\Uid\Uuid::class, $value);
            self::assertFalse(str_starts_with($value::class, 'App\\Event\\Domain\\') && !$value instanceof \UnitEnum);
            self::assertFalse(str_contains($value::class, 'Proxies'));
            if ($value instanceof \DateTimeInterface || $value instanceof \UnitEnum) {
                return;
            }
            foreach ((new \ReflectionObject($value))->getProperties() as $property) {
                $this->assertNoEntityOrUuid($property->getValue($value));
            }
        } elseif (\is_array($value)) {
            foreach ($value as $item) {
                $this->assertNoEntityOrUuid($item);
            }
        }
    }

    // ------------------------------------------------------------------ non-régression et lecture seule

    public function testTheOrdinaryEvaluationIsUnchangedByTheVerificationMode(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->approve($this->instructor, $this->organizer, $this->request([$this->organizerZone, $second]));
        $this->sql('UPDATE permit_authorization_zone SET removed_at = :t WHERE zone_id = :z', ['t' => '2026-06-12 09:45:00', 'z' => $second->id()->toBinary()]);
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();
        $evaluator = $this->service(PermitRightsEvaluator::class);

        $ordinary = $evaluator->evaluate($permit, $this->at('2026-06-12 10:00:00'));
        $verification = $evaluator->evaluate($permit, $this->at('2026-06-12 10:00:00'), true);

        self::assertCount(1, $ordinary->zones(), 'une zone retirée disparaît de l\'évaluation ordinaire');
        self::assertSame([], $ordinary->zones()[0]->periods, 'aucune période hors vérification');
        self::assertCount(2, $verification->zones());
        self::assertSame($ordinary->zones()[0]->state, $verification->zones()[0]->state);
        self::assertEquals($ordinary->zones()[0]->effectiveUntil, $verification->zones()[0]->effectiveUntil);
    }

    public function testTheReadWritesNothing(): void
    {
        $this->simple();
        $before = [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token'), $this->rows('audit_log')];

        $this->find();
        $this->find('2026-06-20 10:00:00');

        self::assertSame($before, [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token'), $this->rows('audit_log')]);
    }

    public function testTheNumberOfQueriesIsBoundedForAMulticommunalPermit(): void
    {
        $id = $this->request([$this->organizerZone, $this->partnerZone]);
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);
        $token = $this->token();
        $this->em->clear();
        $before = $this->queryCount();

        $this->service(PublicVerificationReader::class)->find($token, $this->at('2026-06-12 10:00:00'));

        self::assertLessThanOrEqual(12, $this->queryCount() - $before - 1, 'budget de requêtes (2 communes, 2 zones ; mesuré : 7)');
    }

    /** Requêtes envoyées par cette connexion jusqu'ici (compteur de session MySQL ; la lecture du compteur compte pour 1). */
    private function queryCount(): int
    {
        $row = $this->em->getConnection()->fetchNumeric("SHOW SESSION STATUS LIKE 'Questions'");

        return (int) ($row[1] ?? 0);
    }
}
