<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ajout, retrait, réintégration et correction de libellé des zones d'une autorisation (LOT 05.5.3) avec les VRAIS services et MySQL : opérations groupées atomiques
 * validées sur l'état final, une révision par zone et un batch commun, une seule intention de notification par opération, zone suspendue rattachée sans droit
 * utilisable, suspension de l'événement, identifiants de zones forgés, participation non active, révocation irréversible, QR et demande citoyenne inchangés,
 * indépendance des communes, aucune fuite intercommunale. Horloge figée au 12 juin 2026 09:00 UTC ; décision de 72 h.
 */
#[Group('requires-request-schema')]
final class AuthorizationZoneServiceTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private EventZone $zoneB;
    private EventZone $zoneC;

    protected function setUp(): void
    {
        parent::setUp();
        $participation = $this->em->find(\App\Event\Domain\EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($participation);
        $this->zoneC = F::readyZone($participation);
        $this->save($this->zoneB->createdBy(), $this->zoneB, $this->zoneC->createdBy(), $this->zoneC);
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B'], [$this->zoneC, 'Zone C'], [$this->partnerZone, 'Zone partenaire']] as [$zone, $label]) {
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

    private function zones(?AuditTrail $audit = null): AuthorizationZoneService
    {
        $c = self::getContainer();

        return new AuthorizationZoneService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $c->get(PermitRevisionGuard::class),
            $c->get(PermitRevisionRepository::class),
            $c->get(EventZoneRepository::class),
            $c->get(ProposableZones::class),
            $this->clock,
            $audit ?? $c->get(AuditTrail::class),
            $c->get(NotificationIntentRecorder::class),
        );
    }

    private function approvedByBoth(): string
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
            ['GH-482-KT'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    /**
     * @param list<EventZone> $adds
     * @param list<EventZone> $removes
     * @param list<EventZone> $reinstates
     */
    private function change(string $requestId, array $adds = [], array $removes = [], array $reinstates = [], ?string $addReason = null, ?string $removeReason = null, ?string $reinstateReason = null, ?string $comment = null, ?User $agent = null, ?Municipality $commune = null, ?AuthorizationZoneService $service = null): \App\Permit\Application\Result\ZoneChangeResult
    {
        $ids = static fn (array $zones): array => array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones);

        return ($service ?? $this->zones())->change(
            $this->managed($agent ?? $this->instructor),
            ($commune ?? $this->organizer)->id()->toRfc4122(),
            $requestId,
            new ZoneChangeRequest($ids($adds), $ids($removes), $ids($reinstates), $addReason, $removeReason, $reinstateReason, $comment),
        );
    }

    private function token(): string
    {
        $this->em->clear();

        return ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
    }

    /** @return array<string, array{label: string, removed: bool}> lignes de l'autorisation de la commune, par libellé de zone du référentiel (id en clé) */
    private function rowsOf(Municipality $commune): array
    {
        $rows = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(az.zone_id)) AS zone, az.zone_label AS label, az.removed_at FROM permit_authorization_zone az JOIN permit_authorization a ON a.id = az.authorization_id WHERE a.municipality_id = :m ORDER BY az.zone_label', ['m' => $commune->id()->toBinary()]) as $r) {
            $rows[$r['zone']] = ['label' => $r['label'], 'removed' => null !== $r['removed_at']];
        }

        return $rows;
    }

    private function key32(EventZone $zone): string
    {
        return str_replace('-', '', $zone->id()->toRfc4122());
    }

    /** @return array{revisions: int, audits: int, outbox: int, rows: int} */
    private function counts(): array
    {
        $c = $this->em->getConnection();

        return [
            'revisions' => $this->rows('permit_revision'),
            'audits' => (int) $c->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'PERMIT_ZONE_%'"),
            'outbox' => (int) $c->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type IN ('ZONES_CHANGED', 'ZONES_REMOVED')"),
            'rows' => $this->rows('permit_authorization_zone'),
        ];
    }

    /** @return array<string, RightState> état de chaque zone de la commune à l'instant donné */
    private function states(Municipality $commune, string $utc): array
    {
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();
        $states = [];
        foreach ($this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at($utc))->authorizations as $a) {
            if ($a->municipalityName === $commune->name()) {
                foreach ($a->zones as $zone) {
                    $states[$zone->label] = $zone->state;
                }
            }
        }

        return $states;
    }

    // ------------------------------------------------------------------ ajout

    public function testAddingAZoneNeverRequestedKeepsTheCitizenRequestTheDecisionAndTheQrUntouched(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $requestZones = $this->rows('request_permit_request_zone');
        $partnerRows = $this->rowsOf($this->partner);
        $decision = $this->decisionRow($requestId, $this->organizer);

        $result = $this->change($requestId, adds: [$this->zoneB], addReason: 'NEW_NEED', comment: self::COMMENT);

        self::assertSame(1, \count($result->plan->changes));
        $rows = $this->rowsOf($this->organizer);
        self::assertSame(['label' => 'Zone B', 'removed' => false], $rows[$this->key32($this->zoneB)], 'libellé copié à l\'ajout');
        self::assertCount(2, $rows);
        self::assertSame($requestZones, $this->rows('request_permit_request_zone'), 'la demande citoyenne n\'est pas modifiée');
        self::assertSame($decision, $this->decisionRow($requestId, $this->organizer), 'la décision initiale non plus');
        self::assertSame($partnerRows, $this->rowsOf($this->partner), 'indépendance des communes');
        self::assertSame($token, $this->token(), 'le QR ne change pas');
        self::assertSame(['revisions' => 1, 'audits' => 1, 'outbox' => 1, 'rows' => 3], $this->counts());
        self::assertSame(RightState::Valid, $this->states($this->organizer, '2026-06-12 10:00:00')['Zone B'], 'la zone ajoutée suit les périodes de l\'autorisation');
    }

    public function testTheAdditionIsAuditedWithIdentifiersOnlyAndNotifiedOrdinarily(): void
    {
        $requestId = $this->approvedByBoth();

        $this->change($requestId, adds: [$this->zoneB], addReason: 'OTHER', comment: self::COMMENT);

        $revision = $this->em->getConnection()->fetchAssociative('SELECT type, reason_code, reason_text, before_state, after_state, LOWER(HEX(batch_id)) AS batch FROM permit_revision');
        self::assertIsArray($revision);
        self::assertSame(['ZONE_ADDED', 'OTHER', self::COMMENT], [$revision['type'], $revision['reason_code'], $revision['reason_text']]);
        $after = json_decode((string) $revision['after_state'], true);
        self::assertSame(['Zone B', 2], [$after['label'], \count($after['attachedZones'])]);
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'PERMIT_ZONE_ADDED'");
        foreach ([self::COMMENT, 'Martin', 'Camille', 'GH-482-KT', 'camille@exemple.fr', $this->token()] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $audit.(string) $revision['before_state'].(string) $revision['after_state']);
        }
        self::assertSame('OTHER', json_decode($audit, true)['reason']);
        $outbox = $this->em->getConnection()->fetchAssociative("SELECT available_at, occurred_at FROM notification_outbox WHERE type = 'ZONES_CHANGED'");
        self::assertIsArray($outbox);
        self::assertNotSame($outbox['occurred_at'], $outbox['available_at'], 'ajout seul : ordinaire, regroupé');
    }

    public function testAnAddedZoneIsCountedByThePublicControlAndNothingIsExposedAboutTheReason(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $this->change($requestId, adds: [$this->zoneB], addReason: 'OTHER', comment: self::COMMENT);
        $this->em->clear();

        $v = $this->service(PublicVerificationReader::class)->find($token, $this->at('2026-06-12 10:00:00'))->verification ?? self::fail();

        self::assertSame(3, $v->zoneCount, 'zone A, zone B ajoutée et zone partenaire');
        self::assertSame(3, $v->activeZoneCount);
        self::assertStringNotContainsString(self::COMMENT, serialize($v));
        self::assertStringNotContainsString('NEW_NEED', serialize($v));
    }

    // ------------------------------------------------------------------ retrait, dernière zone, zone suspendue

    public function testARemovalIsLogicalPriorityAndKeepsTheRowWithItsCopiedLabel(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);

        $this->change($requestId, removes: [$this->organizerZone], removeReason: 'NEED_ENDED');

        $rows = $this->rowsOf($this->organizer);
        self::assertSame(['label' => 'Zone A', 'removed' => true], $rows[$this->key32($this->organizerZone)], 'retrait logique : la ligne et son libellé restent');
        self::assertSame(2, $this->rows('permit_authorization_zone WHERE zone_id IN (UNHEX(\''.$this->key32($this->organizerZone).'\'))') + 1, 'aucune suppression physique');
        $removed = $this->em->getConnection()->fetchAssociative("SELECT occurred_at, available_at FROM notification_outbox WHERE type = 'ZONES_REMOVED'");
        self::assertIsArray($removed);
        self::assertSame($removed['occurred_at'], $removed['available_at'], 'retrait : prioritaire, jamais regroupé');
        self::assertArrayNotHasKey('Zone A', $this->states($this->organizer, '2026-06-12 10:00:00'), 'plus aucun droit dans la zone retirée');
    }

    public function testThePublicControlShowsARemovedZoneApartAndOutsideTheCounter(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->organizerZone], removeReason: 'NEED_ENDED', comment: self::COMMENT);
        $this->em->clear();

        $v = $this->service(PublicVerificationReader::class)->find($token, $this->at('2026-06-12 10:00:00'))->verification ?? self::fail();

        $own = array_values(array_filter($v->authorizations, fn ($a): bool => $a->municipalityName === $this->organizer->name()))[0];
        $removed = array_values(array_filter($own->zones, static fn ($z): bool => $z->isRemoved()));
        self::assertCount(1, $removed);
        self::assertSame('Zone A', $removed[0]->label);
        self::assertSame(2, $v->activeZoneCount, 'zone B et zone partenaire');
        self::assertSame(2, $v->zoneCount, 'la zone retirée est hors compteur');
        self::assertStringNotContainsString(self::COMMENT, serialize($v));
    }

    public function testTheLastAttachedZoneCannotBeRemoved(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        try {
            $this->change($requestId, removes: [$this->organizerZone], removeReason: 'NEED_ENDED');
            self::fail('La dernière zone ne se retire pas.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('révoquez', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
    }

    public function testASuspendedZoneStaysAttachedForTheLastZoneRuleButGivesNoUsableRight(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->sql("UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :id", ['id' => $this->zoneB->id()->toBinary()]);
        self::assertSame(RightState::ZoneSuspended, $this->states($this->organizer, '2026-06-12 10:00:00')['Zone B'], 'aucun droit utilisable');

        $this->change($requestId, removes: [$this->organizerZone], removeReason: 'NEED_ENDED'); // il reste la zone suspendue : rattachée

        self::assertSame(1, \count(array_filter($this->rowsOf($this->organizer), static fn (array $r): bool => !$r['removed'])), 'la zone suspendue compte comme rattachée');
        self::assertSame(RightState::ZoneSuspended, $this->states($this->organizer, '2026-06-12 10:00:00')['Zone B']);
    }

    // ------------------------------------------------------------------ réintégration

    public function testAReinstatementKeepsTheRowAndTheFullHistoryAndCopiesTheCurrentLabel(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'ISSUED_IN_ERROR');
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone B rénovée', 'id' => $this->zoneB->id()->toBinary()]);
        $rowId = $this->em->getConnection()->fetchOne('SELECT id FROM permit_authorization_zone WHERE zone_label = \'Zone B\'');

        $this->change($requestId, reinstates: [$this->zoneB], reinstateReason: 'REMOVED_IN_ERROR');

        $rows = $this->rowsOf($this->organizer);
        self::assertSame(['label' => 'Zone B rénovée', 'removed' => false], $rows[$this->key32($this->zoneB)], 'libellé courant recopié, retrait effacé');
        self::assertSame($rowId, $this->em->getConnection()->fetchOne('SELECT id FROM permit_authorization_zone WHERE zone_label = \'Zone B rénovée\''), 'la même ligne : aucune suppression ni duplication');
        $types = $this->em->getConnection()->fetchFirstColumn('SELECT type FROM permit_revision ORDER BY occurred_at, id');
        self::assertSame(['ZONE_ADDED', 'ZONE_REMOVED', 'ZONE_REINSTATED'], $types, 'l\'historique des retraits et réintégrations est reconstituable');
        $reinstated = json_decode((string) $this->em->getConnection()->fetchOne("SELECT before_state FROM permit_revision WHERE type = 'ZONE_REINSTATED'"), true);
        self::assertSame('Zone B', $reinstated['label'], 'l\'état avant garde l\'ancien libellé');
    }

    public function testARemovedZoneCannotBeAddedAgainAndMustBeReinstated(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'NEED_ENDED');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('réintégrez-la');
        $this->change($requestId, adds: [$this->zoneB]);
    }

    // ------------------------------------------------------------------ opérations groupées

    public function testAGroupedOperationWritesOneRevisionPerZoneWithACommonBatchAndOneNotification(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'NEED_ENDED');
        $before = $this->counts();

        $result = $this->change($requestId, adds: [$this->zoneC], removes: [$this->organizerZone], reinstates: [$this->zoneB], addReason: 'NEW_NEED', removeReason: 'NEED_ENDED', reinstateReason: 'NEED_RETURNED', comment: self::COMMENT);

        self::assertCount(3, $result->plan->changes);
        $after = $this->counts();
        self::assertSame(3, $after['revisions'] - $before['revisions'], 'une révision immuable par zone modifiée');
        self::assertSame(3, $after['audits'] - $before['audits']);
        self::assertSame(1, $after['outbox'] - $before['outbox'], 'une seule intention de notification pour l\'opération');
        $batches = $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(batch_id)) AS batch, COUNT(*) AS n FROM permit_revision GROUP BY batch_id ORDER BY n');
        self::assertSame([1, 1, 3], array_map(static fn (array $b): int => (int) $b['n'], $batches), 'trois opérations, dont une de trois zones sous un batch commun');
        $reasons = $this->em->getConnection()->fetchAllAssociative('SELECT type, reason_code FROM permit_revision WHERE LOWER(HEX(batch_id)) = :b ORDER BY type', ['b' => $batches[2]['batch']]);
        self::assertSame([['type' => 'ZONE_ADDED', 'reason_code' => 'NEW_NEED'], ['type' => 'ZONE_REINSTATED', 'reason_code' => 'NEED_RETURNED'], ['type' => 'ZONE_REMOVED', 'reason_code' => 'NEED_ENDED']], $reasons, 'chaque changement garde son motif');
        $types = $this->em->getConnection()->fetchFirstColumn("SELECT type FROM notification_outbox WHERE type LIKE 'ZONES_%' ORDER BY type");
        self::assertSame(['ZONES_CHANGED', 'ZONES_REMOVED', 'ZONES_REMOVED'], $types, 'ajout ordinaire, puis retrait et opération mixte prioritaires');
        $active = array_filter($this->rowsOf($this->organizer), static fn (array $r): bool => !$r['removed']);
        self::assertCount(2, $active, 'état final : Zone B réintégrée et Zone C ajoutée');
    }

    public function testAGroupedOperationWithOneInvalidZoneHasNoPartialEffect(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();
        $rows = $this->rowsOf($this->organizer);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->change($requestId, adds: [$this->zoneB, $this->partnerZone]); // la zone du partenaire n'est pas celle de la commune
        } finally {
            self::assertSame($before, $this->counts(), 'aucun effet partiel');
            self::assertSame($rows, $this->rowsOf($this->organizer));
        }
    }

    public function testRemovingEveryAttachedZoneInOneOperationIsRefusedOnTheFinalState(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $before = $this->counts();

        try {
            $this->change($requestId, removes: [$this->organizerZone, $this->zoneB], removeReason: 'NEED_ENDED');
            self::fail('Refus attendu.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('au moins une zone', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
    }

    public function testAFailureInTheMiddleOfAGroupedOperationRollsEverythingBack(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();
        $rows = $this->rowsOf($this->organizer);
        $failing = new FailingAuditTrail(self::getContainer()->get(AuditTrail::class), 2); // échec à la deuxième écriture d'audit

        try {
            $this->change($requestId, adds: [$this->zoneB, $this->zoneC], service: $this->zones($failing));
            self::fail('Le journal d\'audit en échec doit faire échouer toute l\'opération.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->em->clear();
        self::assertSame($before, $this->counts(), 'ni révision, ni audit, ni notification, ni ligne de zone');
        self::assertSame($rows, $this->rowsOf($this->organizer));
    }

    // ------------------------------------------------------------------ motifs

    public function testReasonsAreMandatoryForRemovalAndReinstatementAndOptionalForAddition(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]); // ajout sans motif : permis
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reason_code FROM permit_revision'));
        $before = $this->counts();

        foreach ([['removeReason' => null], ['removeReason' => 'NOPE'], ['removeReason' => 'OTHER'], ['removeReason' => 'NEED_ENDED', 'comment' => str_repeat('é', 201)]] as $arguments) {
            try {
                $this->change($requestId, removes: [$this->zoneB], removeReason: $arguments['removeReason'], comment: $arguments['comment'] ?? null);
                self::fail('Refus attendu.');
            } catch (DomainRuleViolation) {
                self::assertSame($before, $this->counts());
            }
        }
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'NEED_ENDED');
        try {
            $this->change($requestId, reinstates: [$this->zoneB]);
            self::fail('Motif de réintégration obligatoire.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('motif de la réintégration', $e->getMessage());
        }
        $this->change($requestId, adds: [$this->zoneC], addReason: 'OTHER', comment: 'ok'); // « Autre » avec commentaire
    }

    // ------------------------------------------------------------------ suspension de l'événement

    public function testASuspendedEventRefusesAdditionAndReinstatementButAllowsRemovalAndLabelCorrection(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB, $this->zoneC]);
        $this->change($requestId, removes: [$this->zoneC], removeReason: 'NEED_ENDED');
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $before = $this->counts();

        foreach ([['adds' => [$this->zoneC]], ['reinstates' => [$this->zoneC], 'reinstateReason' => 'NEED_RETURNED'], ['adds' => [$this->zoneB], 'removes' => [$this->organizerZone], 'removeReason' => 'NEED_ENDED']] as $arguments) {
            try {
                $this->change($requestId, ...$arguments);
                self::fail('Refus attendu pendant la suspension.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('suspendu', $e->getMessage());
                self::assertSame($before, $this->counts(), 'opération mixte : refus total, aucun effet partiel');
            }
        }
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'NEED_ENDED');
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone A renommée', 'id' => $this->organizerZone->id()->toBinary()]);
        $labels = $this->zones()->correctLabels($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, [$this->organizerZone->id()->toRfc4122()], 'TYPO', true);

        self::assertCount(1, $labels, 'la correction de libellé reste permise pendant la suspension');
    }

    public function testAClosedEventRefusesEverything(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 08:00:00'");
        $before = $this->counts();

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->change($requestId, adds: [$this->zoneB]);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    // ------------------------------------------------------------------ zones forgées, participation, révocation, rôles

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedIds(): iterable
    {
        yield 'identifiant inexistant' => ['0199aaaa-0000-7000-8000-000000000000'];
        yield 'identifiant mal formé' => ['pas-un-uuid'];
        yield 'zone de l\'autre commune' => ['PARTNER'];
        yield 'vide' => [''];
    }

    #[DataProvider('forgedIds')]
    public function testAForgedZoneIdentifierGetsTheUniformRefusalAndNothingIsWritten(string $id): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();
        $id = 'PARTNER' === $id ? $this->partnerZone->id()->toRfc4122() : $id;

        foreach (['adds', 'reinstates', 'removes'] as $kind) {
            try {
                $request = new ZoneChangeRequest(...[$kind => [$id], 'removeReason' => 'NEED_ENDED', 'reinstateReason' => 'NEED_RETURNED']);
                $this->zones()->change($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $request);
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed|DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
            self::assertSame($before, $this->counts());
        }
    }

    public function testADeletedOrUnconfirmedOrSuspendedZoneIsNotProposable(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        foreach (["UPDATE event_zone SET deleted_at = '2026-06-12 08:00:00' WHERE id = :id", "UPDATE event_zone SET status = 'SUSPENDED' WHERE id = :id"] as $sql) {
            $this->sql($sql, ['id' => $this->zoneB->id()->toBinary()]);
            try {
                $this->change($requestId, adds: [$this->zoneB]);
                self::fail('Une zone non proposable ne s\'ajoute pas.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('pas ouverte', $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inactiveParticipations(): iterable
    {
        yield 'suspendue' => ['SUSPENDED'];
        yield 'clôturée' => ['CLOSED'];
    }

    #[DataProvider('inactiveParticipations')]
    public function testASuspendedOrClosedParticipationRefusesEveryChange(string $status): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE event_participation SET status = :s WHERE municipality_id = :m', ['s' => $status, 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        $this->expectException(PermitAccessUnavailable::class);
        try {
            $this->change($requestId, adds: [$this->zoneB]);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testARevokedAuthorizationRefusesEveryZoneChange(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB]);
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        foreach ([['adds' => [$this->zoneC]], ['removes' => [$this->zoneB], 'removeReason' => 'NEED_ENDED']] as $arguments) {
            try {
                $this->change($requestId, ...$arguments);
                self::fail('Refus attendu.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('révoquée', $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testRolesAndPerimeterAreEnforced(): void
    {
        $requestId = $this->approvedByBoth();

        try {
            $this->change($requestId, adds: [$this->zoneB], agent: $this->managerOnly);
            self::fail('EVENT_MANAGER : aucune révision.');
        } catch (PermitAccessDenied) {
            self::addToAssertionCount(1);
        }
        $this->expectException(OperationNotAllowed::class);
        $this->change($requestId, adds: [$this->zoneB], agent: $this->outsiderInstructor, commune: $this->outsider);
    }

    public function testAnAgentCannotChangeTheZonesOfAnotherCommunesAuthorization(): void
    {
        $requestId = $this->approvedByBoth();

        $this->expectException(OperationNotAllowed::class);
        $this->change($requestId, removes: [$this->partnerZone], removeReason: 'NEED_ENDED', agent: $this->instructor, commune: $this->partner);
    }

    // ------------------------------------------------------------------ correction de libellé

    public function testALabelCorrectionSynchronizesWithTheReferenceWithoutAnyNotificationAndNeedsConfirmation(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone A corrigée', 'id' => $this->organizerZone->id()->toBinary()]);
        $before = $this->counts();
        $service = $this->zones();
        $ids = [$this->organizerZone->id()->toRfc4122()];

        foreach ([['NOPE', true], ['', true], ['TYPO', false]] as [$reason, $confirmed]) {
            try {
                $service->correctLabels($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $ids, $reason, $confirmed);
                self::fail('Refus attendu.');
            } catch (DomainRuleViolation) {
                self::assertSame($before, $this->counts());
            }
        }
        $changes = $service->correctLabels($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $ids, 'WORDING_CLARIFIED', true);

        self::assertSame(['Zone A corrigée', 'Zone A'], [$changes[0]->label, $changes[0]->previousLabel]);
        self::assertSame('Zone A corrigée', $this->rowsOf($this->organizer)[$this->key32($this->organizerZone)]['label']);
        $revision = $this->em->getConnection()->fetchAssociative("SELECT type, reason_code, reason_text FROM permit_revision WHERE type = 'ZONE_LABEL_CORRECTED'");
        self::assertIsArray($revision);
        self::assertSame(['ZONE_LABEL_CORRECTED', 'WORDING_CLARIFIED', null], [$revision['type'], $revision['reason_code'], $revision['reason_text']], 'code seul, aucun commentaire');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type IN ('ZONES_CHANGED', 'ZONES_REMOVED')"), 'aucune notification : le périmètre ne change pas');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PERMIT_ZONE_LABEL_CORRECTED'"));
    }

    public function testALabelAlreadyUpToDateIsRefusedAndNoFreeTextExists(): void
    {
        $requestId = $this->approvedByBoth();

        try {
            $this->zones()->correctLabels($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, [$this->organizerZone->id()->toRfc4122()], 'TYPO', true);
            self::fail('Libellé déjà à jour.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('déjà à jour', $e->getMessage());
        }
        // L'API ne reçoit AUCUN libellé : seulement des identifiants de zones et un code de motif.
        $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(AuthorizationZoneService::class, 'correctLabels'))->getParameters());
        self::assertSame(['agent', 'municipalityId', 'requestId', 'zoneIds', 'reasonCode', 'confirmed'], $parameters);
    }

    // ------------------------------------------------------------------ historique, fuite intercommunale

    public function testTheOtherCommuneSeesOnlyANeutralLineWithoutAnyZoneNameOrReason(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: [$this->zoneB], addReason: 'OTHER', comment: self::COMMENT);
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'ZONE_CLOSED_OR_UNSAFE');
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));

        $own = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $other = $reader->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId);

        self::assertSame(['Zone retirée', 'Zone ajoutée'], array_map(static fn ($l): string => $l->label, $own->history));
        self::assertTrue($own->history[0]->own);
        self::assertSame('Zone B', $own->history[0]->zone['label'] ?? null, 'détail pour la commune qui a agi');
        self::assertSame(['Zones modifiées', 'Zones modifiées'], array_map(static fn ($l): string => $l->label, $other->history), 'ligne neutre : type, date, commune');
        foreach ($other->history as $line) {
            self::assertFalse($line->own);
            self::assertNull($line->zone);
            self::assertNull($line->reasonLabel);
            self::assertNull($line->comment);
        }
        $dump = serialize($other);
        foreach (['Zone A', 'Zone B', 'Zone C', self::COMMENT, 'ZONE_CLOSED_OR_UNSAFE', 'Zone fermée'] as $leak) {
            self::assertStringNotContainsString($leak, $dump, 'aucune fuite intercommunale : '.$leak);
        }
        self::assertSame(1, \count(array_filter($other->zoneRows, static fn ($r): bool => 'Zone partenaire' === $r->label)), 'la commune ne voit que SES zones');
    }

    public function testTheOverviewOffersTheRightZoneActions(): void
    {
        $requestId = $this->approvedByBoth();
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));

        $overview = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);

        self::assertSame(['Zone B', 'Zone C'], array_map(static fn ($z): string => $z->label, $overview->addableZones));
        self::assertTrue($overview->canAddZones);
        self::assertTrue($overview->canChangeZones);
        self::assertFalse($overview->canCorrectLabels, 'tous les libellés sont à jour');

        $this->change($requestId, adds: [$this->zoneB]);
        $this->change($requestId, removes: [$this->zoneB], removeReason: 'NEED_ENDED');
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $suspended = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        self::assertFalse($suspended->canAddZones, 'ajout et réintégration impossibles pendant la suspension');
        self::assertStringContainsString('pas actif', $suspended->addZonesBlockedReason);
        self::assertTrue($suspended->canChangeZones, 'retrait possible');
    }
}
