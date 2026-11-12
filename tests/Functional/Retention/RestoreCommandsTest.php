<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Ops\Application\MaintenanceMode;
use App\Retention\Application\RetentionPurgeService;
use App\Tests\Support\RestoreScenarioTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Commandes de restauration, de rejeu et de remise en service (LOT 05.6.7) : sorties, codes de sortie et refus, sans donnée personnelle. */
final class RestoreCommandsTest extends RestoreScenarioTestCase
{
    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find($name));
    }

    private function purgedThenRestored(): void
    {
        $this->dueForPurge();
        $this->backupDatabase();
        static::getContainer()->get(RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
        $this->restoreDatabase();
    }

    private function operatorId(): string
    {
        return $this->superAdmin->id()->toRfc4122();
    }

    public function testTheRestoreCheckIsCleanOnAHealthySystemAndBlockingAfterARestore(): void
    {
        $check = $this->command('app:retention:restore-check');
        self::assertSame(0, $check->execute([]), $check->getDisplay());
        self::assertStringContainsString('anomalies_bloquantes=0', $check->getDisplay());

        $this->purgedThenRestored();
        self::assertSame(1, $check->execute([]));
        $out = $check->getDisplay();
        self::assertStringContainsString('REINTRODUCED_DOSSIER', $out);
        self::assertStringContainsString('rejouables=3', $out);
        self::assertStringContainsString('Lecture seule', $out);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $out);
        }

        $json = $this->command('app:retention:restore-check');
        $json->execute(['--format' => 'json']);
        $first = strpos($json->getDisplay(), '{');
        $decoded = json_decode(substr($json->getDisplay(), (int) $first, (int) strrpos($json->getDisplay(), '}') - (int) $first + 1), true);
        self::assertIsArray($decoded);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $decoded['hash']);
    }

    public function testTheReplayCommandSimulatesRefusesAndThenExecutes(): void
    {
        $this->purgedThenRestored();
        $replay = $this->command('app:retention:replay');
        $hash = $this->report()->hash;

        self::assertSame(1, $replay->execute([]), 'sans opérateur');
        self::assertStringContainsString('non habilité', $replay->getDisplay());
        self::assertSame(1, $replay->execute(['--operator' => $this->admin->id()->toRfc4122(), '--approval-ref' => 'INSTRUCTION-2026-007']));
        self::assertSame(1, $replay->execute(['--operator' => $this->operatorId()]));
        self::assertStringContainsString('APPROVAL_REFERENCE_REQUIRED', $replay->getDisplay());

        self::assertSame(0, $replay->execute(['--operator' => $this->operatorId(), '--approval-ref' => 'INSTRUCTION-2026-007']), $replay->getDisplay());
        self::assertStringContainsString('SIMULATION', $replay->getDisplay());
        self::assertStringContainsString('prévus_dossiers=2', $replay->getDisplay());
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        self::assertSame(1, $replay->execute(['--operator' => $this->operatorId(), '--approval-ref' => 'INSTRUCTION-2026-007', '--execute' => true]));
        self::assertStringContainsString('REPORT_HASH_MISMATCH', $replay->getDisplay());
        self::assertStringContainsString('Rien n\'a été modifié', $replay->getDisplay());
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        self::assertSame(0, $replay->execute(['--operator' => $this->operatorId(), '--approval-ref' => 'INSTRUCTION-2026-007', '--report' => $hash, '--execute' => true]), $replay->getDisplay());
        self::assertStringContainsString('MODE EXÉCUTION', $replay->getDisplay());
        self::assertStringContainsString('rejoués_dossiers=2', $replay->getDisplay());
        self::assertSame(0, $this->rows('request_permit_request_municipality'));
    }

    public function testTheReleaseCommandRefusesWhileAnomaliesRemainThenReleasesAfterTheReplayAndTheAcknowledgement(): void
    {
        $this->purgedThenRestored();
        $this->command('app:ops:maintenance')->execute(['--reason' => 'restauration test', '--operator' => $this->operatorId()]);
        $release = $this->command('app:ops:release');

        self::assertSame(1, $release->execute(['--operator' => $this->operatorId()]));
        self::assertStringContainsString('REMISE EN SERVICE REFUSÉE', $release->getDisplay());
        self::assertStringContainsString('REINTRODUCED_DOSSIER', $release->getDisplay());
        self::assertTrue(static::getContainer()->get(MaintenanceMode::class)->active());

        $check = $this->command('app:retention:restore-check');
        $check->execute([]); // en maintenance : les constats de perte sont consignés
        $this->command('app:retention:replay')->execute(['--operator' => $this->operatorId(), '--approval-ref' => 'INSTRUCTION-2026-007', '--report' => $this->report()->hash, '--execute' => true]);

        self::assertSame(1, $release->execute(['--operator' => $this->operatorId()]), 'la perte possible n\'est pas reconnue');
        self::assertSame(0, $release->execute(['--operator' => $this->operatorId(), '--acknowledge-loss' => 'RECONNAISSANCE-2026-01']), $release->getDisplay());
        self::assertStringContainsString('Aucun droit ni aucune autorisation n\'a été modifié', $release->getDisplay());
        self::assertFalse(static::getContainer()->get(MaintenanceMode::class)->active());
    }

    public function testTheRegisterCheckCommandRefusesARepairWithoutOperatorAndApproval(): void
    {
        $this->dueForPurge();
        $check = $this->command('app:retention:register-check');

        self::assertSame(1, $check->execute(['--repair' => true]));
        self::assertStringContainsString('non habilité', $check->getDisplay());
        self::assertSame(1, $check->execute(['--repair' => true, '--operator' => $this->operatorId()]));
        self::assertStringContainsString('APPROVAL_REFERENCE_REQUIRED', $check->getDisplay());
    }

    public function testTheIntegrityCheckIsReadOnlyAndFailsOnBlockingAnomalies(): void
    {
        $integrity = $this->command('app:ops:integrity-check');
        self::assertSame(0, $integrity->execute([]), $integrity->getDisplay());

        $this->purgedThenRestored();
        $before = [$this->rows('audit_log'), $this->rows('retention_purge_record')];
        self::assertSame(1, $integrity->execute([]));
        self::assertStringContainsString('REINTRODUCED_DOSSIER (BLOCKING)', $integrity->getDisplay());
        self::assertSame($before, [$this->rows('audit_log'), $this->rows('retention_purge_record')]);
    }
}
