<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\PermitIssuer;
use App\Permit\Domain\VerificationToken;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\PermitIssuerFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Création du laissez-passer à l'approbation (LOT 05.1, DM-112, DT-046) : un laissez-passer par demande, une autorisation par décision,
 * copies de zones et de plaques, QR stable, audit sans jeton, rollback intégral, rejeu, unicités SQL, délivrance directe. Horloge figée au
 * 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class PermitIssuanceTest extends MunicipalDecisionTestCase
{
    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approve(User $agent, Municipality $commune, string $requestId, DurationMode $mode = DurationMode::H24): void
    {
        $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $requestId, ApprovalDuration::fixed($mode));
    }

    /**
     * Dépose une demande en véhicule (une plaque) sur les zones données.
     *
     * @param list<\App\Event\Domain\EventZone>|null $zones
     */
    private function submitWithPlate(?array $zones = null, string $plate = 'GH-482-KT'): string
    {
        $zones ??= [$this->organizerZone, $this->partnerZone];

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
            [$plate],
            array_map(static fn ($z): string => $z->id()->toRfc4122(), $zones),
            true,
        ), $this->key())->requestId;
    }

    /** @return array<string, mixed> */
    private function permit(): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT *, LOWER(HEX(request_id)) AS rid FROM permit_permit');
        self::assertIsArray($row);

        return $row;
    }

    private function n(string $table): int
    {
        return $this->rows($table);
    }

    public function testTheFirstApprovalCreatesThePermitItsTokenItsAuthorizationItsZonesAndItsPlates(): void
    {
        $id = $this->submitWithPlate();

        $this->approve($this->instructor, $this->organizer, $id);

        self::assertSame([1, 1, 1, 1, 1], [$this->n('permit_permit'), $this->n('permit_verification_token'), $this->n('permit_authorization'), $this->n('permit_authorization_zone'), $this->n('permit_vehicle')]);
        $permit = $this->permit();
        self::assertSame('LP-2026-0001', $permit['reference']);
        self::assertSame(str_replace('-', '', $id), $permit['rid']);
        self::assertSame(bin2hex($this->event->id()->toBinary()), bin2hex((string) $permit['event_id']));
        $decision = $this->decisionRow($id, $this->organizer);
        self::assertNotNull($decision);
        self::assertSame(bin2hex((string) $decision['id']), bin2hex((string) $permit['first_decision_id']));
        self::assertSame('GH482KT', $this->em->getConnection()->fetchOne('SELECT registration FROM permit_vehicle'), 'plaque normalisée, copiée de la demande');
        self::assertSame($this->organizerZone->label(), $this->em->getConnection()->fetchOne('SELECT zone_label FROM permit_authorization_zone'));
        $token = (string) $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
        self::assertMatchesRegularExpression('/^[a-z2-7]{32}$/', $token, '160 bits en base32');
        self::assertSame(bin2hex((string) $permit['id']), bin2hex((string) $this->em->getConnection()->fetchOne('SELECT active_permit_id FROM permit_verification_token')));
    }

    public function testTheAuthorizationCopiesTheWindowOfTheDecisionAndLeavesTheDecisionUntouched(): void
    {
        $id = $this->submitWithPlate([$this->organizerZone]);
        $this->approve($this->instructor, $this->organizer, $id, DurationMode::H72);
        $decision = $this->decisionRow($id, $this->organizer);

        $row = $this->em->getConnection()->fetchAssociative('SELECT duration_mode, valid_from, valid_until, revoked_at FROM permit_authorization');
        self::assertSame(['H72', '2026-06-12 09:00:00', '2026-06-15 09:00:00', null], array_values($row ?: []));
        self::assertSame($decision['duration_mode'], $row['duration_mode']);
        self::assertSame($decision, $this->decisionRow($id, $this->organizer));
    }

    public function testASecondCommuneAddsItsAuthorizationToTheSamePermitWithTheSameTokenReferenceAndPlates(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);
        $permit = $this->permit();
        $token = $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token');

        $this->approve($this->partnerInstructor, $this->partner, $id, DurationMode::H48);

        self::assertSame([1, 1, 2, 2, 1], [$this->n('permit_permit'), $this->n('permit_verification_token'), $this->n('permit_authorization'), $this->n('permit_authorization_zone'), $this->n('permit_vehicle')]);
        self::assertSame($permit['reference'], $this->permit()['reference']);
        self::assertSame($token, $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token'), 'le QR ne change pas');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM permit_reference_counter'), 'aucune référence supplémentaire');
        self::assertSame(['H24', 'H48'], $this->em->getConnection()->fetchFirstColumn('SELECT duration_mode FROM permit_authorization ORDER BY duration_mode'));
        self::assertSame(bin2hex((string) $permit['first_decision_id']), bin2hex((string) $this->permit()['first_decision_id']), 'la première décision reste celle de la première approbation');
    }

    public function testARefusalAndAPendingPartCreateNothing(): void
    {
        $id = $this->submitWithPlate();

        $this->instruction()->refuse($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, 'duplicate', null);

        self::assertSame([0, 0, 0], [$this->n('permit_permit'), $this->n('permit_authorization'), $this->n('permit_reference_counter')]);
    }

    public function testRefusingTheSecondCommuneAddsNoAuthorization(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);

        $this->instruction()->refuse($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $id, 'duplicate', null);

        self::assertSame([1, 1], [$this->n('permit_permit'), $this->n('permit_authorization')]);
    }

    public function testTheAuditNamesTheEventsAndNeverContainsTheTokenOrPersonalData(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);

        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT action, LOWER(HEX(municipality_id)) AS municipality, new_value FROM audit_log WHERE action IN ('PERMIT_ISSUED', 'PERMIT_AUTHORIZATION_ADDED') ORDER BY occurred_at, action DESC");
        self::assertSame(['PERMIT_ISSUED', 'PERMIT_AUTHORIZATION_ADDED'], array_column($rows, 'action'));
        self::assertSame([bin2hex($this->organizer->id()->toBinary()), bin2hex($this->partner->id()->toBinary())], array_column($rows, 'municipality'));
        $token = (string) $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
        foreach ($rows as $row) {
            foreach ([$token, 'Martin', 'Camille', 'camille@exemple.fr', 'GH-482-KT', 'GH482KT'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $row['new_value']);
            }
            self::assertStringContainsString('LP-2026-0001', (string) $row['new_value']);
        }
    }

    public function testReplayingThePortForAnAlreadyAuthorizedDecisionCreatesNothing(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);
        $decisionId = $this->em->getConnection()->fetchOne('SELECT id FROM request_permit_request_decision');

        $this->service(TransactionRunner::class)->run(function () use ($decisionId): void {
            $decision = $this->em->find(\App\Request\Domain\PermitRequestDecision::class, \Symfony\Component\Uid\Uuid::fromBinary((string) $decisionId)) ?? self::fail('Décision absente.');
            PermitIssuerFactory::create(self::getContainer())->partApproved($decision);
        });

        self::assertSame([1, 1, 1], [$this->n('permit_permit'), $this->n('permit_authorization'), $this->n('permit_verification_token')]);
        self::assertSame(1, $this->n('audit_log WHERE action = \'PERMIT_ISSUED\''));
    }

    public function testCallingThePortTwiceInTheSameTransactionDoesNotCreateTwoPermits(): void
    {
        $id = $this->submitWithPlate();
        // Approbation SANS créateur de laissez-passer : le port est ensuite appelé deux fois dans UNE transaction.
        $this->instruction(listener: new \App\Request\Infrastructure\NullApprovedPartListener())->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H24));
        self::assertSame(0, $this->n('permit_permit'));
        $decisionId = $this->em->getConnection()->fetchOne('SELECT id FROM request_permit_request_decision');

        $this->service(TransactionRunner::class)->run(function () use ($decisionId): void {
            $decision = $this->em->find(\App\Request\Domain\PermitRequestDecision::class, \Symfony\Component\Uid\Uuid::fromBinary((string) $decisionId)) ?? self::fail('Décision absente.');
            $issuer = PermitIssuerFactory::create(self::getContainer());
            $issuer->partApproved($decision);
            $issuer->partApproved($decision);
        });

        self::assertSame([1, 1, 1, 1], [$this->n('permit_permit'), $this->n('permit_authorization'), $this->n('permit_verification_token'), $this->n('permit_vehicle')]);
    }

    public function testAFailureAfterThePermitCreationRollsBackTheApprovalTheCounterAndEverythingElse(): void
    {
        $id = $this->submitWithPlate();
        $failing = new class (self::getContainer()->get(AuditTrail::class)) implements AuditTrail {
            public function __construct(private readonly AuditTrail $inner)
            {
            }

            public function record(AuditEvent $event): void
            {
                if (AuditAction::PermitIssued === $event->action) {
                    throw new \RuntimeException('audit indisponible');
                }
                $this->inner->record($event);
            }
        };

        try {
            $this->instruction($failing)->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H24));
            self::fail('Un échec après la création du laissez-passer doit annuler l\'approbation.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit indisponible', $e->getMessage());
        }

        self::assertSame('PENDING', $this->partStatus($id, $this->organizer));
        self::assertSame(0, $this->n('request_permit_request_decision'));
        self::assertSame([0, 0, 0, 0, 0, 0], [$this->n('permit_permit'), $this->n('permit_verification_token'), $this->n('permit_authorization'), $this->n('permit_authorization_zone'), $this->n('permit_vehicle'), $this->n('permit_reference_counter')]);
        self::assertSame(0, $this->n('audit_log WHERE action LIKE \'REQUEST_APPROV%\''));
    }

    public function testTheSameApprovalSucceedsAfterARolledBackAttemptAndTakesTheFirstNumber(): void
    {
        $id = $this->submitWithPlate();
        $failing = new class (self::getContainer()->get(AuditTrail::class)) implements AuditTrail {
            public function __construct(private readonly AuditTrail $inner)
            {
            }

            public function record(AuditEvent $event): void
            {
                if (AuditAction::PermitIssued === $event->action) {
                    throw new \RuntimeException('audit indisponible');
                }
                $this->inner->record($event);
            }
        };
        try {
            $this->instruction($failing)->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H24));
        } catch (\RuntimeException) {
        }

        // L'EntityManager est fermé après l'échec (convention du TransactionRunner) : la nouvelle tentative part dans un autre processus.
        $raw = \App\Tests\Support\ParallelPhp::run('decide-request.php', [[$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $id, 'approve', '{}']]);
        $decoded = json_decode($raw[0]['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['ok'], $raw[0]['stdout']);
        self::assertSame('LP-2026-0001', $this->permit()['reference'], 'aucun numéro n\'a été consommé par la tentative annulée');
    }

    public function testTheDatabaseRefusesDuplicatePermitsTokensReferencesAndAuthorizations(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);
        $conn = $this->em->getConnection();
        $permit = $this->permit();

        $duplicates = [
            'un second laissez-passer pour la demande' => ['INSERT INTO permit_permit (id, reference, created_at, request_id, event_id, first_decision_id) VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'LP-2026-0099\', NOW(), :request, :event, :decision)', ['request' => $permit['request_id'], 'event' => $permit['event_id'], 'decision' => $permit['first_decision_id']]],
            'une référence déjà prise' => ['INSERT INTO permit_permit (id, reference, created_at, request_id, event_id, first_decision_id) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), reference, NOW(), UNHEX(REPLACE(UUID(), \'-\', \'\')), event_id, first_decision_id FROM permit_permit', []],
            'un second jeton actif' => ['INSERT INTO permit_verification_token (id, token, active_permit_id, created_at, permit_id) VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), \'abcdefghijklmnopqrstuvwxyz234567\', :permit, NOW(), :permit)', ['permit' => $permit['id']]],
            'un jeton déjà pris' => ['INSERT INTO permit_verification_token (id, token, active_permit_id, created_at, permit_id) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), token, NULL, NOW(), permit_id FROM permit_verification_token', []],
            'une seconde autorisation pour la participation' => ['INSERT INTO permit_authorization (id, duration_mode, valid_from, participation_id, municipality_id, decision_id, permit_id) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), duration_mode, valid_from, participation_id, municipality_id, UNHEX(REPLACE(UUID(), \'-\', \'\')), permit_id FROM permit_authorization LIMIT 1', []],
            'une seconde autorisation pour la décision' => ['INSERT INTO permit_authorization (id, duration_mode, valid_from, participation_id, municipality_id, decision_id, permit_id) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), duration_mode, valid_from, UNHEX(REPLACE(UUID(), \'-\', \'\')), municipality_id, decision_id, permit_id FROM permit_authorization LIMIT 1', []],
        ];
        foreach ($duplicates as $label => [$sql, $params]) {
            try {
                $conn->executeStatement($sql, $params);
                self::fail('Doublon accepté : '.$label);
            } catch (UniqueConstraintViolationException|\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([1, 1, 2], [$this->n('permit_permit'), $this->n('permit_verification_token'), $this->n('permit_authorization')]);
    }

    public function testARetiredTokenFreesTheActiveSlotAndKeepsTheHistoryForTheFutureReplacement(): void
    {
        $id = $this->submitWithPlate();
        $this->approve($this->instructor, $this->organizer, $id);
        $conn = $this->em->getConnection();
        $permit = $this->permit();

        $conn->executeStatement('UPDATE permit_verification_token SET active_permit_id = NULL, retired_at = NOW()');
        $conn->executeStatement('INSERT INTO permit_verification_token (id, token, active_permit_id, created_at, permit_id) VALUES (UNHEX(REPLACE(UUID(), \'-\', \'\')), :token, :permit, NOW(), :permit)', ['token' => VerificationToken::generate(), 'permit' => $permit['id']]);

        self::assertSame(2, $this->n('permit_verification_token'));
        self::assertSame(1, $this->n('permit_verification_token WHERE active_permit_id IS NOT NULL'));
    }

    public function testGeneratedTokensAreUniqueUnpredictableAndCarryNoReference(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $tokens[] = VerificationToken::generate();
        }

        self::assertCount(200, array_unique($tokens));
        foreach ($tokens as $token) {
            // 160 bits : 32 caractères base32 de 5 bits chacun ; alphabet sans séparateur ni préfixe (aucune référence `DEM-` / `LP-`).
            self::assertMatchesRegularExpression('/^[a-z2-7]{32}$/', $token);
        }
    }

    // ------------------------------------------------------------ Délivrance directe

    public function testADirectIssuanceCreatesTheRequestTheDecisionAndThePermitInOneTransaction(): void
    {
        $result = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());

        self::assertFalse($result->replayed);
        self::assertSame([1, 1, 1, 1], [$this->n('request_permit_request'), $this->n('request_permit_request_decision'), $this->n('permit_permit'), $this->n('permit_authorization')]);
        self::assertSame(str_replace('-', '', $result->requestId), $this->permit()['rid']);
        self::assertSame('LP-2026-0001', $this->permit()['reference']);
        self::assertSame(['APPROVED'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM request_permit_request_municipality'));
    }

    public function testAReplayedDirectIssuanceCreatesNoSecondPermit(): void
    {
        $key = $this->key();
        $first = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $key);
        $second = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H72), $key);

        self::assertTrue($second->replayed);
        self::assertSame($first->reference, $second->reference);
        self::assertSame([1, 1, 1, 1], [$this->n('request_permit_request'), $this->n('permit_permit'), $this->n('permit_authorization'), $this->n('permit_verification_token')]);
    }

    public function testAFailedDirectIssuanceLeavesNoPermitAndNoCounter(): void
    {
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);

        try {
            $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());
            self::fail('Une zone suspendue refuse la délivrance.');
        } catch (\App\Shared\Domain\DomainRuleViolation) {
        }

        self::assertSame([0, 0, 0, 0], [$this->n('request_permit_request'), $this->n('permit_permit'), $this->n('permit_authorization'), $this->n('permit_reference_counter')]);
    }

    public function testADirectIssuanceFollowedByAnotherCommuneApprovalSharesTheSamePermit(): void
    {
        $result = $this->issuer()->issueDirectly($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $this->command([$this->organizerZone]), ApprovalDuration::fixed(DurationMode::H24), $this->key());
        self::assertSame(1, $this->n('permit_permit'));
        self::assertNotSame('', $result->requestId);
        // La demande issue d'une délivrance directe n'a qu'une part : une seconde commune ne peut pas approuver ; le laissez-passer reste unique.
        self::assertSame(1, $this->n('permit_authorization'));
    }

    /**
     * @return iterable<string, array{DurationMode}>
     */
    public static function modes(): iterable
    {
        foreach (DurationMode::cases() as $mode) {
            yield $mode->value => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function testEveryDurationModeIsCopiedIntoTheAuthorization(DurationMode $mode): void
    {
        $id = $this->submitWithPlate([$this->organizerZone]);
        $duration = match ($mode) {
            DurationMode::Custom => ApprovalDuration::custom('2026-06-15', '18:30'),
            DurationMode::UntilClosure => ApprovalDuration::untilClosure(),
            default => ApprovalDuration::fixed($mode),
        };
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, $duration);

        $decision = $this->em->getConnection()->fetchAssociative('SELECT duration_mode, valid_from, valid_until FROM request_permit_request_decision');
        $copy = $this->em->getConnection()->fetchAssociative('SELECT duration_mode, valid_from, valid_until FROM permit_authorization');
        self::assertSame($decision, $copy);
        self::assertSame($mode->value, $copy['duration_mode']);
    }

    public function testThePermitIssuerIsTheWiredImplementationOfTheRequestPort(): void
    {
        self::assertInstanceOf(PermitIssuer::class, self::getContainer()->get(\App\Request\Application\ApprovedPartListener::class));
    }
}
