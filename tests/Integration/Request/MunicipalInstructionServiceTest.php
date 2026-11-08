<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Request\Application\ApprovedPartListener;
use App\Request\Application\InstructorRoleRequired;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\DurationMode;
use App\Request\Domain\PermitRequestDecision;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\TimeFactory as T;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('requires-request-schema')]
final class MunicipalInstructionServiceTest extends MunicipalDecisionTestCase
{
    private function approve(string $requestId, ?ApprovalDuration $duration = null, bool $asPartner = false): \App\Request\Application\Result\MunicipalDecisionResult
    {
        [$user, $municipality] = $asPartner ? [$this->partnerInstructor, $this->partner] : [$this->instructor, $this->organizer];

        return $this->instruction()->approve($this->managed($user), $municipality->id()->toRfc4122(), $requestId, $duration ?? ApprovalDuration::fixed(DurationMode::H24));
    }

    private function refuse(string $requestId, string $code = 'duplicate', ?string $comment = null, bool $asPartner = false): \App\Request\Application\Result\MunicipalDecisionResult
    {
        [$user, $municipality] = $asPartner ? [$this->partnerInstructor, $this->partner] : [$this->instructor, $this->organizer];

        return $this->instruction()->refuse($this->managed($user), $municipality->id()->toRfc4122(), $requestId, $code, $comment);
    }

    private function managed(\App\Identity\Domain\User $user): \App\Identity\Domain\User
    {
        return $this->em->find(\App\Identity\Domain\User::class, $user->id()) ?? $user;
    }

    private function assertNothingDecided(string $requestId): void
    {
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame([], $this->decisionAudits());
        self::assertSame('PENDING', $this->partStatus($requestId, $this->organizer));
    }

