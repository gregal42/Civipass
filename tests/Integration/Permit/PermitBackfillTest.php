<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Permit\Application\PermitBackfill;
use App\Permit\UI\Console\BackfillPermitsCommand;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Infrastructure\NullApprovedPartListener;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Rattrapage idempotent des approbations antérieures au module Permit (LOT 05.1, DM-112) : même service que la création normale, aucune
 * décision modifiée, aucun doublon, simulation sans écriture, multicommunal, commande réservée au développement.
 */
#[Group('requires-request-schema')]
final class PermitBackfillTest extends MunicipalDecisionTestCase
{
    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    /**
     * Approbations SANS créateur de laissez-passer, comme avant le LOT 05.1 : A (deux communes, la partenaire une heure plus tard) et B (organisatrice).
     *
     * @return array{string, string}
     */
    private function legacyApprovals(): array
    {
        $a = $this->submit();
        $b = $this->submit([$this->organizerZone]);
        $silent = new NullApprovedPartListener();
        $this->instruction(listener: $silent)->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $a, ApprovalDuration::fixed(DurationMode::H24));
        $this->clock->sleep(3600);
        $this->instruction(listener: $silent)->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $a, ApprovalDuration::fixed(DurationMode::H48));
        $this->instruction(listener: $silent)->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $b, ApprovalDuration::fixed(DurationMode::H72));

        return [$a, $b];
    }

    private function backfill(): PermitBackfill
    {
        return self::getContainer()->get(PermitBackfill::class);
    }

    /** @return list<array<string, mixed>> */
    private function decisions(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM request_permit_request_decision ORDER BY id');
    }

    public function testTheDryRunCountsWithoutWritingAnything(): void
    {
        $this->legacyApprovals();

        $report = $this->backfill()->run(true);

        self::assertTrue($report->dryRun);
        self::assertSame([3, 2, 0, 0], [$report->approvalsMissing, $report->permitsToCreate, $report->permitsCreated, $report->authorizationsAdded]);
        self::assertSame([0, 0, 0], [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_reference_counter')]);
    }

    public function testTheBackfillCreatesMissingPermitsAndAuthorizationsWithoutTouchingDecisions(): void
    {
        [$a] = $this->legacyApprovals();
        $decisions = $this->decisions();
        $audits = $this->rows('audit_log WHERE action IN (\'REQUEST_APPROVED\', \'REQUEST_REFUSED\')');

        $report = $this->backfill()->run(false);

        self::assertSame([], $report->errors);
        self::assertSame([3, 2, 2, 1], [$report->approvalsMissing, $report->permitsToCreate, $report->permitsCreated, $report->authorizationsAdded]);
        self::assertSame([2, 3, 2, 3], [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token'), $this->rows('permit_authorization_zone')]);
        self::assertSame($decisions, $this->decisions(), 'aucune décision historique n\'est modifiée');
        self::assertSame($audits, $this->rows('audit_log WHERE action IN (\'REQUEST_APPROVED\', \'REQUEST_REFUSED\')'));
        $conn = $this->em->getConnection();
        $firstCommune = $conn->fetchOne('SELECT LOWER(HEX(d.municipality_id)) FROM permit_permit p JOIN request_permit_request_decision d ON d.id = p.first_decision_id WHERE p.request_id = :r', ['r' => (string) hex2bin(str_replace('-', '', $a))]);
        self::assertSame(bin2hex($this->organizer->id()->toBinary()), $firstCommune, 'la plus ancienne approbation porte le laissez-passer');
        self::assertSame(['LP-2026-0001', 'LP-2026-0002'], $conn->fetchFirstColumn('SELECT reference FROM permit_permit ORDER BY reference'));
    }

    public function testASecondRunCreatesNothing(): void
    {
        $this->legacyApprovals();
        $this->backfill()->run(false);
        $before = [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('audit_log'), $this->rows('permit_verification_token')];
        $tokens = $this->em->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token ORDER BY token');

        $report = $this->backfill()->run(false);

        self::assertSame([0, 0, 0, 0], [$report->approvalsMissing, $report->permitsCreated, $report->authorizationsAdded, \count($report->errors)]);
        self::assertSame($before, [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('audit_log'), $this->rows('permit_verification_token')]);
        self::assertSame($tokens, $this->em->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token ORDER BY token'), 'les QR ne changent pas');
    }

    public function testAnApprovalAlreadyHandledByTheNormalPathIsNotDuplicated(): void
    {
        $a = $this->submit();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $a, ApprovalDuration::fixed(DurationMode::H24)); // laissez-passer créé normalement
        $this->instruction(listener: new NullApprovedPartListener())->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $a, ApprovalDuration::fixed(DurationMode::H24));

        $report = $this->backfill()->run(false);

        self::assertSame([1, 0, 1], [$report->approvalsMissing, $report->permitsCreated, $report->authorizationsAdded]);
        self::assertSame([1, 2], [$this->rows('permit_permit'), $this->rows('permit_authorization')]);
    }

    public function testTheCommandIsRefusedOutsideDevelopmentAndRunsInDryRunInDevelopment(): void
    {
        $this->legacyApprovals();

        $refused = new CommandTester(new BackfillPermitsCommand($this->backfill(), 'test'));
        self::assertSame(1, $refused->execute([]));
        self::assertStringContainsString('réservée à l\'environnement de développement', $refused->getDisplay());
        self::assertSame(0, $this->rows('permit_permit'));

        $dev = new CommandTester(new BackfillPermitsCommand($this->backfill(), 'dev'));
        self::assertSame(0, $dev->execute(['--dry-run' => true]));
        self::assertStringContainsString('simulation', $dev->getDisplay());
        self::assertSame(0, $this->rows('permit_permit'));
        self::assertSame(0, $dev->execute([]));
        self::assertSame(2, $this->rows('permit_permit'));
    }
}
