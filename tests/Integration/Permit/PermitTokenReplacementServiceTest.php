<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\TokenReplacementResult;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Remplacement du QR code d'un laissez-passer (LOT 05.5.5) avec les VRAIS services et MySQL : un seul jeton actif à tout instant, ancien jeton retiré (410), droits et
 * référence inchangés, idempotence persistante par identifiant d'opération (rejeu, reprise après incident), MUNICIPALITY_ADMIN seul, états de l'événement, motifs,
 * commentaire sans jeton, remplacement rapproché (référence ressaisie), signalement sans blocage, collisions, ligne neutre des autres communes, aucun jeton dans l'audit,
 * les états de révision, l'outbox ni les exceptions. Horloge figée au 12 juin 2026 09:00 UTC ; décision de 72 h.
 */
#[Group('requires-request-schema')]
final class PermitTokenReplacementServiceTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private User $partnerAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partnerAdmin = UserBuilder::aUser()->build();
        $this->save($this->partnerAdmin, MembershipBuilder::aMembership($this->partnerAdmin, $this->partner)->withRoles(Role::MunicipalityAdmin)->build());
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    /** @param (\Closure(): string)|null $generator */
    private function replacer(?AuditTrail $audit = null, ?AbstractLogger $logger = null, ?\Closure $generator = null): PermitTokenReplacementService
    {
        $c = self::getContainer();

        return new PermitTokenReplacementService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $c->get(PermitRevisionGuard::class),
            $c->get(PermitRevisionRepository::class),
            $c->get(PermitRepository::class),
            $this->clock,
            $audit ?? $c->get(AuditTrail::class),
            $c->get(NotificationIntentRecorder::class),
            $logger,
            $generator,
        );
    }

    private function approvedByBoth(TravelMode $mode = TravelMode::Vehicle): string
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
            $mode,
            TravelMode::Vehicle === $mode ? ['GH-482-KT', 'AB-123-CD'] : [],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    private function replace(string $requestId, ?string $operation = null, string $reason = 'LOST', ?string $comment = null, bool $confirm = true, ?string $reference = null, ?User $agent = null, ?Municipality $commune = null, ?PermitTokenReplacementService $service = null): TokenReplacementResult
    {
        return ($service ?? $this->replacer())->replace(
            $this->managed($agent ?? $this->adminOnly),
            ($commune ?? $this->organizer)->id()->toRfc4122(),
            $requestId,
            new TokenReplacementRequest($operation ?? Uuid::v7()->toRfc4122(), $reason, $comment, $confirm, $reference),
        );
    }

    /** @return list<array{token: string, active: bool, retired: bool}> du plus ancien au plus récent */
    private function tokens(): array
    {
        $this->em->clear();

        return array_map(static fn (array $r): array => ['token' => (string) $r['token'], 'active' => null !== $r['active_permit_id'], 'retired' => null !== $r['retired_at']], $this->em->getConnection()->fetchAllAssociative('SELECT token, active_permit_id, retired_at FROM permit_verification_token ORDER BY created_at, id'));
    }

    private function activeToken(): string
    {
        $active = array_values(array_filter($this->tokens(), static fn (array $t): bool => $t['active']));
        self::assertCount(1, $active, 'un et un seul jeton actif');

        return $active[0]['token'];
    }

    private function outcome(string $token): PublicVerificationOutcome
    {
        $this->em->clear();

        return $this->service(PublicVerificationReader::class)->find($token, $this->clock->now())->outcome;
    }

    /** @return array{revisions: int, tokens: int, audits: int, outbox: int} */
    private function counts(): array
    {
        $c = $this->em->getConnection();

        return [
            'revisions' => $this->rows('permit_revision'),
            'tokens' => $this->rows('permit_verification_token'),
            'audits' => (int) $c->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'PERMIT_TOKEN%'"),
            'outbox' => (int) $c->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type = 'QR_REPLACED'"),
        ];
    }

    private function overview(User $agent, Municipality $commune, string $requestId): \App\Permit\Application\Read\PermitRightsOverview
    {
        $c = self::getContainer();

        return (new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class)))
            ->overview($this->managed($agent), $commune->id()->toRfc4122(), $requestId);
    }

    // ------------------------------------------------------------------ remplacement atomique

    public function testReplacementRetiresTheOldTokenCreatesTheNewOneAndKeepsEverythingElse(): void
    {
        $requestId = $this->approvedByBoth();
        $old = $this->activeToken();
        $before = [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_authorization_zone'), $this->rows('permit_vehicle'), $this->rows('request_permit_request')];
        $reference = $this->em->getConnection()->fetchOne('SELECT reference FROM permit_permit');
        $rightsBefore = $this->service(PublicVerificationReader::class)->find($old, $this->clock->now())->verification ?? self::fail();

        $result = $this->replace($requestId);

        self::assertSame([1, false, false, false, true], [$result->sequence, $result->close, $result->unusual, $result->replayed, $result->recipientAvailable]);
        $new = $this->activeToken();
        self::assertNotSame($old, $new);
        self::assertCount(2, $this->tokens());
        self::assertSame(PublicVerificationOutcome::Replaced, $this->outcome($old), 'ancien QR : définitivement invalide (410)');
        self::assertSame(PublicVerificationOutcome::Found, $this->outcome($new));
        self::assertSame($before, [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_authorization_zone'), $this->rows('permit_vehicle'), $this->rows('request_permit_request')], 'laissez-passer, autorisations, zones, plaques et demande inchangés');
        self::assertSame($reference, $this->em->getConnection()->fetchOne('SELECT reference FROM permit_permit'));
        $rightsAfter = $this->service(PublicVerificationReader::class)->find($new, $this->clock->now())->verification ?? self::fail();
        self::assertSame([$rightsBefore->reference, $rightsBefore->plates, $rightsBefore->activeZoneCount, $rightsBefore->zoneCount, $rightsBefore->verdict], [$rightsAfter->reference, $rightsAfter->plates, $rightsAfter->activeZoneCount, $rightsAfter->zoneCount, $rightsAfter->verdict], 'le nouveau QR donne les mêmes droits');
    }

    public function testTheTokenRowsAreKeptAndTheRetiredOneNeverBecomesActiveAgain(): void
    {
        $requestId = $this->approvedByBoth();
        $first = $this->activeToken();
        $this->replace($requestId);
        $second = $this->activeToken();
        $this->clock->sleep(3601);
        $this->replace($requestId);
        $third = $this->activeToken();

        $tokens = $this->tokens();
        self::assertSame([$first, $second, $third], array_column($tokens, 'token'));
        self::assertSame([[false, true], [false, true], [true, false]], array_map(static fn (array $t): array => [$t['active'], $t['retired']], $tokens));
        foreach ([$first, $second] as $retired) {
            self::assertSame(PublicVerificationOutcome::Replaced, $this->outcome($retired));
        }
        self::assertSame(PublicVerificationOutcome::Found, $this->outcome($third));
        $sequences = array_map(static fn (string $s): int => (int) json_decode($s, true)['sequence'], $this->em->getConnection()->fetchFirstColumn('SELECT after_state FROM permit_revision ORDER BY occurred_at, id'));
        self::assertSame([1, 2], $sequences, 'les remplacements successifs se reconstituent par leur numéro d\'ordre');
    }

    public function testAFootBikePermitHasAQrToo(): void
    {
        $requestId = $this->approvedByBoth(TravelMode::FootBike);
        $old = $this->activeToken();

        $this->replace($requestId);

        self::assertSame(PublicVerificationOutcome::Replaced, $this->outcome($old));
    }

    // ------------------------------------------------------------------ jeton : sécurité

    public function testNoTokenAppearsInTheRevisionTheAuditTheOutboxOrTheCommentStorage(): void
    {
        $requestId = $this->approvedByBoth();
        $old = $this->activeToken();
        $this->replace($requestId, reason: 'OTHER', comment: self::COMMENT);
        $new = $this->activeToken();

        $c = $this->em->getConnection();
        $dump = json_encode([
            $c->fetchAllAssociative('SELECT before_state, after_state, reason_code, reason_text FROM permit_revision'),
            $c->fetchAllAssociative("SELECT new_value, previous_value FROM audit_log WHERE action LIKE 'PERMIT_TOKEN%'"),
            $c->fetchAllAssociative('SELECT type, LOWER(HEX(revision_id)) AS revision, LOWER(HEX(authorization_id)) AS authorization, last_error_code FROM notification_outbox'),
        ], \JSON_THROW_ON_ERROR);
        foreach ([$old, $new, strtoupper($old), strtoupper($new)] as $token) {
            self::assertStringNotContainsString($token, $dump, 'jamais un jeton dans une révision, un audit ou l\'outbox');
        }
        $audit = json_decode((string) $c->fetchOne("SELECT new_value FROM audit_log WHERE action = 'PERMIT_TOKEN_REPLACED'"), true);
        self::assertSame(['OTHER', 1, false, false], [$audit['reason'], $audit['sequence'], $audit['close'], $audit['unusual']]);
        self::assertStringNotContainsString(self::COMMENT, (string) json_encode($audit), 'jamais le commentaire dans l\'audit');
        self::assertNull($c->fetchOne('SELECT authorization_id FROM permit_revision'), 'le QR appartient au laissez-passer, pas à une autorisation');
        self::assertNull($c->fetchOne("SELECT authorization_id FROM notification_outbox WHERE type = 'QR_REPLACED'"));
        self::assertSame(self::COMMENT, $c->fetchOne('SELECT reason_text FROM permit_revision'));
    }

    // ------------------------------------------------------------------ idempotence persistante

    public function testTheSameOperationIdentifierReplacedTwiceProducesOneReplacementAndReturnsTheInitialResult(): void
    {
        $requestId = $this->approvedByBoth();
        $operation = Uuid::v7()->toRfc4122();

        $first = $this->replace($requestId, $operation);
        $tokenAfterFirst = $this->activeToken();
        $counts = $this->counts();
        $second = $this->replace($requestId, $operation);
        $third = $this->replace($requestId, $operation);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed && $third->replayed);
        self::assertSame([$first->sequence, $first->close, $first->unusual], [$second->sequence, $second->close, $second->unusual], 'résultat initial restitué');
        self::assertSame($tokenAfterFirst, $this->activeToken(), 'aucun second remplacement');
        self::assertSame($counts, $this->counts(), 'ni révision, ni jeton, ni audit, ni notification de plus');
        self::assertSame($operation, Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT batch_id FROM permit_revision'))->toRfc4122(), 'l\'identifiant d\'opération est réellement persisté (batch_id)');
    }

    public function testAReplayStillReturnsTheInitialResultAfterAFurtherReplacementHasHappened(): void
    {
        $requestId = $this->approvedByBoth();
        $operation = Uuid::v7()->toRfc4122();
        $this->replace($requestId, $operation);
        $this->clock->sleep(3601);
        $this->replace($requestId);
        $counts = $this->counts();

        $replay = $this->replace($requestId, $operation);

        self::assertTrue($replay->replayed);
        self::assertSame(1, $replay->sequence, 'le résultat du premier envoi, pas celui du dernier remplacement');
        self::assertSame($counts, $this->counts());
    }

    public function testAnOperationIdentifierAlreadyUsedByAnotherCommuneOrAnotherKindOfRevisionIsRefused(): void
    {
        $requestId = $this->approvedByBoth();
        $operation = Uuid::v7()->toRfc4122();
        $this->replace($requestId, $operation);
        $counts = $this->counts();

        try {
            $this->replace($requestId, $operation, agent: $this->partnerAdmin, commune: $this->partner);
            self::fail('Un identifiant d\'opération d\'une autre commune ne doit pas être rejoué.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('déjà été utilisé', $e->getMessage());
        }
        $this->sql("UPDATE permit_revision SET type = 'VEHICLE_ADDED' WHERE batch_id = :b", ['b' => Uuid::fromString($operation)->toBinary()]);
        $this->expectException(DomainRuleViolation::class);
        try {
            $this->replace($requestId, $operation);
        } finally {
            self::assertSame($counts, $this->counts());
        }
    }

    public function testAnIdentifierOfAnotherPermitHasNoEffectOnThisOne(): void
    {
        $first = $this->approvedByBoth();
        $operation = Uuid::v7()->toRfc4122();
        $this->replace($first, $operation);
        $second = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Paul', null, 'paul@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['QQ-555-QQ'], [$this->organizerZone->id()->toRfc4122()], true), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $second, ApprovalDuration::fixed(DurationMode::H72));

        $result = $this->replace($second, $operation);

        self::assertFalse($result->replayed, 'un identifiant n\'a d\'effet que dans son laissez-passer');
        self::assertSame(2, $this->rows('permit_revision'));
    }

    public function testAnIncidentLeavesNothingWrittenAndTheSameOperationCanBeRetriedExactlyOnce(): void
    {
        $requestId = $this->approvedByBoth();
        $old = $this->activeToken();
        $operation = Uuid::v7()->toRfc4122();
        $before = $this->counts();

        try {
            $this->replace($requestId, $operation, service: $this->replacer(new FailingAuditTrail(self::getContainer()->get(AuditTrail::class), 1)));
            self::fail('Le journal d\'audit en échec doit faire échouer toute l\'opération.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        self::assertSame($before, $this->counts(), 'ni révision, ni jeton, ni audit, ni notification');
        self::assertSame($old, $this->activeToken(), 'l\'ancien QR est toujours le seul actif');
        self::assertSame(PublicVerificationOutcome::Found, $this->outcome($old));
    }

    public function testTheRetryAfterAnIncidentInANewProcessReplacesOnceWithTheSameOperationIdentifier(): void
    {
        $requestId = $this->approvedByBoth();
        $old = $this->activeToken();
        $operation = Uuid::v7()->toRfc4122();
        try {
            $this->replace($requestId, $operation, service: $this->replacer(new FailingAuditTrail(self::getContainer()->get(AuditTrail::class), 1)));
            self::fail('Incident attendu.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        // la reprise est une NOUVELLE requête (nouveau processus) avec le même identifiant d'opération
        $arguments = [$this->adminOnly->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $requestId, 'replace', json_encode(['operation' => $operation], \JSON_THROW_ON_ERROR)];
        $results = \App\Tests\Support\ParallelPhp::run('token-replace.php', [$arguments]);
        $retry = json_decode($results[0]['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $again = json_decode(\App\Tests\Support\ParallelPhp::run('token-replace.php', [$arguments])[0]['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('REPLACED:1:done', $retry['outcome'] ?? json_encode($retry));
        self::assertSame('REPLACED:1:replayed', $again['outcome'] ?? json_encode($again));
        self::assertNotSame($old, $this->activeToken());
        self::assertSame(2, $this->counts()['tokens']);
        self::assertSame(1, $this->counts()['revisions']);
    }

    // ------------------------------------------------------------------ habilitations

    public function testOnlyAMunicipalityAdminCanReplaceTheQr(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        foreach ([[$this->instructor, $this->organizer], [$this->managerOnly, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            try {
                $this->replace($requestId, agent: $agent, commune: $commune);
                self::fail('Rôle insuffisant accepté.');
            } catch (PermitAccessDenied) {
                self::assertSame($before, $this->counts());
            }
        }
        $this->replace($requestId);
        $this->clock->sleep(3601);
        $this->replace($requestId, agent: $this->partnerAdmin, commune: $this->partner);
        self::assertSame(2, $this->counts()['revisions'], 'l\'administrateur de chaque commune titulaire peut remplacer');
    }

    public function testAnAdminOfACommuneWithoutAuthorizationOrAForgedRequestGetsTheUniformRefusal(): void
    {
        $requestId = $this->approvedByBoth();
        $third = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Paul', null, 'paul@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['QQ-555-QQ'], [$this->partnerZone->id()->toRfc4122()], true), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $third, ApprovalDuration::fixed(DurationMode::H72));
        $before = $this->counts();

        foreach ([$third, 'pas-un-uuid', '0199aaaa-0000-7000-8000-000000000000'] as $forged) {
            try {
                $this->replace($forged);
                self::fail('Refus uniforme attendu.');
            } catch (OperationNotAllowed) {
                self::assertSame($before, $this->counts());
            }
        }
        self::assertNotEmpty($requestId);
    }

    /** @return iterable<string, array{string}> */
    public static function inactiveParticipations(): iterable
    {
        yield 'suspendue' => ['SUSPENDED'];
        yield 'clôturée' => ['CLOSED'];
    }

    #[DataProvider('inactiveParticipations')]
    public function testASuspendedOrClosedParticipationRefusesTheReplacement(string $status): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE event_participation SET status = :s WHERE municipality_id = :m', ['s' => $status, 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        $this->expectException(PermitAccessUnavailable::class);
        try {
            $this->replace($requestId);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testARevokedAuthorizationCannotReplaceButTheOtherCommuneCan(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        try {
            $this->replace($requestId);
            self::fail('Refus attendu.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('révoquée', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
        $this->replace($requestId, agent: $this->partnerAdmin, commune: $this->partner);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PERMIT_TOKEN_REPLACED_BY_OTHER' AND municipality_id = :m", ['m' => $this->organizer->id()->toBinary()]), 'une commune titulaire révoquée reçoit aussi la ligne neutre');
    }

    // ------------------------------------------------------------------ états de l'événement

    public function testASuspendedEventAndAnExpiredPeriodAllowTheReplacementAndAClosedEventRefusesIt(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $this->replace($requestId);
        self::assertSame(1, $this->counts()['revisions'], 'événement suspendu : autorisé');

        $this->sql("UPDATE event_event SET status = 'ACTIVE'");
        $this->sql("UPDATE permit_authorization SET valid_until = '2026-06-12 08:00:00'");
        $this->clock->sleep(3601);
        $this->replace($requestId);
        self::assertSame(2, $this->counts()['revisions'], 'période d\'autorisation expirée : autorisé');

        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $before = $this->counts();
        $this->clock->sleep(3601);
        try {
            $this->replace($requestId);
            self::fail('Événement clos : refus attendu.');
        } catch (\App\Shared\Domain\ClosedEventAccess $e) { // LOT 05.6.2 : la garde de clôture refuse avant la règle de remplacement
            self::assertStringContainsString('clos', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
    }

    // ------------------------------------------------------------------ motifs, commentaire, confirmation

    public function testTheReasonIsMandatoryAndOtherNeedsACommentWithNoToken(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->activeToken();
        $before = $this->counts();
        $cases = [
            [['reason' => ''], 'motif du remplacement'],
            [['reason' => 'INCONNU'], 'motif du remplacement'],
            [['reason' => 'OTHER'], 'commentaire est obligatoire'],
            [['reason' => 'LOST', 'comment' => str_repeat('x', 201)], '200'],
            [['reason' => 'LOST', 'comment' => 'le QR est '.$token], 'aucun jeton'],
            [['reason' => 'LOST', 'comment' => strtoupper(implode(' ', str_split($token, 8)))], 'aucun jeton'],
            [['reason' => 'LOST', 'comment' => 'https://exemple.fr/v/abcdef'], 'aucun jeton'],
            [['reason' => 'LOST', 'confirm' => false], 'Confirmez'],
        ];
        foreach ($cases as [$arguments, $message]) {
            try {
                $this->replace($requestId, ...$arguments);
                self::fail('Refus attendu : '.$message);
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertStringNotContainsString($token, $e->getMessage(), 'jamais le jeton recopié dans une erreur');
                self::assertSame($before, $this->counts());
            }
        }
        try {
            $this->replace($requestId, 'pas-un-uuid');
            self::fail('Identifiant d\'opération invalide accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('Identifiant d\'opération', $e->getMessage());
        }
        $this->replace($requestId, reason: 'SHARED_OR_PUBLISHED');
        self::assertSame(1, $this->counts()['revisions']);
    }

    // ------------------------------------------------------------------ remplacement rapproché, signalement

    public function testASecondReplacementWithinTheHourNeedsTheRetypedReferenceButIsNeverRefusedOtherwise(): void
    {
        $requestId = $this->approvedByBoth();
        $reference = (string) $this->em->getConnection()->fetchOne('SELECT reference FROM permit_permit');
        $this->replace($requestId);
        $before = $this->counts();

        foreach ([null, '', 'LP-0000-0000'] as $typed) {
            try {
                $this->replace($requestId, reference: $typed);
                self::fail('Référence attendue.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('ressaisissez la référence', $e->getMessage());
                self::assertStringNotContainsString($reference, $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
        $result = $this->replace($requestId, reference: strtolower($reference), reason: 'SUSPECTED_MISUSE', agent: $this->partnerAdmin, commune: $this->partner);

        self::assertSame([2, true], [$result->sequence, $result->close], 'remplacement rapproché, mesuré par le serveur et tous agents confondus');
        self::assertSame([false, true], array_map(static fn (string $s): bool => true === json_decode($s, true)['close'], $this->em->getConnection()->fetchFirstColumn('SELECT after_state FROM permit_revision ORDER BY occurred_at, id')));
        $this->clock->sleep(3601);
        self::assertFalse($this->replace($requestId)->close, 'au-delà d\'une heure : plus de référence à ressaisir');
    }

    public function testThreeReplacementsInADayAreFlaggedUnusualInTheAuditAndTheLogWithoutBeingBlocked(): void
    {
        $requestId = $this->approvedByBoth();
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $results = [];
        foreach (range(1, 3) as $i) {
            $results[] = $this->replace($requestId, service: $this->replacer(logger: $logger));
            $this->clock->sleep(3601);
        }

        self::assertSame([false, false, true], array_map(static fn (TokenReplacementResult $r): bool => $r->unusual, $results));
        $flags = array_map(static fn (string $j): bool => json_decode($j, true)['unusual'], $this->em->getConnection()->fetchFirstColumn("SELECT new_value FROM audit_log WHERE action = 'PERMIT_TOKEN_REPLACED' ORDER BY occurred_at, id"));
        self::assertSame([false, false, true], $flags);
        self::assertCount(1, $logger->records);
        self::assertSame('permit.token_replacement.unusual', $logger->records[0][0]);
        self::assertSame(3, $logger->records[0][1]['sequence']);
        foreach ($this->tokens() as $token) {
            self::assertStringNotContainsString($token['token'], (string) json_encode($logger->records), 'jamais un jeton dans le journal');
        }
        self::assertSame(3, $this->counts()['revisions'], 'aucun blocage');
    }

    // ------------------------------------------------------------------ collisions

    public function testACollisionDrawsAgainAndNeverReusesAnExistingToken(): void
    {
        $requestId = $this->approvedByBoth();
        $existing = $this->activeToken();
        $draws = [$existing, $existing, \App\Permit\Domain\VerificationToken::generate()];
        $generator = static function () use (&$draws): string {
            return array_shift($draws) ?? throw new \LogicException('trop de tirages');
        };

        $this->replace($requestId, service: $this->replacer(generator: $generator));

        self::assertNotSame($existing, $this->activeToken());
        self::assertSame([], $draws, 'deux collisions simulées, puis un jeton libre');
    }

    public function testPersistentCollisionsFailWithoutAnyWriteAndWithoutLeakingTheToken(): void
    {
        $requestId = $this->approvedByBoth();
        $existing = $this->activeToken();
        $before = $this->counts();

        try {
            $this->replace($requestId, service: $this->replacer(generator: static fn (): string => $existing));
            self::fail('Échec attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($existing, $e->getMessage());
            self::assertSame($before, $this->counts());
            self::assertSame($existing, $this->activeToken());
        }
    }

    // ------------------------------------------------------------------ historique, panneau

    public function testTheOtherCommuneSeesOnlyANeutralLineAndTheOwnCommuneSeesTheDetail(): void
    {
        $requestId = $this->approvedByBoth();
        $this->replace($requestId, reason: 'OTHER', comment: self::COMMENT);

        $own = $this->overview($this->adminOnly, $this->organizer, $requestId);
        $other = $this->overview($this->partnerAdmin, $this->partner, $requestId);

        self::assertSame(['QR code remplacé'], array_map(static fn ($l): string => $l->label, $own->history));
        self::assertSame(['QR code remplacé'], array_map(static fn ($l): string => $l->label, $other->history));
        self::assertTrue($own->history[0]->own);
        self::assertSame('Autre motif', $own->history[0]->reasonLabel);
        self::assertSame(self::COMMENT, $own->history[0]->comment);
        self::assertSame(1, $own->history[0]->token['sequence'] ?? null);
        self::assertFalse($other->history[0]->own);
        self::assertNull($other->history[0]->reasonLabel);
        self::assertNull($other->history[0]->comment);
        self::assertNull($other->history[0]->actorName);
        self::assertNull($other->history[0]->token);
        foreach ([self::COMMENT, 'OTHER', 'Autre motif'] as $leak) {
            self::assertStringNotContainsString($leak, serialize($other->history), 'aucune fuite intercommunale : '.$leak);
        }
        self::assertSame($this->organizer->name(), $other->history[0]->municipalityName);
    }

    public function testThePanelOffersTheReplacementOnlyToAnAdminWhileTheEventIsNotClosed(): void
    {
        $requestId = $this->approvedByBoth();

        $admin = $this->overview($this->adminOnly, $this->organizer, $requestId)->qr ?? self::fail();
        self::assertTrue($admin->canReplace);
        self::assertSame([$this->partner->name()], $admin->otherMunicipalities, 'la portée globale est annoncée');
        self::assertFalse($admin->closeToLast);
        $instructor = $this->overview($this->instructor, $this->organizer, $requestId)->qr ?? self::fail();
        self::assertFalse($instructor->canReplace);
        self::assertStringContainsString('administrateurs', $instructor->blockedReason);

        $this->replace($requestId);
        $after = $this->overview($this->adminOnly, $this->organizer, $requestId)->qr ?? self::fail();
        self::assertTrue($after->closeToLast, 'la référence devra être ressaisie');
        self::assertSame(1, $after->replacementCount);
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        self::assertTrue(($this->overview($this->adminOnly, $this->organizer, $requestId)->qr ?? self::fail())->canReplace);
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        // LOT 05.6.2 : un événement clos ne sert plus aucun panneau (garde de clôture, 410 en HTTP).
        $this->expectException(\App\Shared\Domain\ClosedEventAccess::class);
        $this->overview($this->adminOnly, $this->organizer, $requestId);
    }
}
