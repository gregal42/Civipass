<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PeriodChange;
use App\Permit\Domain\PeriodEnd;
use App\Permit\Domain\PeriodKind;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Prolongation, reprise après expiration et raccourcissement d'une autorisation (LOT 05.5.2) avec les VRAIS services et MySQL : périodes successives (B2),
 * aucune reprise rétroactive, aucune période chevauchante, début inclus et fin exclue, historique conservé, décision et QR inchangés, indépendance des
 * communes, révocation irréversible, événement non actif, participation non ACTIVE, atomicité, audit sans motif ni commentaire, protections d'un historique
 * volumineux. Horloge figée au 12 juin 2026 09:00 UTC (événement du 10 au 20 juin, heure de Paris) ; décision de 72 h : fin le 15 juin 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class AuthorizationPeriodServiceTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_authorization_period'])) {
            self::markTestSkipped('Schéma des périodes non migré (LOT 05.5.2) : test exécutable après la migration validée.');
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

    private function periods(?AuditTrail $audit = null): AuthorizationPeriodService
    {
        $c = self::getContainer();

        return new AuthorizationPeriodService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $c->get(PermitRevisionGuard::class),
            $c->get(PermitRepository::class),
            $c->get(PermitRevisionRepository::class),
            $this->clock,
            $audit ?? $c->get(AuditTrail::class),
            $c->get(NotificationIntentRecorder::class),
        );
    }

    private function approvedByBoth(?ApprovalDuration $duration = null): string
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
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, $duration ?? ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    private function extend(string $requestId, PeriodEnd $end, ?string $reason = null, ?string $comment = null, ?User $agent = null, ?Municipality $commune = null, ?AuthorizationPeriodService $service = null): PeriodChange
    {
        return ($service ?? $this->periods())->extend($this->managed($agent ?? $this->instructor), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, $end, $reason, $comment);
    }

    private function shorten(string $requestId, PeriodEnd $end, string $reason = 'NEED_ENDED', ?string $comment = null, bool $confirmed = true, ?User $agent = null, ?Municipality $commune = null): PeriodChange
    {
        return $this->periods()->shorten($this->managed($agent ?? $this->instructor), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, $end, $reason, $comment, $confirmed);
    }

    private function token(): string
    {
        $this->em->clear();

        return ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
    }

    /**
     * @return array<string, RightState> état de la zone de chaque commune à l'instant donné
     */
    private function states(string $utc): array
    {
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.');
        $states = [];
        foreach ($this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at($utc))->authorizations as $authorization) {
            $states[$authorization->municipalityName] = $authorization->zones[0]->state;
        }

        return $states;
    }

    /** @return array<string, mixed> */
    private function row(Municipality $commune): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT duration_mode, valid_from, valid_until FROM permit_authorization WHERE municipality_id = :m', ['m' => $commune->id()->toBinary()]) ?: [];
    }

    /** @return list<array<string, mixed>> */
    private function closed(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT position, duration_mode, valid_from, valid_until, closed_at, LOWER(HEX(closed_by_revision_id)) AS revision FROM permit_authorization_period ORDER BY position');
    }

    /** @return array{revisions: int, closed: int, audits: int, outbox: int} */
    private function counts(): array
    {
        $c = $this->em->getConnection();

        return [
            'revisions' => $this->rows('permit_revision'),
            'closed' => $this->rows('permit_authorization_period'),
            'audits' => (int) $c->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'PERMIT_PERIOD_%'"),
            'outbox' => (int) $c->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type IN ('PERMIT_EXTENDED', 'PERMIT_RESUMED', 'PERMIT_SHORTENED')"),
        ];
    }

    // ------------------------------------------------------------------ prolongation

    public function testAnExtensionMovesTheEndOfTheCurrentPeriodOnlyAndKeepsEverythingElse(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $partnerBefore = $this->row($this->partner);
        $decisionBefore = $this->decisionRow($requestId, $this->organizer);

        $change = $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');

        self::assertSame(PeriodKind::Extended, $change->plan->kind);
        $row = $this->row($this->organizer);
        self::assertSame('2026-06-12 09:00:00', $row['valid_from']);
        self::assertSame('2026-06-16 09:00:00', $row['valid_until']);
        self::assertSame('CUSTOM', $row['duration_mode']);
        self::assertSame([], $this->closed(), 'aucune période close pour une prolongation ordinaire');
        self::assertSame($partnerBefore, $this->row($this->partner), 'indépendance des communes');
        self::assertSame($decisionBefore, $this->decisionRow($requestId, $this->organizer), 'la décision initiale n\'est jamais modifiée');
        self::assertSame($token, $this->token(), 'le QR ne change pas');
        $states = $this->states('2026-06-15 10:00:00');
        self::assertSame(RightState::Valid, $states[$this->organizer->name()], 'prolongée : encore valide après l\'ancienne fin');
        self::assertSame(RightState::Expired, $states[$this->partner->name()], 'l\'autre commune garde sa fin d\'origine');
    }

    public function testTheRevisionAuditAndNotificationAreWrittenTogetherWithoutReasonLeaks(): void
    {
        $requestId = $this->approvedByBoth();

        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'OTHER', self::COMMENT);

        self::assertSame(['revisions' => 1, 'closed' => 0, 'audits' => 1, 'outbox' => 1], $this->counts());
        $revision = $this->em->getConnection()->fetchAssociative('SELECT type, reason_code, reason_text, before_state, after_state FROM permit_revision');
        self::assertIsArray($revision);
        self::assertSame(['PERIOD_EXTENDED', 'OTHER', self::COMMENT], [$revision['type'], $revision['reason_code'], $revision['reason_text']]);
        self::assertSame('2026-06-15T09:00:00+00:00', json_decode((string) $revision['before_state'], true)['validUntil']);
        self::assertSame('2026-06-16T09:00:00+00:00', json_decode((string) $revision['after_state'], true)['validUntil']);
        $audit = (string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'PERMIT_PERIOD_EXTENDED'");
        foreach ([self::COMMENT, 'Martin', 'Camille', 'GH-482-KT', 'camille@exemple.fr', $this->token()] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $audit.(string) $revision['before_state'].(string) $revision['after_state']);
        }
        self::assertSame('OTHER', json_decode($audit, true)['reason']);
        $outbox = $this->em->getConnection()->fetchAssociative('SELECT type, LOWER(HEX(revision_id)) AS revision, available_at, occurred_at FROM notification_outbox WHERE type = \'PERMIT_EXTENDED\'');
        self::assertIsArray($outbox);
        self::assertNotSame($outbox['available_at'], $outbox['occurred_at'], 'prolongation : ordinaire, regroupée (attend)');
    }

    public function testTheReasonIsOptionalForAnOrdinaryExtensionButValidatedWhenGiven(): void
    {
        $requestId = $this->approvedByBoth();

        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT reason_code FROM permit_revision'));

        foreach ([['NOT_A_REASON', null], ['OTHER', null], ['DUPLICATE', null], ['NEED_EXTENDED', str_repeat('é', 201)]] as [$reason, $comment]) {
            $before = $this->counts();
            try {
                $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), $reason, $comment);
                self::fail('Refus attendu pour '.$reason);
            } catch (DomainRuleViolation) {
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testAnExtensionBeyondTheEventEndIsRefusedAndNothingIsWritten(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        try {
            $this->extend($requestId, PeriodEnd::custom('2026-07-01', '10:00'));
            self::fail('Refus attendu.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('fin prévue de l\'événement', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
    }

    // ------------------------------------------------------------------ reprise après expiration

    public function testAResumptionArchivesTheOldPeriodAndNeverRestoresTheInterruption(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $this->clock->sleep(4 * 86400); // 16 juin 09:00 : la période (fin 15 juin 09:00) est terminée
        self::assertSame(RightState::Expired, $this->states('2026-06-16 09:00:00')[$this->organizer->name()]);

        $change = $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');

        self::assertSame(PeriodKind::Resumed, $change->plan->kind);
        $row = $this->row($this->organizer);
        self::assertSame(['2026-06-16 09:00:00', '2026-06-17 09:00:00'], [$row['valid_from'], $row['valid_until']], 'nouvelle période courante : maintenant → +24 h');
        $closed = $this->closed();
        self::assertCount(1, $closed);
        self::assertSame([1, '2026-06-12 09:00:00', '2026-06-15 09:00:00', '2026-06-16 09:00:00'], [(int) $closed[0]['position'], $closed[0]['valid_from'], $closed[0]['valid_until'], $closed[0]['closed_at']], 'ancienne période conservée');
        $revisionId = (string) $this->em->getConnection()->fetchOne('SELECT LOWER(HEX(id)) FROM permit_revision');
        self::assertSame($revisionId, $closed[0]['revision'], 'cohérence période close ⇄ révision immuable');
        self::assertSame($token, $this->token());

        // Droits : valides dans chaque période, AUCUN pendant l'interruption, à la seconde près (début inclus, fin exclue).
        $name = $this->organizer->name();
        self::assertSame(RightState::Valid, $this->states('2026-06-14 10:00:00')[$name], 'l\'historique : valide pendant la première période');
        self::assertSame(RightState::Valid, $this->states('2026-06-15 08:59:59')[$name]);
        self::assertNotSame(RightState::Valid, $this->states('2026-06-15 09:00:00')[$name], 'fin de la première période exclue');
        self::assertNotSame(RightState::Valid, $this->states('2026-06-15 20:00:00')[$name], 'AUCUN droit pendant l\'interruption : pas de rétablissement rétroactif');
        self::assertNotSame(RightState::Valid, $this->states('2026-06-16 08:59:59')[$name], 'une seconde avant la reprise');
        self::assertSame(RightState::Valid, $this->states('2026-06-16 09:00:00')[$name], 'début de la reprise inclus');
        self::assertSame(RightState::Valid, $this->states('2026-06-17 08:59:59')[$name]);
        self::assertSame(RightState::Expired, $this->states('2026-06-17 09:00:00')[$name], 'fin de la reprise exclue');
    }

    public function testAResumptionNeedsAReasonAndWritesNothingWithout(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $before = $this->counts();

        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
            self::fail('Un motif est obligatoire pour une reprise.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('motif', mb_strtolower($e->getMessage()));
        }
        self::assertSame($before, $this->counts());
        self::assertSame('2026-06-15 09:00:00', $this->row($this->organizer)['valid_until'], 'rien n\'a bougé');
    }

    public function testPeriodsNeverOverlapAndPositionsAreContiguousAfterSeveralResumptions(): void
    {
        $requestId = $this->approvedByBoth();
        foreach ([4, 3, 2] as $days) {
            $this->clock->sleep($days * 86400);
            if ($this->clock->now() > $this->at('2026-06-20 12:00:00')) {
                break;
            }
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
        }
        $closed = $this->closed();
        self::assertGreaterThanOrEqual(1, \count($closed));
        $previousEnd = null;
        foreach ($closed as $i => $period) {
            self::assertSame($i + 1, (int) $period['position'], 'positions contiguës');
            self::assertLessThan($period['valid_until'], $period['valid_from']);
            if (null !== $previousEnd) {
                self::assertLessThan($period['valid_from'], $previousEnd, 'aucune période chevauchante : fin précédente < début suivant');
            }
            $previousEnd = $period['valid_until'];
        }
        self::assertLessThan($this->row($this->organizer)['valid_from'], $previousEnd, 'la période courante commence après la dernière période close');
    }

    public function testResumingAfterAnImmediateShorteningIsPossible(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(3600);
        $this->shorten($requestId, PeriodEnd::custom('2026-06-12', '10:00')); // 08:00 UTC, passé : fin immédiate à 10:00 UTC
        self::assertSame('2026-06-12 10:00:00', $this->row($this->organizer)['valid_until']);
        $this->clock->sleep(7200);

        $change = $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'BENEFICIARY_REQUEST');

        self::assertSame(PeriodKind::Resumed, $change->plan->kind);
        self::assertSame([['position' => 1, 'valid_until' => '2026-06-12 10:00:00']], array_map(static fn (array $p): array => ['position' => (int) $p['position'], 'valid_until' => $p['valid_until']], $this->closed()));
        self::assertNotSame(RightState::Valid, $this->states('2026-06-12 11:00:00')[$this->organizer->name()], 'interruption entre la fin immédiate et la reprise');
    }

    // ------------------------------------------------------------------ raccourcissement

    public function testAShorteningWithAFutureEndIsAppliedAndNotifiedWithPriority(): void
    {
        $requestId = $this->approvedByBoth();

        $change = $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'), 'AREA_REOPENED', self::COMMENT);

        self::assertSame(PeriodKind::Shortened, $change->plan->kind);
        self::assertFalse($change->plan->immediate);
        self::assertSame('2026-06-14 10:00:00', $this->row($this->organizer)['valid_until']);
        self::assertSame(['revisions' => 1, 'closed' => 0, 'audits' => 1, 'outbox' => 1], $this->counts());
        $outbox = $this->em->getConnection()->fetchAssociative("SELECT available_at, occurred_at, status FROM notification_outbox WHERE type = 'PERMIT_SHORTENED'");
        self::assertIsArray($outbox);
        self::assertSame($outbox['occurred_at'], $outbox['available_at'], 'raccourcissement : prioritaire, jamais regroupé ni retardé');
        self::assertSame(RightState::Valid, $this->states('2026-06-14 09:59:59')[$this->organizer->name()]);
        self::assertSame(RightState::Expired, $this->states('2026-06-14 10:00:00')[$this->organizer->name()]);
        self::assertSame('2026-06-15 09:00:00', $this->row($this->partner)['valid_until'], 'indépendance des communes');
    }

    public function testAPastDateGivesAnImmediateEndAndTheRequestedDateIsKept(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(7200); // 11:00 UTC

        $change = $this->shorten($requestId, PeriodEnd::custom('2026-06-12', '10:00')); // 08:00 UTC : passé

        self::assertTrue($change->plan->immediate);
        self::assertSame('2026-06-12 11:00:00', $this->row($this->organizer)['valid_until'], 'jamais antidaté');
        $after = json_decode((string) $this->em->getConnection()->fetchOne('SELECT after_state FROM permit_revision'), true);
        self::assertSame('2026-06-12T08:00:00+00:00', $after['requestedUntil']);
        self::assertTrue($after['immediate']);
        self::assertSame(RightState::Expired, $this->states('2026-06-12 11:00:00')[$this->organizer->name()]);
        self::assertSame(RightState::Valid, $this->states('2026-06-12 10:59:59')[$this->organizer->name()], 'les droits passés ne sont pas réécrits');
    }

    public function testAShorteningNeedsAReasonAndAnExplicitConfirmation(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        foreach ([['', null, true], ['NOPE', null, true], ['OTHER', null, true], ['NEED_ENDED', null, false]] as [$reason, $comment, $confirmed]) {
            try {
                $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'), $reason, $comment, $confirmed);
                self::fail('Refus attendu.');
            } catch (DomainRuleViolation) {
                self::assertSame($before, $this->counts());
            }
        }
    }

    // ------------------------------------------------------------------ événement, participation, révocation, périmètre

    public function testASuspendedEventRefusesExtensionAndResumptionButNotShortening(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $before = $this->counts();

        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24));
            self::fail('Prolongation refusée pendant la suspension.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('suspendu', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertSame(PeriodKind::Shortened, $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'))->plan->kind, 'retirer des droits reste possible');
        $this->clock->sleep(4 * 86400); // la période raccourcie (fin 14 juin) est terminée : reprise demandée pendant la suspension
        $counts = $this->counts();
        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
            self::fail('Reprise refusée pendant la suspension.');
        } catch (DomainRuleViolation) {
            self::assertSame($counts, $this->counts());
        }
    }

    public function testAClosedEventRefusesEverything(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = '2026-06-12 08:00:00'");
        $before = $this->counts();

        foreach ([fn () => $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)), fn () => $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'))] as $operation) {
            try {
                $operation();
                self::fail('Refus attendu.');
            } catch (\App\Shared\Domain\ClosedEventAccess) { // LOT 05.6.2 : la garde de clôture refuse avant tout calcul de période
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testANonActiveParticipationRefusesEveryChangeUnderLock(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_participation SET status = 'SUSPENDED' WHERE municipality_id = :m", ['m' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        foreach ([fn () => $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)), fn () => $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'))] as $operation) {
            try {
                $operation();
                self::fail('PermitAccessUnavailable attendue.');
            } catch (PermitAccessUnavailable) {
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testARevokedAuthorizationRefusesEveryChange(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        foreach ([fn () => $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24)), fn () => $this->shorten($requestId, PeriodEnd::custom('2026-06-14', '12:00'))] as $operation) {
            try {
                $operation();
                self::fail('Refus attendu : révocation irréversible, aucune période modifiable.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('révoquée', $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testRolesAndPerimeterAreEnforcedWithAUniformRefusal(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), null, null, $this->outsiderInstructor, $this->outsider);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testAnEventManagerCannotChangeAPeriod(): void
    {
        $requestId = $this->approvedByBoth();

        $this->expectException(PermitAccessDenied::class);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), null, null, $this->managerOnly);
    }

    public function testAnAgentCannotUseAnotherCommunesIdentifier(): void
    {
        $requestId = $this->approvedByBoth();

        $this->expectException(OperationNotAllowed::class);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), null, null, $this->instructor, $this->partner);
    }

    // ------------------------------------------------------------------ atomicité

    public function testAFailureAfterTheChangeRollsEverythingBack(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $failing = new FailingAuditTrail(self::getContainer()->get(AuditTrail::class), 1);
        $before = $this->counts();
        $row = $this->row($this->organizer);

        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', null, null, null, $this->periods($failing));
            self::fail('Le journal d\'audit en échec doit faire échouer l\'opération.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->em->clear();
        self::assertSame($before, $this->counts(), 'ni période close, ni révision, ni audit, ni notification');
        self::assertSame($row, $this->row($this->organizer), 'la période courante n\'a pas bougé');
    }

    // ------------------------------------------------------------------ créneaux de zone, lecteur public, écran

    public function testZoneSlotsApplyToEachPeriodAndNeverInTheInterruption(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_zone SET validity_mode = 'SPECIFIC_PERIODS' WHERE id = :id", ['id' => $this->organizerZone->id()->toBinary()]);
        $zone = $this->em->find(\App\Event\Domain\EventZone::class, $this->organizerZone->id()) ?? self::fail();
        $slot = static fn (string $date): \App\Event\Domain\EventZoneValidity => \App\Event\Domain\EventZoneValidity::fromSlot($zone, \App\Event\Domain\Validity\ValiditySlot::punctual(\App\Tests\Support\TimeFactory::date($date), \App\Tests\Support\TimeFactory::time('09:00'), \App\Tests\Support\TimeFactory::time('17:00')), \App\Tests\Support\EventFixtures::now());
        $this->save($slot('2026-06-13'), $slot('2026-06-17'), $slot('2026-06-18'));
        $this->clock->sleep(4 * 86400); // 16 juin 09:00 : première période (fin 15 juin) terminée
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H72), 'NEED_EXTENDED'); // 16 juin 09:00 → 19 juin 09:00

        $name = $this->organizer->name();
        self::assertSame(RightState::Valid, $this->states('2026-06-13 08:00:00')[$name], '13 juin 10:00 Paris : créneau dans la première période');
        self::assertNotSame(RightState::Valid, $this->states('2026-06-14 10:00:00')[$name], 'aucun créneau ce jour-là');
        self::assertSame(RightState::Valid, $this->states('2026-06-17 08:00:00')[$name], 'créneau du 17 dans la reprise');
        self::assertSame(RightState::Valid, $this->states('2026-06-18 08:00:00')[$name]);
    }

    public function testThePublicReaderShowsTheCurrentPeriodOnlyAndNotTheHistoryOfResumptions(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $this->clock->sleep(4 * 86400);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', self::COMMENT);
        $this->em->clear();

        $lookup = $this->service(PublicVerificationReader::class)->find($token, $this->clock->now());

        $v = $lookup->verification ?? self::fail();
        $own = array_values(array_filter($v->authorizations, fn ($a): bool => $a->municipalityName === $this->organizer->name()))[0];
        self::assertEquals($this->at('2026-06-16 09:00:00'), $own->validFrom, 'période applicable au moment du contrôle');
        self::assertEquals($this->at('2026-06-17 09:00:00'), $own->validUntil);
        self::assertStringNotContainsString(self::COMMENT, serialize($v));
        self::assertStringNotContainsString('2026-06-12', serialize($own), 'la période close n\'est pas exposée');
        $periods = $own->zones[0]->periods;
        self::assertCount(1, $periods, 'seules les périodes de la période courante sont listées : l\'historique des reprises n\'est pas exposé');
        self::assertEquals($this->at('2026-06-16 09:00:00'), $periods[0]->from);
    }

    private function reader(): PermitRightsOverviewReader
    {
        $c = self::getContainer();

        return new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock);
    }

    public function testTheOverviewOffersTheRightActionsAndShowsDetailedOwnHistoryButNeutralLinesToOthers(): void
    {
        $requestId = $this->approvedByBoth();
        $first = $this->reader()->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        self::assertSame('extend', $first->extendMode);
        self::assertTrue($first->canShorten);

        $this->clock->sleep(4 * 86400);
        $expired = $this->reader()->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        self::assertSame('resume', $expired->extendMode, 'période terminée : l\'action devient « reprendre »');
        self::assertFalse($expired->canShorten, 'rien à raccourcir sur une période terminée');

        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED', self::COMMENT);
        $this->em->clear();
        $own = $this->reader()->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $other = $this->reader()->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId);

        self::assertTrue($own->history[0]->own);
        self::assertSame('Reprise des droits', $own->history[0]->label);
        self::assertNotNull($own->history[0]->period);
        self::assertSame('Besoin prolongé', $own->history[0]->reasonLabel);
        self::assertFalse($other->history[0]->own);
        self::assertSame('Reprise des droits', $other->history[0]->label);
        self::assertNull($other->history[0]->period);
        self::assertNull($other->history[0]->comment);
        self::assertNull($other->history[0]->reasonLabel);
        self::assertStringNotContainsString(self::COMMENT, serialize($other));
    }

    // ------------------------------------------------------------------ historique volumineux : protections techniques

    /** Ajoute artificiellement des périodes closes (rangs 2..N, une heure chacune depuis le 1er janvier) ; la 1re est issue d'une vraie reprise. */
    private function inflateClosedPeriods(int $total): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement('SET SESSION cte_max_recursion_depth = 100000');
        $c->executeStatement(
            'INSERT INTO permit_authorization_period (id, authorization_id, position, duration_mode, valid_from, valid_until, closed_at, closed_by_revision_id)
             WITH RECURSIVE seq (n) AS (SELECT 2 UNION ALL SELECT n + 1 FROM seq WHERE n < :total)
             SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), p.authorization_id, seq.n, \'CUSTOM\',
                    DATE_ADD(\'2025-01-01 00:00:00\', INTERVAL (seq.n * 2) HOUR), DATE_ADD(\'2025-01-01 00:00:00\', INTERVAL (seq.n * 2 + 1) HOUR),
                    DATE_ADD(\'2025-01-01 00:00:00\', INTERVAL (seq.n * 2 + 1) HOUR), p.closed_by_revision_id
             FROM permit_authorization_period p JOIN seq WHERE p.position = 1',
            ['total' => $total],
        );
        $this->em->clear();
    }

    public function testALargeHistoryIsNotBlockedByAFunctionalCapButIsFlaggedUnusual(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED'); // 1 période close
        $this->inflateClosedPeriods(AuthorizationPeriodService::ALERT_THRESHOLD);
        $this->clock->sleep(2 * 86400);

        $change = $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');

        self::assertTrue($change->unusual, 'au-delà du seuil : marqué inhabituel, jamais bloqué');
        $audit = json_decode((string) $this->em->getConnection()->fetchOne("SELECT new_value FROM audit_log WHERE action = 'PERMIT_PERIOD_RESUMED' ORDER BY occurred_at DESC LIMIT 1"), true);
        self::assertTrue($audit['unusual']);
        self::assertSame(AuthorizationPeriodService::ALERT_THRESHOLD + 1, $audit['closedPeriods']);
        self::assertSame(AuthorizationPeriodService::ALERT_THRESHOLD + 1, \count($this->closed()));
    }

    public function testTheRightsRemainCorrectAndBoundedWithManyClosedPeriods(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
        $this->inflateClosedPeriods(200);
        $this->em->clear();
        $connection = $this->em->getConnection();
        $before = (int) ($connection->fetchNumeric("SHOW SESSION STATUS LIKE 'Questions'")[1] ?? 0);
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();

        $rights = $this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at('2026-06-16 10:00:00'));
        $queries = (int) ($connection->fetchNumeric("SHOW SESSION STATUS LIKE 'Questions'")[1] ?? 0) - $before - 1;

        $states = array_map(static fn ($a): RightState => $a->zones[0]->state, $rights->authorizations);
        self::assertContains(RightState::Valid, $states);
        self::assertLessThanOrEqual(25, $queries, 'une seule lecture des périodes closes par autorisation : le nombre de requêtes ne dépend pas du nombre de périodes');
        $collection = (new \ReflectionProperty(\App\Permit\Domain\PermitAuthorization::class, 'closedPeriods'))->getValue($permit->authorizations()[0]);
        self::assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $collection);
        self::assertFalse($collection->isInitialized(), 'valide maintenant : les 200 périodes closes ne sont même pas lues, le coût d\'un contrôle ne dépend pas de l\'historique');

        // Hors de la période courante, l'état tient compte de toutes les périodes (lues alors).
        $this->em->clear();
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();
        $this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at('2026-06-15 20:00:00'));
        $collection = (new \ReflectionProperty(\App\Permit\Domain\PermitAuthorization::class, 'closedPeriods'))->getValue($permit->authorizations()[0]);
        self::assertInstanceOf(\Doctrine\ORM\PersistentCollection::class, $collection);
        self::assertTrue($collection->isInitialized());
    }

    public function testThereIsNoArbitraryCeilingAHugeHistoryStillAllowsALegitimateResumption(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
        $this->inflateClosedPeriods(600);
        $this->clock->sleep(2 * 86400);

        $change = $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');

        self::assertSame(PeriodKind::Resumed, $change->plan->kind, 'aucun plafond bloquant');
        self::assertTrue($change->unusual);
        self::assertSame(601, \count($this->closed()));
    }

    public function testTheOnlyLimitIsThePhysicalStorageOfThePositionAndItGivesAClearMessage(): void
    {
        $requestId = $this->approvedByBoth();
        $this->clock->sleep(4 * 86400);
        $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
        $this->inflateClosedPeriods(AuthorizationPeriodService::POSITION_STORAGE_LIMIT);
        $this->clock->sleep(2 * 86400);
        $before = $this->counts();

        try {
            $this->extend($requestId, PeriodEnd::fixedHours(DurationMode::H24), 'NEED_EXTENDED');
            self::fail('La limite de stockage doit donner un message clair, pas une erreur SQL.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('Limite de stockage', $e->getMessage());
        }
        self::assertSame($before, $this->counts());
        self::assertSame(65535, AuthorizationPeriodService::POSITION_STORAGE_LIMIT, 'SMALLINT non signé');
    }

    // ------------------------------------------------------------------ fuseaux : bornes exactes autour d'un changement d'heure

    public function testACustomLocalEndAcrossTheSpringTransitionIsExactToTheSecond(): void
    {
        $this->sql("UPDATE event_event SET starts_at = '2026-03-27 00:00:00', ends_at = '2026-04-02 00:00:00'");
        $this->clock = new \Symfony\Component\Clock\MockClock('2026-03-28 09:00:00 UTC');
        $requestId = $this->approvedByBoth(ApprovalDuration::fixed(DurationMode::H24)); // fin le 29 mars 09:00 UTC
        $this->clock->sleep(3600);

        $this->extend($requestId, PeriodEnd::custom('2026-03-29', '18:00')); // CEST (+2) : 16:00 UTC

        self::assertSame('2026-03-29 16:00:00', $this->row($this->organizer)['valid_until']);
        self::assertSame(RightState::Valid, $this->states('2026-03-29 15:59:59')[$this->organizer->name()]);
        self::assertSame(RightState::Expired, $this->states('2026-03-29 16:00:00')[$this->organizer->name()]);
    }

    public function testAnAmbiguousLocalEndNeedsTheOccurrenceChoiceAtTheAutumnTransition(): void
    {
        $this->sql("UPDATE event_event SET starts_at = '2026-10-22 00:00:00', ends_at = '2026-10-28 00:00:00'");
        $this->clock = new \Symfony\Component\Clock\MockClock('2026-10-23 09:00:00 UTC'); // fin de l'autorisation (24 h) : 24 octobre 09:00 UTC, avant le changement d'heure
        $requestId = $this->approvedByBoth(ApprovalDuration::fixed(DurationMode::H24));
        $before = $this->counts();

        try {
            $this->extend($requestId, PeriodEnd::custom('2026-10-25', '02:30'));
            self::fail('Horaire répété sans choix : refus attendu.');
        } catch (\App\Event\Domain\Time\AmbiguousLocalTime) {
            self::assertSame($before, $this->counts());
        }
        $this->extend($requestId, PeriodEnd::custom('2026-10-25', '02:30', \App\Event\Domain\Time\Occurrence::Second));

        self::assertSame('2026-10-25 01:30:00', $this->row($this->organizer)['valid_until'], '02:30 CET (deuxième occurrence) = 01:30 UTC');
    }
}
