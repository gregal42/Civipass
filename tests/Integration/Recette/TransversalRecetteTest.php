<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recette;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * RECETTE TRANSVERSALE (LOT 05.5.6), partie automatisée : enchaînements de plusieurs opérations des LOT 05.5.1 à 05.5.5 sur un MÊME laissez-passer partagé par deux communes, avec les
 * vrais services et MySQL. Ce sont des scénarios de bout en bout (pas des tests unitaires d'une règle) : isolement des communes, historique immuable et ordonné, révisions à la même
 * seconde, zone rattachée contre zone accessible, chaîne de périodes (expiration, reprise, prolongation, raccourcissement), plaques communes, QR remplacé, lecture publique. Une recette
 * MANUELLE (téléphone, lecteur de PDF, client de messagerie) n'est jamais déclarée réussie sur la base de ces tests : voir docs/lot-05-5-6-recette.md.
 * Horloge figée au 12 juin 2026 09:00 UTC ; décision de 72 h.
 */
#[Group('requires-request-schema')]
#[Group('recette')]
final class TransversalRecetteTest extends MunicipalDecisionTestCase
{
    private User $partnerAdmin;
    private EventZone $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partnerAdmin = UserBuilder::aUser()->build();
        $this->save($this->partnerAdmin, MembershipBuilder::aMembership($this->partnerAdmin, $this->partner)->withRoles(Role::MunicipalityAdmin)->build());
        $organizer = $this->em->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($organizer);
        $this->save($this->zoneB->createdBy(), $this->zoneB);
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B'], [$this->partnerZone, 'Zone P']] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function approved(?ApprovalDuration $duration = null): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            'SENTINEL-ADRESSE',
            'camille@exemple.fr',
            '0611223344',
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['GH-482-KT', 'AB-123-CD'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, $duration ?? ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    // ------------------------------------------------------------------ services

    /**
     * @param list<EventZone> $adds
     * @param list<EventZone> $removes
     * @param list<EventZone> $reinstates
     */
    private function zones(string $request, array $adds = [], array $removes = [], array $reinstates = []): void
    {
        $c = self::getContainer();
        $ids = static fn (array $z): array => array_map(static fn (EventZone $x): string => $x->id()->toRfc4122(), $z);
        (new AuthorizationZoneService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(EventZoneRepository::class), $c->get(ProposableZones::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->change($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, new ZoneChangeRequest($ids($adds), $ids($removes), $ids($reinstates), 'NEW_NEED', 'NEED_ENDED', 'NEED_RETURNED', null));
    }

    private function plateId(string $registration): string
    {
        return Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_vehicle WHERE registration = :r', ['r' => $registration]))->toRfc4122();
    }

    /**
     * @param list<string>                      $adds
     * @param list<string>                      $removes      plaques normalisées
     * @param list<string>                      $reinstates   plaques normalisées
     * @param list<array{0: string, 1: string}> $replacements plaque normalisée remplacée, nouvelle plaque
     */
    private function plates(string $request, User $agent, \App\Municipality\Domain\Municipality $commune, array $adds = [], array $removes = [], array $reinstates = [], array $replacements = []): void
    {
        $c = self::getContainer();
        (new PermitVehicleService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->change($this->managed($agent), $commune->id()->toRfc4122(), $request, new VehicleChangeRequest(
                $adds,
                array_map($this->plateId(...), $removes),
                array_map($this->plateId(...), $reinstates),
                array_map(fn (array $r): array => ['from' => $this->plateId($r[0]), 'to' => $r[1]], $replacements),
                'NEW_VEHICLE',
                'VEHICLE_NO_LONGER_USED',
                'VEHICLE_CHANGED',
                'VEHICLE_BACK_IN_USE',
                null,
                true,
            ));
    }

    private function periods(): AuthorizationPeriodService
    {
        $c = self::getContainer();

        return new AuthorizationPeriodService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRepository::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
    }

    private function replaceQr(string $request, ?string $reference = null): void
    {
        $c = self::getContainer();
        (new PermitTokenReplacementService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->replace($this->managed($this->adminOnly), $this->organizer->id()->toRfc4122(), $request, new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'SUSPECTED_MISUSE', null, true, $reference));
    }

    private function revoke(string $request, User $agent, \App\Municipality\Domain\Municipality $commune): void
    {
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($agent), $commune->id()->toRfc4122(), $request, 'DUPLICATE', null, true);
    }

    // ------------------------------------------------------------------ lectures

    /** @return list<string> types de révision dans l'ordre d'application (du plus ancien au plus récent) */
    private function history(): array
    {
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();

        return array_reverse(array_map(static fn ($r): string => $r->type()->value, self::getContainer()->get(PermitRevisionRepository::class)->forPermit($permit->id())));
    }

    /** @return array<string, array<string, RightState>> états des zones par commune puis libellé, à l'instant donné */
    private function states(string $utc): array
    {
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();
        $out = [];
        foreach ($this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at($utc), true)->authorizations as $a) {
            foreach ($a->zones as $z) {
                $out[$a->municipalityName][$z->label] = $z->state;
            }
        }

        return $out;
    }

    private function activeToken(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token WHERE active_permit_id IS NOT NULL');
    }

    // ------------------------------------------------------------------ R-TR-01 : enchaînement multi-lots, deux communes

    public function testAFullChainOfOperationsKeepsTheCommunesIndependentTheHistoryOrderedAndImmutable(): void
    {
        $requestId = $this->approved();
        $oldToken = $this->activeToken();
        $organizerName = $this->organizer->name();
        $partnerName = $this->partner->name();

        $this->zones($requestId, adds: [$this->zoneB]);                                                        // 05.5.3 : organisatrice
        $this->clock->sleep(60);
        $this->periods()->extend($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', null); // 05.5.2 : partenaire
        $this->clock->sleep(60);
        $this->plates($requestId, $this->instructor, $this->organizer, replacements: [['AB123CD', 'EF-456-GH']]); // 05.5.4 : remplacement, plaques communes
        $this->clock->sleep(60);
        $this->replaceQr($requestId);                                                                          // 05.5.5 : QR commun
        $this->clock->sleep(60);
        $snapshot = $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, type, occurred_at, reason_code, reason_text, before_state, after_state, LOWER(HEX(batch_id)) AS batch FROM permit_revision ORDER BY occurred_at, id');
        $organizerBefore = $this->states('2026-06-12 10:00:00')[$organizerName] ?? [];
        $this->revoke($requestId, $this->partnerInstructor, $this->partner);                                   // 05.5.1 : révocation de la partenaire

        // l'ordre des révisions est celui des opérations ; un remplacement donne deux révisions consécutives
        self::assertSame(['ZONE_ADDED', 'PERIOD_EXTENDED', 'VEHICLE_REPLACED_OUT', 'VEHICLE_REPLACED_IN', 'TOKEN_REPLACED', 'AUTHORIZATION_REVOKED'], $this->history());
        // historique IMMUABLE : les révisions déjà écrites sont identiques après les opérations suivantes
        $after = $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, type, occurred_at, reason_code, reason_text, before_state, after_state, LOWER(HEX(batch_id)) AS batch FROM permit_revision ORDER BY occurred_at, id');
        self::assertSame($snapshot, \array_slice($after, 0, \count($snapshot)), 'aucune révision passée n\'est modifiée');
        // la révocation de la partenaire n'a AUCUN impact sur l'organisatrice
        $states = $this->states('2026-06-12 10:00:00');
        self::assertSame($organizerBefore, $states[$organizerName], 'zones et états de l\'organisatrice inchangés');
        self::assertSame(RightState::Valid, $states[$organizerName]['Zone A']);
        self::assertSame(RightState::Valid, $states[$organizerName]['Zone B']);
        self::assertSame(RightState::Revoked, $states[$partnerName]['Zone P']);
        // plaques communes : le remplacement est visible, le jeton ancien est mort, le nouveau vit
        $v = $this->service(PublicVerificationReader::class)->find($this->activeToken(), $this->clock->now());
        self::assertSame(PublicVerificationOutcome::Found, $v->outcome);
        self::assertSame(['GH-482-KT', 'EF-456-GH'], $v->verification?->plates);
        $this->em->clear();
        self::assertSame(PublicVerificationOutcome::Replaced, $this->service(PublicVerificationReader::class)->find($oldToken, $this->clock->now())->outcome);
        // vues : la commune qui a agi voit le détail, l'autre une ligne neutre, la commune révoquée ne voit plus les plaques
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));
        $own = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $revoked = $reader->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId);
        self::assertTrue($own->history[1]->own || $own->history[0]->own);
        self::assertSame([], $revoked->vehicles?->rows, 'la commune révoquée ne voit plus les plaques communes');
        foreach ($revoked->history as $line) {
            if ($line->municipalityName === $organizerName) {
                self::assertFalse($line->own);
                self::assertNull($line->reasonLabel, 'ligne neutre : aucun motif');
                self::assertNull($line->actorName);
                self::assertNull($line->vehicle);
                self::assertNull($line->zone);
                self::assertNull($line->token);
            }
        }
    }

    // ------------------------------------------------------------------ R-TR-02 : plusieurs révisions à la même seconde

    public function testSixRevisionsInTheSameSecondKeepTheApplicationOrderAndOneBatchPerOperation(): void
    {
        $requestId = $this->approved();

        $this->zones($requestId, adds: [$this->zoneB]);
        $this->zones($requestId, removes: [$this->zoneB]);
        $this->zones($requestId, reinstates: [$this->zoneB]);
        $this->plates($requestId, $this->instructor, $this->organizer, adds: ['EF-456-GH']);
        $this->plates($requestId, $this->instructor, $this->organizer, removes: ['EF456GH']);
        $this->plates($requestId, $this->instructor, $this->organizer, reinstates: ['EF456GH']);

        self::assertSame(['ZONE_ADDED', 'ZONE_REMOVED', 'ZONE_REINSTATED', 'VEHICLE_ADDED', 'VEHICLE_REMOVED', 'VEHICLE_REINSTATED'], $this->history(), 'ordre d\'application conservé (occurred_at puis identifiant UUID v7)');
        $seconds = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT occurred_at FROM permit_revision');
        self::assertCount(1, $seconds, 'les six révisions portent le MÊME instant : l\'ordre ne repose pas sur l\'horodatage');
        self::assertSame(6, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT batch_id) FROM permit_revision'), 'un lot par opération');
        // reconstitution d'une succession ajout / retrait / réintégration d'une même zone
        $removedAt = $this->em->getConnection()->fetchOne('SELECT removed_at FROM permit_authorization_zone WHERE zone_label = :l', ['l' => 'Zone B']);
        self::assertNull($removedAt, 'la zone B est rattachée à la fin de la succession');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_authorization_zone WHERE zone_label = 'Zone B'"), 'une seule ligne réutilisée');
    }

    // ------------------------------------------------------------------ R-TR-03 : zone rattachée contre zone accessible

    public function testAnAttachedZoneIsNotNecessarilyAnAccessibleOne(): void
    {
        $requestId = $this->approved();
        $this->zones($requestId, adds: [$this->zoneB]);
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->zoneB->id()->toBinary()]);

        $states = $this->states('2026-06-12 10:00:00')[$this->organizer->name()];
        self::assertSame(RightState::Valid, $states['Zone A']);
        self::assertSame(RightState::ZoneSuspended, $states['Zone B'], 'rattachée mais suspendue : aucun droit utilisable');
        $v = $this->service(PublicVerificationReader::class)->find($this->activeToken(), $this->at('2026-06-12 10:00:00'))->verification ?? self::fail();
        self::assertSame(3, $v->zoneCount, 'zones rattachées : A, B et P');
        self::assertSame(2, $v->activeZoneCount, 'zones effectivement accessibles : A et P (B suspendue)');
        $c = self::getContainer();
        $overview = (new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class)))->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $rows = array_column(array_map(static fn ($r): array => [$r->label, $r->state], $overview->zoneRows), 1, 0);
        self::assertSame(RightState::ZoneSuspended, $rows['Zone B']);
        // le retrait d'une zone suspendue reste possible et ne laisse jamais l'autorisation sans zone
        $this->zones($requestId, removes: [$this->zoneB]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone az JOIN permit_authorization a ON a.id = az.authorization_id WHERE a.municipality_id = :m AND az.removed_at IS NULL', ['m' => $this->organizer->id()->toBinary()]));
    }

    // ------------------------------------------------------------------ R-TR-04 : chaîne de périodes

    public function testExpiryResumptionExtensionAndShorteningFormACoherentChain(): void
    {
        $requestId = $this->approved(ApprovalDuration::fixed(DurationMode::H24)); // fin le 13 juin 09:00 UTC
        $zone = fn (string $utc): RightState => $this->states($utc)[$this->organizer->name()]['Zone A'];
        self::assertSame(RightState::Valid, $zone('2026-06-12 12:00:00'));

        $this->clock->sleep(25 * 3600); // 13 juin 10:00 : expirée
        self::assertSame(RightState::Expired, $zone('2026-06-13 10:00:00'));
        $this->periods()->extend($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', null); // reprise après expiration
        self::assertSame(RightState::Valid, $zone('2026-06-13 12:00:00'), 'reprise : droits de nouveau valables');
        $this->clock->sleep(3600);
        $this->periods()->extend($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', null); // prolongation d'une période en cours
        $this->clock->sleep(3600);
        $this->periods()->shorten($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, PeriodEnd::custom('2026-06-13', '16:00'), 'NEED_ENDED', null, true); // raccourcissement

        self::assertSame(['PERIOD_RESUMED', 'PERIOD_EXTENDED', 'PERIOD_SHORTENED'], $this->history());
        self::assertSame(RightState::Valid, $zone('2026-06-13 13:00:00'));
        self::assertSame(RightState::Expired, $zone('2026-06-13 15:00:00'), 'après le raccourcissement (16:00 heure de Paris = 14:00 UTC)');
        // l'autre commune n'est pas touchée par les périodes de l'organisatrice
        self::assertSame(RightState::Expired, $this->states('2026-06-16 10:00:00')[$this->partner->name()]['Zone P'], 'la partenaire garde sa propre fenêtre de 72 h');
        self::assertSame(RightState::Valid, $this->states('2026-06-13 08:00:00')[$this->partner->name()]['Zone P']);
    }
}
