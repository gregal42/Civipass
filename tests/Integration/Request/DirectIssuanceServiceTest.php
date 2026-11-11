<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Request\Application\AgentRequestDenied;
use App\Request\Application\ApprovedPartListener;
use App\Request\Application\DirectIssuancePreview;
use App\Request\Application\DirectIssuanceReader;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\DurationMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\TimeFactory as T;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Délivrance directe (LOT 04.3) : une seule transaction crée et approuve, aucune part PENDING n'est écrite, rollback intégral à l'échec,
 * idempotence, durées, compatibilité des zones, permissions et confirmation réservée à l'agent émetteur. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class DirectIssuanceServiceTest extends MunicipalDecisionTestCase
{
    /**
     * @param list<EventZone>|null $zones
     */
    private function issue(User $agent, Municipality $municipality, ApprovalDuration $duration, ?array $zones = null, ?string $key = null): \App\Request\Application\Result\SubmittedRequest
    {
        return $this->issuer()->issueDirectly($this->managed($agent), $municipality->id()->toRfc4122(), $this->command($zones ?? [$this->organizerZone]), $duration, $key ?? $this->key());
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function assertNothingWritten(): void
    {
        foreach (['request_permit_request', 'request_permit_request_municipality', 'request_permit_request_zone', 'request_permit_request_decision', 'request_idempotency_key', 'request_permit_request_attachment'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
        self::assertSame(0, $this->rows('audit_log WHERE action LIKE \'REQUEST_%\''));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(last_number), 0) FROM request_reference_counter'), 'aucune référence consommée');
    }

    private function specificSlot(EventZone $zone, string $date): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = :id', ['id' => $zone->id()->toBinary()]);
        $managed = $this->em->find(EventZone::class, $zone->id());
        $this->save(EventZoneValidity::fromSlot($managed, ValiditySlot::punctual(T::date($date), T::time('09:00'), T::time('17:00')), F::now()));
    }

    // ---------------------------------------------------------------- Transaction unique

    public function testTheRequestIsCreatedAndApprovedImmediatelyInOneTransactionWithNoPendingRowEverWritten(): void
    {
        $seen = [];
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class ($seen) {
            /** @param list<array{string, string}> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof PermitRequestMunicipality) {
                        $this->seen[] = ['part', $entity->status()->value];
                    }
                    if ($entity instanceof PermitRequestDecision) {
                        $this->seen[] = ['decision', $entity->outcome()->value];
                    }
                }
            }
        });

        $result = $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));

        self::assertFalse($result->replayed);
        self::assertNotNull($result->decision);
        self::assertSame('APPROVED', $result->decision->outcome->value);
        // Le premier et seul flush écrit la part DÉJÀ approuvée, avec sa décision : aucune part PENDING, même brièvement.
        self::assertSame([['part', 'APPROVED'], ['decision', 'APPROVED']], $seen);
        self::assertSame(['APPROVED'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM request_permit_request_municipality'));
        self::assertSame(1, $this->rows('request_permit_request'));
        self::assertSame(1, $this->rows('request_permit_request_municipality'));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        $row = $this->em->getConnection()->fetchAssociative('SELECT outcome, path, duration_mode, valid_from, valid_until, decided_at FROM request_permit_request_decision');
        self::assertSame(['APPROVED', 'DIRECT_ISSUANCE', 'H24', '2026-06-12 09:00:00', '2026-06-13 09:00:00', '2026-06-12 09:00:00'], array_values($row ?: []));
    }

    public function testTheAuditCarriesTheAgentTheMunicipalityTheEventTheReferenceAndTheDuration(): void
    {
        $result = $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H48));

        $audits = $this->decisionAudits();
        self::assertCount(1, $audits);
        self::assertSame('REQUEST_APPROVED', $audits[0]['action']);
        self::assertSame(bin2hex($this->instructor->id()->toBinary()), $audits[0]['actor']);
        self::assertSame(bin2hex($this->organizer->id()->toBinary()), $audits[0]['municipality']);
        self::assertSame($result->reference, $audits[0]['new']['reference']);
        self::assertSame($this->event->id()->toRfc4122(), $audits[0]['new']['event']);
        self::assertSame(['APPROVED', 'DIRECT_ISSUANCE', 'H48', '2026-06-14T09:00:00Z'], [$audits[0]['new']['status'], $audits[0]['new']['path'], $audits[0]['new']['durationMode'], $audits[0]['new']['validUntil']]);
        self::assertSame(1, $this->rows('audit_log WHERE action = \'REQUEST_SUBMITTED\''));
        $json = (string) json_encode($audits);
        self::assertStringNotContainsString('Martin', $json);
        self::assertStringNotContainsString('camille@exemple.fr', $json);
    }

    /**
     * @return iterable<string, array{string, string|null, string|null, string, string|null}>
     */
    public static function modes(): iterable
    {
        yield 'H24' => ['H24', null, null, 'H24', '2026-06-13 09:00:00'];
        yield 'H48' => ['H48', null, null, 'H48', '2026-06-14 09:00:00'];
        yield 'H72' => ['H72', null, null, 'H72', '2026-06-15 09:00:00'];
        yield 'CUSTOM' => ['CUSTOM', '2026-06-15', '18:30', 'CUSTOM', '2026-06-15 16:30:00'];
        yield 'UNTIL_CLOSURE' => ['UNTIL_CLOSURE', null, null, 'UNTIL_CLOSURE', null];
    }

    #[DataProvider('modes')]
    public function testEachOfTheFiveDurationModesIsIssuedAndStored(string $mode, ?string $date, ?string $time, string $stored, ?string $until): void
    {
        $this->issue($this->instructor, $this->organizer, ApprovalDuration::fromInput($mode, $date, $time));

        $row = $this->em->getConnection()->fetchAssociative('SELECT duration_mode, valid_until FROM request_permit_request_decision');
        self::assertSame([$stored, $until], array_values($row ?: []));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCustomEnds(): iterable
    {
        yield 'passée' => ['2026-06-11', '10:00'];
        yield 'au-delà de l\'événement' => ['2026-06-21', '10:00'];
        yield 'heure locale inexistante' => ['2026-03-29', '02:30'];
    }

    #[DataProvider('invalidCustomEnds')]
    public function testAnInvalidCustomEndWritesNothing(string $date, string $time): void
    {
        $this->expectException(DomainRuleViolation::class);
        try {
            $this->issue($this->instructor, $this->organizer, ApprovalDuration::custom($date, $time));
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testSeveralCompatibleZonesWithDifferentSlotsAreIssuedTogether(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->specificSlot($this->organizerZone, '2026-06-12');
        $this->specificSlot($second, '2026-06-13');

        $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H48), [$this->organizerZone, $second]);

        self::assertSame(2, $this->rows('request_permit_request_zone'));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    // ---------------------------------------------------------------- Rollback intégral

    public function testAZoneWithoutAnySlotInTheWindowRefusesTheWholeIssuanceAndLeavesNoTrace(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->specificSlot($second, '2026-06-18'); // après les 24 h choisies

        try {
            $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24), [$this->organizerZone, $second]);
            self::fail('Une zone incompatible doit refuser toute la délivrance.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('aucune période d\'accès dans la durée', $e->getMessage());
        }
        $this->assertNothingWritten();
        // La même clé, avec une durée compatible, aboutit ensuite à la PREMIÈRE référence : aucune référence n'a été consommée.
        $result = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone, $second]), ApprovalDuration::untilClosure(), 'decision-retry-0000000001');
        self::assertMatchesRegularExpression('/^DEM-\d{4}-0001$/', $result->reference);
    }

    public function testASuspendedZoneRefusesTheIssuance(): void
    {
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testAnEventOrParticipationThatIsNotOperationalRefusesTheIssuance(): void
    {
        $this->sql('UPDATE event_event SET status = \'SUSPENDED\'');
        try {
            $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));
            self::fail();
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('ne sont pas ouvertes', $e->getMessage());
        }
        $this->assertNothingWritten();
    }

    public function testASuspendedParticipationRefusesTheIssuance(): void
    {
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->partnerParticipation->id()->toBinary()]);

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->issuer()->issueDirectly($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $this->command([$this->partnerZone]), ApprovalDuration::untilClosure(), $this->key());
        } finally {
            $this->assertNothingWritten();
        }
    }

    public function testAFailingAuditRollsBackEveryWriteOfTheAttempt(): void
    {
        // Appel 1 : audit de l'approbation ; appel 2 : audit du dépôt, écrit APRÈS la décision, juste avant le commit.
        foreach ([1, 2] as $failOn) {
            try {
                $this->issuer(new FailingAuditTrail(self::getContainer()->get(\App\Shared\Application\AuditTrail::class), $failOn))
                    ->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());
                self::fail('L\'échec injecté aurait dû remonter.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Échec injecté', $e->getMessage());
            }
            $this->assertNothingWritten();
            $this->em->clear();
            self::getContainer()->get('doctrine')->resetManager();
            $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        }
    }

    public function testAFailingLot05HookRollsBackTheWholeIssuance(): void
    {
        $listener = new class () implements ApprovedPartListener {
            public function partApproved(PermitRequestDecision $decision): void
            {
                throw new \RuntimeException('Émission impossible.');
            }
        };

        try {
            $this->issuer(listener: $listener)->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());
            self::fail();
        } catch (\RuntimeException) {
        }
        $this->assertNothingWritten();
    }

    public function testTheHookIsCalledOnceForTheIssuedDecision(): void
    {
        $listener = new class () implements ApprovedPartListener {
            public int $calls = 0;

            public function partApproved(PermitRequestDecision $decision): void
            {
                ++$this->calls;
            }
        };
        $result = $this->issuer(listener: $listener)->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), 'decision-hook-00000000001');
        $this->issuer(listener: $listener)->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), 'decision-hook-00000000001');

        self::assertSame(1, $listener->calls, 'le rejeu ne réapplique pas la décision');
        self::assertNotNull($result->decision);
    }

    // ---------------------------------------------------------------- Idempotence

    public function testReplayingTheSameKeyReturnsTheExistingResultWithoutApplyingTheDecisionAgain(): void
    {
        $first = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), 'decision-replay-0000000001');
        $again = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H72), 'decision-replay-0000000001');

        self::assertFalse($first->replayed);
        self::assertTrue($again->replayed);
        self::assertSame([$first->requestId, $first->reference], [$again->requestId, $again->reference]);
        self::assertNotNull($again->decision);
        self::assertSame('H24', $again->decision->durationMode?->value, 'la décision d\'origine est relue, jamais recalculée avec la nouvelle durée');
        self::assertSame([1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision'), $this->rows('request_idempotency_key')]);
        self::assertSame(1, $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\''));
    }

    public function testADepositKeyAndAnIssuanceKeyNeverReplayEachOther(): void
    {
        $key = 'decision-scope-000000000001';
        $public = $this->service(\App\Request\Application\RequestSubmissionService::class)->submitPublic($this->command([$this->organizerZone]), $key);
        $issued = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $key);

        self::assertFalse($issued->replayed);
        self::assertNotSame($public->requestId, $issued->requestId);
    }

    // ---------------------------------------------------------------- Permissions

    public function testTheThreeAuthorisedRolesMayIssueForTheirOwnMunicipality(): void
    {
        foreach ([$this->adminOnly, $this->managerOnly, $this->instructor] as $agent) {
            $result = $this->issue($agent, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));
            self::assertNotNull($result->decision, $agent->email());
        }
        self::assertSame([3, 3], [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision')]);
    }

    public function testAUserWithoutMunicipalRoleOrFromAnotherMunicipalityCannotIssue(): void
    {
        $stranger = \App\Tests\Support\UserBuilder::aUser()->build();
        $this->save($stranger);
        $cases = [
            'sans rôle municipal' => [$stranger, $this->organizer],
            'commune étrangère à l\'agent (identifiant forgé)' => [$this->outsiderInstructor, $this->organizer],
            'commune partenaire forgée par un agent de l\'organisatrice' => [$this->instructor, $this->partner],
        ];
        foreach ($cases as $label => [$agent, $municipality]) {
            try {
                $this->issue($agent, $municipality, ApprovalDuration::fixed(DurationMode::H24));
                self::fail($label);
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingWritten();
    }

    public function testAnAgentCannotIssueForTheZonesOfAForeignMunicipalityEvenAlongsideHisOwn(): void
    {
        foreach ([[$this->partnerZone], [$this->organizerZone, $this->partnerZone]] as $zones) {
            try {
                $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24), $zones);
                self::fail('Une zone d\'une autre commune est acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNothingWritten();
    }

    public function testTheIssuanceNeverTouchesAnotherMunicipalityPortion(): void
    {
        $public = $this->submit(); // demande publique des deux communes : en attente
        $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));

        self::assertSame('PENDING', $this->partStatus($public, $this->organizer));
        self::assertSame('PENDING', $this->partStatus($public, $this->partner));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    // ---------------------------------------------------------------- Prévisualisation

    private function preview(): DirectIssuancePreview
    {
        $c = self::getContainer();

        return new DirectIssuancePreview(
            $c->get(\App\Request\Application\AgentRequestAccess::class),
            $c->get(\App\Event\Domain\EventParticipationRepository::class),
            $c->get(\App\Event\Domain\EventZoneRepository::class),
            new \App\Request\Application\MunicipalDecisionApplier($c->get(\App\Shared\Application\AuditTrail::class), $c->get(\App\Event\Domain\EventZoneValidityRepository::class), new \App\Request\Infrastructure\NullApprovedPartListener()),
            $this->clock,
        );
    }

    public function testThePreviewComputesTheWindowAndNamesIncompatibleZonesWithoutWritingAnything(): void
    {
        $second = F::readyZone($this->organizerParticipation);
        $this->save($second->createdBy(), $second);
        $this->specificSlot($second, '2026-06-18');
        $before = [$this->rows('request_permit_request'), $this->rows('audit_log'), $this->rows('request_reference_counter')];

        $ok = $this->preview()->preview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->event->id()->toRfc4122(), [$this->organizerZone->id()->toRfc4122()], ApprovalDuration::fixed(DurationMode::H24));
        $bad = $this->preview()->preview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->event->id()->toRfc4122(), [$this->organizerZone->id()->toRfc4122(), $second->id()->toRfc4122()], ApprovalDuration::fixed(DurationMode::H24));

        self::assertTrue($ok->isApprovable());
        self::assertSame('2026-06-13 09:00:00', $ok->window->until?->format('Y-m-d H:i:s'));
        self::assertFalse($bad->isApprovable());
        self::assertCount(1, $bad->problems);
        self::assertSame('NO_PERIOD', $bad->problems[0]->cause);
        self::assertSame($before, [$this->rows('request_permit_request'), $this->rows('audit_log'), $this->rows('request_reference_counter')]);
    }

    public function testThePreviewRefusesForeignZonesAndUnauthorisedAgents(): void
    {
        foreach ([
            [$this->instructor, $this->organizer, $this->partnerZone],
        ] as [$agent, $municipality, $zone]) {
            try {
                $this->preview()->preview($this->managed($agent), $municipality->id()->toRfc4122(), $this->event->id()->toRfc4122(), [$zone->id()->toRfc4122()], ApprovalDuration::fixed(DurationMode::H24));
                self::fail();
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(OperationNotAllowed::class);
        $this->preview()->preview($this->managed($this->outsiderInstructor), $this->organizer->id()->toRfc4122(), $this->event->id()->toRfc4122(), [$this->organizerZone->id()->toRfc4122()], ApprovalDuration::fixed(DurationMode::H24));
    }

    // ---------------------------------------------------------------- Confirmation

    private function reader(): DirectIssuanceReader
    {
        $c = self::getContainer();

        return new DirectIssuanceReader($c->get(\App\Municipality\Application\MunicipalityContext::class), $c->get(\App\Request\Domain\PermitRequestRepository::class), $c->get(\App\Event\Application\EventOperationalGuard::class));
    }

    public function testOnlyTheIssuingAgentReadsTheConfirmationAndOnlyForHisOwnMunicipality(): void
    {
        $result = $this->issue($this->managerOnly, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));
        $organizer = $this->organizer->id()->toRfc4122();

        $confirmation = $this->reader()->confirmation($this->managed($this->managerOnly), $organizer, $result->requestId);
        self::assertSame('APPROVED', $confirmation->status->value);
        self::assertSame($result->reference, $confirmation->reference);
        self::assertFalse($confirmation->consultable, 'un EVENT_MANAGER sans INSTRUCTOR n\'obtient aucun lien de consultation');
        self::assertSame(['Route départementale 107'], $confirmation->zones);

        foreach ([[$this->instructor, $organizer], [$this->adminOnly, $organizer], [$this->partnerInstructor, $organizer], [$this->managerOnly, $this->partner->id()->toRfc4122()]] as $i => [$other, $commune]) {
            try {
                $this->reader()->confirmation($this->managed($other), $commune, $result->requestId);
                self::fail('cas '.$i);
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheConfirmationNeverExposesAnInstructedOrPendingRequest(): void
    {
        $pending = $this->submit([$this->organizerZone]);
        $decided = $this->submit([$this->organizerZone]);
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $decided, ApprovalDuration::fixed(DurationMode::H24));
        foreach ([$pending, $decided] as $id) {
            try {
                $this->reader()->confirmation($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id);
                self::fail('une demande instruite ou en attente est exposée');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAMemberWithoutAnIssuingRoleIsDeniedTheConfirmation(): void
    {
        $result = $this->issue($this->instructor, $this->organizer, ApprovalDuration::fixed(DurationMode::H24));
        $this->sql('UPDATE municipality_membership SET roles = :r WHERE user_id = :id', ['r' => '[]', 'id' => $this->instructor->id()->toBinary()]);

        $this->expectException(AgentRequestDenied::class);
        $this->reader()->confirmation($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $result->requestId);
    }

    public function testNoPendingPartExistsAfterEitherPathOfTheAgent(): void
    {
        $this->issue($this->instructor, $this->organizer, ApprovalDuration::untilClosure());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM request_permit_request_municipality WHERE status = :s', ['s' => MunicipalRequestStatus::Pending->value]));
    }
}
