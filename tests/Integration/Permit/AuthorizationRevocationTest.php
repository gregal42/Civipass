<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Application\Result\RevocationOutcome;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Révocation d'une autorisation communale (LOT 05.5.1, DM-116 §2, DM-119 §4.1) : une commune ne révoque que la sienne, les autres gardent leurs
 * droits, la révocation est irréversible et idempotente, le QR ne change pas, le motif ne sort jamais (audit, public), la participation ACTIVE est
 * exigée, tout est écrit dans UNE transaction (autorisation, révision, audit, intention de notification). Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class AuthorizationRevocationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1) : test exécutable après la migration validée.');
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

    private function revocation(): AuthorizationRevocationService
    {
        $c = self::getContainer();

        return new AuthorizationRevocationService(
            $c->get(EventTransactions::class),
            $c->get(PermitRevisionGuard::class),
            $c->get(PermitRevisionRepository::class),
            $this->clock,
            $c->get(AuditTrail::class),
            $c->get(NotificationIntentRecorder::class),
        );
    }

    /** Dossier approuvé par les deux communes ; renvoie l'identifiant de la demande. */
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
            ['GH-482-KT', 'AB-123-CD'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        return $id;
    }

    private function revoke(User $agent, Municipality $commune, string $requestId, string $code = 'FALSE_STATEMENT', ?string $comment = null, bool $confirmed = true): RevocationOutcome
    {
        return $this->revocation()->revoke($this->managed($agent), $commune->id()->toRfc4122(), $requestId, $code, $comment, $confirmed);
    }

    private function token(): string
    {
        $this->em->clear();

        return ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
    }

    /** @return array{revisions: int, audits: int, outbox: int, revoked: int} */
    private function counts(): array
    {
        return [
            'revisions' => $this->rows('permit_revision'),
            'audits' => (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PERMIT_AUTHORIZATION_REVOKED'"),
            'outbox' => (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type = 'AUTHORIZATION_REVOKED'"),
            'revoked' => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL'),
        ];
    }

    // ------------------------------------------------------------------ révocation nominale

    public function testARevocationWritesTheRevisionTheAuditAndThePriorityNotificationInOneGo(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();

        $outcome = $this->revoke($this->instructor, $this->organizer, $requestId, 'OTHER', self::COMMENT);

        self::assertSame(RevocationOutcome::Revoked, $outcome);
        self::assertSame(['revisions' => 1, 'audits' => 1, 'outbox' => 1, 'revoked' => 1], $this->counts());
        $revision = $this->em->getConnection()->fetchAssociative('SELECT type, reason_code, reason_text, occurred_at, before_state, after_state, LOWER(HEX(municipality_id)) AS municipality, LOWER(HEX(actor_user_id)) AS actor FROM permit_revision');
        self::assertIsArray($revision);
        self::assertSame('AUTHORIZATION_REVOKED', $revision['type']);
        self::assertSame('OTHER', $revision['reason_code']);
        self::assertSame(self::COMMENT, $revision['reason_text']);
        self::assertSame('2026-06-12 09:00:00', $revision['occurred_at']);
        self::assertSame(str_replace('-', '', $this->organizer->id()->toRfc4122()), $revision['municipality']);
        self::assertSame(str_replace('-', '', $this->instructor->id()->toRfc4122()), $revision['actor']);
        $before = json_decode((string) $revision['before_state'], true);
        $after = json_decode((string) $revision['after_state'], true);
        self::assertNull($before['revokedAt']);
        self::assertSame('2026-06-12T09:00:00+00:00', $after['revokedAt']);
        self::assertSame($before['activeZones'], $after['activeZones'], 'la révocation ne retire aucune zone : elle rend l\'autorisation inopérante');
        foreach ([$revision['before_state'], $revision['after_state']] as $json) {
            foreach (['Martin', 'Camille', 'GH-482-KT', 'camille@exemple.fr', 'SENTINEL', $token, self::COMMENT] as $forbidden) {
                self::assertStringNotContainsString($forbidden, (string) $json, 'aucune donnée personnelle, aucun jeton dans les états');
            }
        }
        self::assertSame($token, $this->token(), 'le QR ne change pas');
    }

    public function testTheAuditCarriesTheReasonCodeButNeverTheCommentNorAnyPersonalData(): void
    {
        $requestId = $this->approvedByBoth();

        $this->revoke($this->instructor, $this->organizer, $requestId, 'OTHER', self::COMMENT);

        $audit = $this->em->getConnection()->fetchAssociative("SELECT new_value, LOWER(HEX(municipality_id)) AS municipality FROM audit_log WHERE action = 'PERMIT_AUTHORIZATION_REVOKED'");
        self::assertIsArray($audit);
        $data = json_decode((string) $audit['new_value'], true);
        self::assertSame('OTHER', $data['reason']);
        self::assertSame(str_replace('-', '', $this->organizer->id()->toRfc4122()), $audit['municipality'], 'audit dans la commune concernée');
        foreach (['Martin', 'Camille', 'GH-482-KT', 'camille@exemple.fr', self::COMMENT, $this->token()] as $forbidden) {
            self::assertStringNotContainsString($forbidden, (string) $audit['new_value']);
        }
    }

    public function testTheOtherCommunesKeepTheirRightsAndOnlyTheRevokedOneLosesItsOwn(): void
    {
        $requestId = $this->approvedByBoth();
        $this->revoke($this->instructor, $this->organizer, $requestId);
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();

        $rights = $this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at('2026-06-12 10:00:00'));

        $states = [];
        foreach ($rights->authorizations as $authorization) {
            foreach ($authorization->zones as $zone) {
                $states[$authorization->municipalityName] = $zone->state;
            }
        }
        self::assertSame(RightState::Revoked, $states[$this->organizer->name()]);
        self::assertSame(RightState::Valid, $states[$this->partner->name()]);
    }

    public function testThePublicReaderShowsTheRevocationImmediatelyWithTheSameQrAndNoReason(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $this->revoke($this->instructor, $this->organizer, $requestId, 'OTHER', self::COMMENT);
        $this->em->clear();

        $lookup = $this->service(PublicVerificationReader::class)->find($token, $this->at('2026-06-12 10:00:00'));

        self::assertSame(PublicVerificationOutcome::Found, $lookup->outcome);
        $v = $lookup->verification ?? self::fail();
        self::assertSame(PublicVerdict::ActiveRights, $v->verdict, 'des droits subsistent chez l\'autre commune');
        self::assertSame(1, $v->activeZoneCount);
        $revoked = array_values(array_filter($v->authorizations, static fn ($a): bool => $a->isRevoked()));
        self::assertCount(1, $revoked);
        self::assertSame($this->organizer->name(), $revoked[0]->municipalityName);
        self::assertStringNotContainsString(self::COMMENT, serialize($v));
        self::assertStringNotContainsString('FALSE_STATEMENT', serialize($v));
    }

    public function testRevokingEveryAuthorizationLeavesNoLiveRightAndTheLinkPageStillExists(): void
    {
        $requestId = $this->approvedByBoth();
        $this->revoke($this->instructor, $this->organizer, $requestId);
        $this->revoke($this->partnerInstructor, $this->partner, $requestId);
        $permit = $this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail();

        $rights = $this->service(PermitRightsEvaluator::class)->evaluate($permit, $this->at('2026-06-12 10:00:00'));

        self::assertFalse($rights->hasLiveRights());
        self::assertTrue($rights->isEntirelyRevoked());
    }

    // ------------------------------------------------------------------ validation

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'motif inconnu' => ['NOT_A_REASON', null, true];
        yield 'motif vide' => ['', null, true];
        yield 'motif en minuscules' => ['false_statement', null, true];
        yield 'Autre sans commentaire' => ['OTHER', null, true];
        yield 'Autre avec commentaire blanc' => ['OTHER', "   \n ", true];
        yield 'commentaire de 201 caractères' => ['DUPLICATE', str_repeat('é', 201), true];
        yield 'confirmation absente' => ['DUPLICATE', null, false];
    }

    #[DataProvider('invalidInputs')]
    public function testAnInvalidRequestWritesNothing(string $code, ?string $comment, bool $confirmed): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        try {
            $this->revoke($this->instructor, $this->organizer, $requestId, $code, $comment, $confirmed);
            self::fail('Une DomainRuleViolation est attendue.');
        } catch (DomainRuleViolation) {
            self::assertSame($before, $this->counts(), 'ni révocation, ni révision, ni audit, ni notification');
        }
    }

    public function testACommentOfExactlyTwoHundredCharactersIsAccepted(): void
    {
        $requestId = $this->approvedByBoth();

        self::assertSame(RevocationOutcome::Revoked, $this->revoke($this->instructor, $this->organizer, $requestId, 'ISSUED_IN_ERROR', str_repeat('é', 200)));

        self::assertSame(200, mb_strlen((string) $this->em->getConnection()->fetchOne('SELECT reason_text FROM permit_revision')));
    }

    public function testACommentIsOptionalForACodedReasonAndStoredAsNull(): void
    {
        $requestId = $this->approvedByBoth();

        $this->revoke($this->instructor, $this->organizer, $requestId, 'DUPLICATE', "  \n");

        self::assertNull($this->em->getConnection()->fetchOne('SELECT reason_text FROM permit_revision'));
    }

    // ------------------------------------------------------------------ périmètre et rôles

    public function testACommuneThatHasNoAuthorizationCannotRevokeAndGetsTheUniformRefusal(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->revoke($this->outsiderInstructor, $this->outsider, $requestId);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testAnAgentCannotUseAnotherCommunesIdentifierToRevokeItsAuthorization(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        $this->expectException(OperationNotAllowed::class); // l'instructeur de l'organisatrice n'a aucune adhésion à la commune partenaire
        try {
            $this->revoke($this->instructor, $this->partner, $requestId);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testAnEventManagerCannotRevoke(): void
    {
        $requestId = $this->approvedByBoth();

        $this->expectException(PermitAccessDenied::class);
        $this->revoke($this->managerOnly, $this->organizer, $requestId);
    }

    public function testAMunicipalityAdminCanRevoke(): void
    {
        $requestId = $this->approvedByBoth();

        self::assertSame(RevocationOutcome::Revoked, $this->revoke($this->adminOnly, $this->organizer, $requestId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownRequests(): iterable
    {
        yield 'identifiant mal formé' => ['pas-un-uuid'];
        yield 'identifiant inconnu' => ['0199aaaa-0000-7000-8000-000000000000'];
        yield 'vide' => [''];
    }

    #[DataProvider('unknownRequests')]
    public function testAnUnknownOrForgedRequestGetsTheUniformRefusal(string $requestId): void
    {
        $this->approvedByBoth();

        $this->expectException(OperationNotAllowed::class);
        $this->revoke($this->instructor, $this->organizer, $requestId);
    }

    public function testASuspendedParticipationCannotRevokeAndNothingIsWritten(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_participation SET status = 'SUSPENDED' WHERE municipality_id = :m", ['m' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        try {
            $this->revoke($this->instructor, $this->organizer, $requestId);
            self::fail('PermitAccessUnavailable attendue.');
        } catch (PermitAccessUnavailable) {
            self::assertSame($before, $this->counts());
        }
    }

    // ------------------------------------------------------------------ irréversibilité et idempotence

    public function testASecondRevocationIsAnIdempotentNoOp(): void
    {
        $requestId = $this->approvedByBoth();
        $this->revoke($this->instructor, $this->organizer, $requestId);
        $after = $this->counts();

        $second = $this->revoke($this->adminOnly, $this->organizer, $requestId, 'DUPLICATE');

        self::assertSame(RevocationOutcome::AlreadyRevoked, $second);
        self::assertSame($after, $this->counts(), 'aucune seconde révision, aucun second audit, aucune seconde notification');
    }

    public function testNoNewApprovalCanUndoARevocation(): void
    {
        $requestId = $this->approvedByBoth();
        $this->revoke($this->instructor, $this->organizer, $requestId);
        $revokedAt = $this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_authorization WHERE municipality_id = :m', ['m' => $this->organizer->id()->toBinary()]);

        try {
            $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H72));
            self::fail('Une nouvelle approbation de la même commune doit être refusée (part terminale).');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        self::assertSame($revokedAt, $this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_authorization WHERE municipality_id = :m', ['m' => $this->organizer->id()->toBinary()]));
        self::assertSame(2, $this->rows('permit_authorization'), 'aucune seconde autorisation pour la commune');
    }

    // ------------------------------------------------------------------ transaction

    public function testAFailureAfterTheRevocationRollsEverythingBack(): void
    {
        $requestId = $this->approvedByBoth();
        $c = self::getContainer();
        $failing = new \App\Tests\Support\FailingAuditTrail($c->get(AuditTrail::class), 1);
        $service = new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $failing, $c->get(NotificationIntentRecorder::class));
        $before = $this->counts();

        try {
            $service->revoke($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, 'DUPLICATE', null, true);
            self::fail('Le journal d\'audit en échec doit faire échouer la révocation.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->em->clear();
        self::assertSame($before, $this->counts(), 'rien n\'est écrit : ni autorisation révoquée, ni révision, ni notification');
    }

    // ------------------------------------------------------------------ historique : ligne neutre pour les autres communes

    public function testTheHistoryShowsDetailsToTheAuthorCommuneAndANeutralLineToTheOthers(): void
    {
        $requestId = $this->approvedByBoth();
        $this->revoke($this->instructor, $this->organizer, $requestId, 'OTHER', self::COMMENT);
        $reader = $this->service(PermitRightsOverviewReader::class);

        $own = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $other = $reader->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId);

        self::assertCount(1, $own->history);
        self::assertTrue($own->history[0]->own);
        self::assertSame('Autre motif', $own->history[0]->reasonLabel);
        self::assertSame(self::COMMENT, $own->history[0]->comment);
        self::assertNotNull($own->history[0]->actorName);
        self::assertCount(1, $other->history);
        $neutral = $other->history[0];
        self::assertFalse($neutral->own);
        self::assertSame('Autorisation révoquée', $neutral->label);
        self::assertSame($this->organizer->name(), $neutral->municipalityName);
        self::assertNull($neutral->reasonLabel);
        self::assertNull($neutral->comment);
        self::assertNull($neutral->actorName);
        self::assertStringNotContainsString(self::COMMENT, serialize($other), 'le commentaire d\'une autre commune n\'est jamais lu ni transmis');
        self::assertStringNotContainsString('Autre motif', serialize($other));
        self::assertFalse($other->isRevoked(), 'l\'autorisation de l\'autre commune n\'est pas touchée');
        self::assertTrue($other->canRevoke);
        self::assertFalse($own->canRevoke, 'déjà révoquée : définitive');
    }

    public function testTheOverviewIsRefusedToACommuneWithoutAuthorization(): void
    {
        $requestId = $this->approvedByBoth();

        $this->expectException(OperationNotAllowed::class);
        $this->service(PermitRightsOverviewReader::class)->overview($this->managed($this->outsiderInstructor), $this->outsider->id()->toRfc4122(), $requestId);
    }
}