    /** Passe la zone de l'organisatrice en « périodes spécifiques » avec un unique créneau (9 h – 17 h, heure de Paris). */
    private function specificSlot(string $date): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);
        $zone = $this->em->find(\App\Event\Domain\EventZone::class, $this->organizerZone->id());
        $this->save(EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($date), T::time('09:00'), T::time('17:00')), \App\Tests\Support\EventFixtures::now()));
    }

    // ---------------------------------------------------------------- Décisions

    public function testApprovalOfAPendingPartRecordsAnImmutableDecisionWithItsWindowAndAudit(): void
    {
        $requestId = $this->submit();
        $result = $this->approve($requestId, ApprovalDuration::fixed(DurationMode::H48));

        self::assertSame(DecisionOutcome::Approved, $result->outcome);
        self::assertSame('APPROVED', $this->partStatus($requestId, $this->organizer));
        $row = $this->decisionRow($requestId, $this->organizer);
        self::assertNotNull($row);
        self::assertSame(['APPROVED', 'INSTRUCTION', 'H48'], [$row['outcome'], $row['path'], $row['duration_mode']]);
        self::assertSame(bin2hex($this->instructor->id()->toBinary()), bin2hex($row['decided_by_user_id']));
        self::assertSame(bin2hex($this->organizer->id()->toBinary()), bin2hex($row['municipality_id']));
        self::assertSame('2026-06-12 09:00:00', $row['decided_at']);
        self::assertSame('2026-06-12 09:00:00', $row['valid_from']);
        self::assertSame('2026-06-14 09:00:00', $row['valid_until']);
        self::assertNull($row['refusal_reason_id']);

        $audits = $this->decisionAudits();
        self::assertCount(1, $audits);
        self::assertSame('REQUEST_APPROVED', $audits[0]['action']);
        self::assertSame(bin2hex($this->organizer->id()->toBinary()), $audits[0]['municipality']);
        self::assertSame(bin2hex($this->instructor->id()->toBinary()), $audits[0]['actor']);
        self::assertSame(['status' => 'PENDING'], $audits[0]['previous']);
        $keys = array_keys($audits[0]['new']);
        sort($keys);
        self::assertSame(['decision', 'durationMode', 'event', 'path', 'reference', 'status', 'validFrom', 'validUntil', 'zones'], $keys);
        self::assertSame('2026-06-14T09:00:00Z', $audits[0]['new']['validUntil']);
        self::assertStringNotContainsString('Martin', (string) json_encode($audits));
    }

    public function testApprovalCoversEveryRequestedZoneOfTheMunicipality(): void
    {
        $second = \App\Tests\Support\EventFixtures::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $requestId = $this->submit([$this->organizerZone, $second]);

        $this->approve($requestId);

        self::assertSame(2, $this->decisionAudits()[0]['new']['zones']);
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    public function testRefusalRecordsReasonSnapshotAndKeepsTheFreeCommentOutOfTheAudit(): void
    {
        $requestId = $this->submit();
        $result = $this->refuse($requestId, 'reason_not_justified', 'Le justificatif de domicile est manquant, Mme Martin.');

        self::assertSame(DecisionOutcome::Refused, $result->outcome);
        self::assertNull($result->validUntil);
        self::assertSame('REFUSED', $this->partStatus($requestId, $this->organizer));
        $row = $this->decisionRow($requestId, $this->organizer);
        self::assertNotNull($row);
        self::assertSame(['REFUSED', 'reason_not_justified', 'Motif de la demande non justifié ou non vérifiable', 'Le justificatif de domicile est manquant, Mme Martin.'], [$row['outcome'], $row['refusal_reason_code'], $row['refusal_reason_label'], $row['refusal_comment']]);
        self::assertNull($row['duration_mode']);
        self::assertNull($row['valid_from']);
        $audits = $this->decisionAudits();
        self::assertSame('REQUEST_REFUSED', $audits[0]['action']);
        self::assertSame('reason_not_justified', $audits[0]['new']['reasonCode']);
        self::assertStringNotContainsString('justificatif', (string) json_encode($audits));
        self::assertStringNotContainsString('Martin', (string) json_encode($audits));
    }

    public function testDoubleApprovalIsAControlledConflictWithoutExtraDecisionOrAudit(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId);

        try {
            $this->approve($requestId, ApprovalDuration::fixed(DurationMode::H72));
            self::fail('Une seconde approbation doit être refusée.');
        } catch (OperationConflict $conflict) {
            self::assertSame(ConflictCause::StaleState, $conflict->cause);
        }
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertCount(1, $this->decisionAudits());
        self::assertSame('H24', $this->decisionRow($requestId, $this->organizer)['duration_mode'] ?? null);
    }

    public function testDoubleRefusalIsAControlledConflict(): void
    {
        $requestId = $this->submit();
        $this->refuse($requestId);

        $this->expectException(OperationConflict::class);
        try {
            $this->refuse($requestId, 'other', 'Autre cause');
        } finally {
            self::assertSame(1, $this->rows('request_permit_request_decision'));
            self::assertCount(1, $this->decisionAudits());
        }
    }

    public function testApprovalAfterRefusalIsImpossible(): void
    {
        $requestId = $this->submit();
        $this->refuse($requestId);

        try {
            $this->approve($requestId);
            self::fail('Une demande refusée ne s\'approuve pas.');
        } catch (OperationConflict) {
        }
        self::assertSame('REFUSED', $this->partStatus($requestId, $this->organizer));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame('REFUSED', $this->decisionRow($requestId, $this->organizer)['outcome'] ?? null);
    }

    public function testRefusalAfterApprovalIsImpossible(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId);

        try {
            $this->refuse($requestId);
            self::fail('Une demande approuvée ne se refuse pas.');
        } catch (OperationConflict) {
        }
        self::assertSame('APPROVED', $this->partStatus($requestId, $this->organizer));
        self::assertSame('APPROVED', $this->decisionRow($requestId, $this->organizer)['outcome'] ?? null);
    }

    public function testMunicipalitiesDecideIndependentlyAndNeverSeeOrAffectEachOther(): void
    {
        $requestId = $this->submit();
        $this->refuse($requestId, 'duplicate');
        $this->approve($requestId, ApprovalDuration::untilClosure(), asPartner: true);

        self::assertSame('REFUSED', $this->partStatus($requestId, $this->organizer));
        self::assertSame('APPROVED', $this->partStatus($requestId, $this->partner));
        self::assertSame(2, $this->rows('request_permit_request_decision'));
        $audits = $this->decisionAudits();
        self::assertSame([bin2hex($this->organizer->id()->toBinary()), bin2hex($this->partner->id()->toBinary())], array_column($audits, 'municipality'));
        // Chaque ligne d'audit ne décrit que SA commune (aucune zone ni décision de l'autre).
        self::assertSame(1, $audits[0]['new']['zones']);
        self::assertArrayNotHasKey('reasonCode', $audits[1]['new']);
    }

    public function testOneApprovalDoesNotChangeTheOtherMunicipalityPendingPart(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId);

        self::assertSame('PENDING', $this->partStatus($requestId, $this->partner));
        self::assertNull($this->decisionRow($requestId, $this->partner));
    }

    public function testTheDatabaseItselfRejectsASecondDecisionOnTheSamePart(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId);
        $existing = $this->decisionRow($requestId, $this->organizer);
        self::assertNotNull($existing);

        $this->expectException(ConstraintViolationException::class);
        $this->em->getConnection()->insert('request_permit_request_decision', [
            'id' => Uuid::v7()->toBinary(), 'outcome' => 'APPROVED', 'path' => 'INSTRUCTION', 'duration_mode' => 'H24', 'valid_from' => '2026-06-12 09:00:00',
            'valid_until' => '2026-06-13 09:00:00', 'decided_at' => '2026-06-12 09:00:00', 'municipality_id' => $existing['municipality_id'],
            'request_municipality_id' => $existing['request_municipality_id'], 'decided_by_user_id' => $existing['decided_by_user_id'],
        ]);
    }

    public function testTheDatabaseRejectsAnIncoherentDecisionRow(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId, ApprovalDuration::untilClosure(), asPartner: true);
        $existing = $this->decisionRow($requestId, $this->partner);
        self::assertNotNull($existing);

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        // « Jusqu'à clôture » avec une date de fin : refusé par le CHECK, même en SQL direct.
        $this->em->getConnection()->executeStatement('UPDATE request_permit_request_decision SET valid_until = \'2026-06-30 00:00:00\' WHERE id = :id', ['id' => $existing['id']]);
    }

    public function testApprovalNotifiesTheLot05HookOncePerApprovalAndNeverOnRefusal(): void
    {
        $listener = new class () implements ApprovedPartListener {
            /** @var list<PermitRequestDecision> */
            public array $approved = [];

            public function partApproved(PermitRequestDecision $decision): void
            {
                $this->approved[] = $decision;
            }
        };
        $requestId = $this->submit();
        $service = $this->instruction(listener: $listener);
        $service->approve($this->instructor, $this->organizer->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));
        $service->approve($this->partnerInstructor, $this->partner->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));

        self::assertCount(2, $listener->approved);
        self::assertNotSame($listener->approved[0]->id()->toRfc4122(), $listener->approved[1]->id()->toRfc4122());

        $listener->approved = [];
        $other = $this->submit([$this->organizerZone]);
        $this->refuse($other);
        self::assertSame([], $listener->approved);
    }

    // ---------------------------------------------------------------- Durées

    #[DataProvider('fixedDurations')]
    public function testFixedDurationsAreStoredExactly(DurationMode $mode, string $until): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->approve($requestId, ApprovalDuration::fixed($mode));

        self::assertSame($until, $this->decisionRow($requestId, $this->organizer)['valid_until'] ?? null);
    }

    /**
     * @return iterable<string, array{DurationMode, string}>
     */
    public static function fixedDurations(): iterable
    {
        yield 'H24' => [DurationMode::H24, '2026-06-13 09:00:00'];
        yield 'H48' => [DurationMode::H48, '2026-06-14 09:00:00'];
        yield 'H72' => [DurationMode::H72, '2026-06-15 09:00:00'];
    }

    public function testCustomEndIsConvertedFromTheEventTimezoneAndStoredInUtc(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->approve($requestId, ApprovalDuration::custom('2026-06-15', '18:30'));

        $row = $this->decisionRow($requestId, $this->organizer);
        self::assertSame(['CUSTOM', '2026-06-15 16:30:00'], [$row['duration_mode'] ?? null, $row['valid_until'] ?? null]);
    }

    public function testUntilClosureStoresNoEndDate(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->approve($requestId, ApprovalDuration::untilClosure());

        $row = $this->decisionRow($requestId, $this->organizer);
        self::assertNotNull($row);
        self::assertSame('UNTIL_CLOSURE', $row['duration_mode']);
        self::assertNull($row['valid_until']);
    }

    #[DataProvider('invalidCustomEnds')]
    public function testInvalidCustomEndWritesNothingAndKeepsThePartPending(string $date, string $time): void
    {
        $requestId = $this->submit([$this->organizerZone]);

        try {
            $this->approve($requestId, ApprovalDuration::custom($date, $time));
            self::fail('Fin personnalisée invalide acceptée.');
        } catch (DomainRuleViolation) {
        }
        $this->assertNothingDecided($requestId);
        // La part reste décidable : un refus ou une approbation valide reste possible.
        self::assertSame('APPROVED', $this->approve($requestId)->outcome->value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCustomEnds(): iterable
    {
        yield 'égale à la décision' => ['2026-06-12', '11:00'];
        yield 'antérieure à la décision' => ['2026-06-11', '10:00'];
        yield 'au-delà de l\'événement' => ['2026-06-21', '10:00'];
        yield 'heure inexistante' => ['2026-03-29', '02:30'];
    }

    public function testASlotStartingAfterTheDecisionDoesNotBlockTheApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone]); // déposée avant : le dépôt utilise la vraie horloge
        $this->specificSlot('2026-06-18');

        // Fenêtre « jusqu'à clôture » : un créneau futur de l'événement est compatible (règle par durée : MunicipalApprovalWindowCompatibilityTest).
        self::assertSame(DecisionOutcome::Approved, $this->approve($requestId, ApprovalDuration::untilClosure())->outcome);
    }

    // ---------------------------------------------------------------- Zones suspendues ou inutilisables

    public function testASuspendedZoneBlocksTheApprovalEntirelyButNotTheRefusal(): void
    {
        $second = \App\Tests\Support\EventFixtures::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $requestId = $this->submit([$this->organizerZone, $second]);
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $second->id()->toBinary()]);

        try {
            $this->approve($requestId);
            self::fail('Aucune approbation si une zone est suspendue.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('suspendue', $e->getMessage());
        }
        $this->assertNothingDecided($requestId);
        self::assertSame(DecisionOutcome::Refused, $this->refuse($requestId, 'zone_not_concerned')->outcome);
    }

    public function testADeletedZoneBlocksTheApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->sql('UPDATE event_zone SET deleted_at = NOW() WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->approve($requestId);
        } finally {
            $this->assertNothingDecided($requestId);
        }
    }

    public function testAZoneWhoseOnlySlotIsExpiredBlocksTheApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->specificSlot('2026-06-11');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('aucune période d\'accès dans la durée');
        try {
            $this->approve($requestId);
        } finally {
            $this->assertNothingDecided($requestId);
        }
    }

    public function testAnEventWhoseWholeDurationIsOverBlocksTheApprovalOfItsZones(): void
    {
        $requestId = $this->submit([$this->organizerZone]);

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->instruction(clock: new \Symfony\Component\Clock\MockClock('2026-06-21 09:00:00 UTC'))
                ->approve($this->instructor, $this->organizer->id()->toRfc4122(), $requestId, ApprovalDuration::untilClosure());
        } finally {
            $this->assertNothingDecided($requestId);
        }
    }

    // ---------------------------------------------------------------- Motifs de refus

    #[DataProvider('invalidReasons')]
    public function testARefusalNeedsAnActiveReasonAndTheRequiredComment(string $code, ?string $comment, bool $deactivate): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        if ($deactivate) {
            $this->sql('UPDATE request_refusal_reason SET active = 0 WHERE code = :code', ['code' => $code]);
        }

        try {
            $this->refuse($requestId, $code, $comment);
            self::fail('Refus invalide accepté.');
        } catch (DomainRuleViolation) {
        }
        $this->assertNothingDecided($requestId);
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function invalidReasons(): iterable
    {
        yield 'motif vide' => ['', null, false];
        yield 'motif inconnu' => ['inexistant', null, false];
        yield 'motif inactif' => ['duplicate', null, true];
        yield '« Autre » sans commentaire' => ['other', null, false];
        yield '« Autre » avec commentaire blanc' => ['other', "  \n ", false];
        yield 'commentaire trop long' => ['duplicate', str_repeat('x', 501), false];
    }

    public function testOtherWithACommentIsAcceptedAndBlankCommentIsOptionalElsewhere(): void
    {
        $this->refuse($this->submit([$this->organizerZone]), 'other', 'Précision utile à la commune');
        $second = $this->submit([$this->organizerZone]);
        $this->refuse($second, 'duplicate', '   ');

        $row = $this->decisionRow($second, $this->organizer);
        self::assertNotNull($row);
        self::assertNull($row['refusal_comment']);
    }

    public function testTheReasonLabelIsHistorisedWhenTheReferentialChanges(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->refuse($requestId, 'duplicate');
        $this->sql('UPDATE request_refusal_reason SET label = \'Doublon (renommé)\', active = 0 WHERE code = \'duplicate\'');

        $row = $this->decisionRow($requestId, $this->organizer);
        self::assertSame('Demande en doublon', $row['refusal_reason_label'] ?? null);
        self::assertSame('duplicate', $row['refusal_reason_code'] ?? null);
    }

    // ---------------------------------------------------------------- Sécurité

    public function testOnlyAnInstructorOfTheMunicipalityMayDecide(): void
    {
        $requestId = $this->submit([$this->organizerZone]);

        self::assertSame(DecisionOutcome::Approved, $this->approve($requestId)->outcome);
    }

    public function testAdministratorWithoutTheInstructorRoleCannotDecide(): void
    {
        $this->assertRoleRefused($this->adminOnly);
    }

    public function testEventManagerWithoutTheInstructorRoleCannotDecide(): void
    {
        $this->assertRoleRefused($this->managerOnly);
    }

    private function assertRoleRefused(\App\Identity\Domain\User $user): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $organizer = $this->organizer->id()->toRfc4122();
        try {
            $this->instruction()->approve($user, $organizer, $requestId, ApprovalDuration::fixed(DurationMode::H24));
            self::fail('Rôle INSTRUCTOR exigé.');
        } catch (InstructorRoleRequired) {
        }
        try {
            $this->instruction()->refuse($user, $organizer, $requestId, 'duplicate', null);
            self::fail('Rôle INSTRUCTOR exigé.');
        } catch (InstructorRoleRequired) {
        }
        $this->assertNothingDecided($requestId);
    }

    public function testAnotherMunicipalityCannotDecideAndLearnsNothing(): void
    {
        $requestId = $this->submit([$this->organizerZone]); // dossier concernant la seule commune organisatrice
        $forged = [
            'commune étrangère, identifiant de commune forgé' => [$this->outsiderInstructor, $this->organizer->id()->toRfc4122(), $requestId],
            'commune partenaire, identifiant de commune forgé' => [$this->partnerInstructor, $this->organizer->id()->toRfc4122(), $requestId],
            'commune propre, dossier étranger (aucune part)' => [$this->partnerInstructor, $this->partner->id()->toRfc4122(), $requestId],
            'dossier inexistant' => [$this->instructor, $this->organizer->id()->toRfc4122(), Uuid::v7()->toRfc4122()],
            'identifiant de dossier invalide' => [$this->instructor, $this->organizer->id()->toRfc4122(), 'pas-un-uuid'],
            'identifiant de commune invalide' => [$this->instructor, 'pas-un-uuid', $requestId],
        ];
        $messages = [];
        foreach ($forged as $label => [$user, $municipalityId, $request]) {
            foreach ([
                fn () => $this->instruction()->approve($user, $municipalityId, $request, ApprovalDuration::fixed(DurationMode::H24)),
                fn () => $this->instruction()->refuse($user, $municipalityId, $request, 'duplicate', null),
            ] as $call) {
                try {
                    $call();
                    self::fail($label.' : décision acceptée.');
                } catch (OperationNotAllowed $e) {
                    $messages[$label][] = $e->getMessage();
                }
            }
        }
        // Refus uniformes par catégorie : commune inaccessible, ou ressource inaccessible (jamais « existe mais interdit »).
        self::assertSame(['Commune inaccessible.', 'Commune inaccessible.'], $messages['commune étrangère, identifiant de commune forgé']);
        self::assertSame(['Ressource inaccessible.', 'Ressource inaccessible.'], $messages['dossier inexistant']);
        self::assertSame($messages['dossier inexistant'], $messages['commune propre, dossier étranger (aucune part)']);
        $this->assertNothingDecided($requestId);
    }

    public function testAMunicipalityDecisionNeverTouchesTheOtherPartEvenWithAForgedRequestSharedByBoth(): void
    {
        $requestId = $this->submit();
        $this->approve($requestId, asPartner: true);

        self::assertSame('PENDING', $this->partStatus($requestId, $this->organizer));
        self::assertNull($this->decisionRow($requestId, $this->organizer));
        self::assertSame(bin2hex($this->partnerInstructor->id()->toBinary()), bin2hex($this->decisionRow($requestId, $this->partner)['decided_by_user_id'] ?? ''));
    }

    #[DataProvider('nonActiveParticipations')]
    public function testAParticipationThatIsNotActiveCannotDecide(string $status): void
    {
        $requestId = $this->submit();
        $this->sql('UPDATE event_participation SET status = :status WHERE id = :id', ['status' => $status, 'id' => $this->partnerParticipation->id()->toBinary()]);

        foreach ([
            fn () => $this->instruction()->approve($this->partnerInstructor, $this->partner->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24)),
            fn () => $this->instruction()->refuse($this->partnerInstructor, $this->partner->id()->toRfc4122(), $requestId, 'duplicate', null),
        ] as $call) {
            try {
                $call();
                self::fail('Participation '.$status.' : décision acceptée.');
            } catch (OperationNotAllowed $e) {
                self::assertStringContainsString('opérationnel', $e->getMessage());
            }
        }
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame('PENDING', $this->partStatus($requestId, $this->partner));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonActiveParticipations(): iterable
    {
        yield 'INVITED' => ['INVITED'];
        yield 'SUSPENDED' => ['SUSPENDED'];
        yield 'CLOSED' => ['CLOSED'];
    }

    #[DataProvider('nonOperationalEvents')]
    public function testANonOperationalEventCannotBeDecided(string $status): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->sql('UPDATE event_event SET status = :status', ['status' => $status]);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->approve($requestId);
        } finally {
            self::assertSame(0, $this->rows('request_permit_request_decision'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonOperationalEvents(): iterable
    {
        yield 'SUSPENDED' => ['SUSPENDED'];
        yield 'CLOSED' => ['CLOSED'];
    }

    public function testASuspendedMunicipalityHasNoWriteAccess(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->sql('UPDATE municipality SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizer->id()->toBinary()]);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->approve($requestId);
        } finally {
            self::assertSame(0, $this->rows('request_permit_request_decision'));
        }
    }

    public function testADeactivatedInstructorMembershipCannotDecide(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $this->sql('UPDATE municipality_membership SET active = 0 WHERE user_id = :id', ['id' => $this->instructor->id()->toBinary()]);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->approve($requestId);
        } finally {
            self::assertSame(0, $this->rows('request_permit_request_decision'));
        }
    }

    // ---------------------------------------------------------------- Atomicité

    public function testAFailingAuditRollsBackTheDecisionAndTheStatus(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $audit = new FailingAuditTrail(self::getContainer()->get(\App\Shared\Application\AuditTrail::class), 1);

        try {
            $this->instruction($audit)->approve($this->instructor, $this->organizer->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));
            self::fail('L\'échec injecté aurait dû remonter.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }
        $this->assertNothingDecided($requestId);
    }

    public function testAFailingAuditAlsoRollsBackARefusal(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $audit = new FailingAuditTrail(self::getContainer()->get(\App\Shared\Application\AuditTrail::class), 1);

        try {
            $this->instruction($audit)->refuse($this->instructor, $this->organizer->id()->toRfc4122(), $requestId, 'duplicate', null);
            self::fail('L\'échec injecté aurait dû remonter.');
        } catch (\RuntimeException) {
        }
        $this->assertNothingDecided($requestId);
    }

    public function testAFailingLot05HookRollsBackTheApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $listener = new class () implements ApprovedPartListener {
            public function partApproved(PermitRequestDecision $decision): void
            {
                throw new \RuntimeException('Émission impossible.');
            }
        };

        try {
            $this->instruction(listener: $listener)->approve($this->instructor, $this->organizer->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));
            self::fail('L\'échec du point d\'intégration doit annuler l\'approbation.');
        } catch (\RuntimeException) {
        }
        $this->assertNothingDecided($requestId);
    }
}
