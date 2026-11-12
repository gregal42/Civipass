<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ops;

use App\Ops\Application\FailureNotifier;
use App\Ops\Application\HeartbeatStore;
use App\Ops\Application\JobRegistry;
use App\Ops\Application\MaintenanceMode;
use App\Ops\Application\OpsSettings;
use App\Tests\Support\RestoreScenarioTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * Exploitation (LOT 05.6.7) : battements de cœur et supervision, mode maintenance, enveloppe `run-job` (verrou, délai, maintenance, battement seulement en cas de succès),
 * alerte d'échec sans donnée personnelle, point d'ancrage émis sur le courriel d'exploitation. Les processus sont RÉELS ; aucun timer n'est installé.
 */
final class OpsTest extends RestoreScenarioTestCase
{
    private function ops(): OpsSettings
    {
        return static::getContainer()->get(OpsSettings::class);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application(static::$kernel))->find($name));
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function sentEmails(): array
    {
        $messages = [];
        foreach (static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getMessages() as $message) {
            $messages[] = $message;
        }

        return $messages;
    }

    private function beatAll(): void
    {
        foreach (array_keys(JobRegistry::GO_LIVE) as $job) {
            static::getContainer()->get(HeartbeatStore::class)->beat($job);
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['OPS_ALERT_EMAIL']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ battements de cœur et supervision

    public function testStatusFlagsJobsThatNeverRanOrAreStaleAndAcceptsFreshOnes(): void
    {
        $status = $this->command('app:ops:status');
        self::assertSame(1, $status->execute([]));
        self::assertStringContainsString('NEVER_RAN', $status->getDisplay());

        $this->beatAll();
        self::assertSame(0, $status->execute([]), $status->getDisplay());

        $file = $this->ops()->heartbeatDir().'/anchor.json';
        file_put_contents($file, json_encode(['job' => 'anchor', 'at' => (new \DateTimeImmutable('-3 hours', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')]));
        self::assertSame(1, $status->execute([]));
        self::assertStringContainsString('anchor STALE', $status->getDisplay());
        self::assertSame(0, $status->execute(['--jobs' => 'backup,register-check']), 'sous-ensemble');
    }

    public function testAHeartbeatIsWrittenAtomicallyAndOnlyForAValidJobName(): void
    {
        $heartbeats = static::getContainer()->get(HeartbeatStore::class);
        $heartbeats->beat('backup');
        self::assertNotNull($heartbeats->last('backup'));
        self::assertSame([], glob($this->ops()->heartbeatDir().'/.*.tmp') ?: [], 'aucun fichier temporaire résiduel');
        foreach (['../etc', 'Backup', 'x', 'a b'] as $bad) {
            try {
                $heartbeats->beat($bad);
                self::fail('Nom de tâche invalide accepté.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(1, $this->command('app:ops:heartbeat')->execute(['job' => '../x']));
    }

    public function testTheMaintenanceIsExpectedButForgottenAfterTwentyFourHours(): void
    {
        $this->beatAll();
        static::getContainer()->get(MaintenanceMode::class)->enable('restauration');
        $status = $this->command('app:ops:status');

        self::assertSame(0, $status->execute([]));
        self::assertStringContainsString('attendu', $status->getDisplay());

        file_put_contents($this->ops()->maintenanceFile(), json_encode(['since' => (new \DateTimeImmutable('-30 hours', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'reason' => 'oubliée']));
        self::assertSame(1, $status->execute([]));
        self::assertStringContainsString('24 h', $status->getDisplay());
    }

    public function testTheMaintenanceCommandNeedsAReason(): void
    {
        $maintenance = $this->command('app:ops:maintenance');
        self::assertSame(1, $maintenance->execute([]));
        self::assertFalse(static::getContainer()->get(MaintenanceMode::class)->active());
        self::assertSame(0, $maintenance->execute(['--reason' => 'incident']));
        self::assertTrue(static::getContainer()->get(MaintenanceMode::class)->active());
    }

    // ------------------------------------------------------------------ enveloppe run-job (processus réels)

    /** @param list<string> $command */
    private function runJob(string $job, array $command, int $timeout = 30, bool $realHeartbeat = true): Process
    {
        $root = \dirname(__DIR__, 3);
        $env = ['CIVIPASS_DIR' => $root, 'OPS_DIR' => $this->ops()->dir(), 'APP_ENV' => 'test'];
        if (!$realHeartbeat) {
            $env['RUN_JOB_HEARTBEAT'] = 'true';
        }
        $process = new Process([$root.'/ops/bin/run-job', $job, '--timeout', (string) $timeout, '--', ...$command], $root, $env + ['PATH' => (string) getenv('PATH')]);
        $process->setTimeout(60);

        return $process;
    }

    public function testASuccessfulJobWritesItsHeartbeatAndAFailedOneDoesNot(): void
    {
        $ok = $this->runJob('backup', ['true']);
        $ok->run();
        self::assertSame(0, $ok->getExitCode(), $ok->getErrorOutput());
        self::assertNotNull(static::getContainer()->get(HeartbeatStore::class)->last('backup'));

        $ko = $this->runJob('anchor', ['sh', '-c', 'exit 3']);
        $ko->run();
        self::assertSame(3, $ko->getExitCode(), 'le code de sortie de la commande est conservé');
        self::assertNull(static::getContainer()->get(HeartbeatStore::class)->last('anchor'), 'aucun battement sans succès');
    }

    public function testATimeoutKillsTheJobAndWritesNoHeartbeat(): void
    {
        $process = $this->runJob('integrity-check', ['sleep', '10'], timeout: 1);
        $process->run();

        self::assertSame(124, $process->getExitCode());
        self::assertStringContainsString('interrompu', $process->getErrorOutput());
        self::assertNull(static::getContainer()->get(HeartbeatStore::class)->last('integrity-check'));
    }

    public function testTheMaintenanceFileBlocksEveryJobBeforeItRuns(): void
    {
        static::getContainer()->get(MaintenanceMode::class)->enable('restauration');
        $sentinel = $this->ops()->dir().'/sentinel-maintenance';
        @unlink($sentinel);

        $process = $this->runJob('backup', ['touch', $sentinel]);
        $process->run();

        self::assertSame(75, $process->getExitCode(), 'EX_TEMPFAIL : refusé, ni échec ni succès');
        self::assertStringContainsString('maintenance active', $process->getErrorOutput());
        self::assertFileDoesNotExist($sentinel, 'la commande n\'a jamais été lancée');
        self::assertNull(static::getContainer()->get(HeartbeatStore::class)->last('backup'));
    }

    public function testTwoRunsOfTheSameJobNeverOverlapButDifferentJobsMay(): void
    {
        @mkdir($this->ops()->dir(), 0o750, true);
        $second = $this->ops()->dir().'/sentinel-second';
        @unlink($second);

        $long = $this->runJob('register-check', ['sleep', '3']);
        $long->start();
        usleep(800000); // laisse la première exécution prendre le verrou

        $same = $this->runJob('register-check', ['touch', $second]);
        $same->run();
        self::assertSame(75, $same->getExitCode(), 'même tâche : refusée, jamais mise en file');
        self::assertStringContainsString('déjà en cours', $same->getErrorOutput());
        self::assertFileDoesNotExist($second);

        $other = $this->runJob('rights-overdue', ['true']);
        $other->run();
        self::assertSame(0, $other->getExitCode(), 'une autre tâche n\'est pas bloquée');

        $long->wait();
        self::assertSame(0, $long->getExitCode());
        $again = $this->runJob('register-check', ['true']);
        $again->run();
        self::assertSame(0, $again->getExitCode(), 'le verrou est libéré');
    }

    public function testTheWrapperRefusesInvalidArguments(): void
    {
        foreach ([['Bad Name', 'true'], ['../x', 'true']] as [$job, $cmd]) {
            $process = $this->runJob($job, [$cmd]);
            $process->run();
            self::assertSame(64, $process->getExitCode(), $job);
        }
        $noCommand = new Process([\dirname(__DIR__, 3).'/ops/bin/run-job', 'backup']);
        $noCommand->run();
        self::assertSame(64, $noCommand->getExitCode());
    }

    // ------------------------------------------------------------------ alertes

    public function testTheFailureAlertCarriesNoPersonalDataAndNeedsAnAddress(): void
    {
        self::assertFalse(static::getContainer()->get(FailureNotifier::class)->notify('civipass-backup.service'), 'aucune adresse : pas d\'envoi');
        self::ensureKernelShutdown();
        $_SERVER['OPS_ALERT_EMAIL'] = 'exploitation@exemple.fr';
        self::bootKernel();
        $notifier = static::getContainer()->get(FailureNotifier::class);

        self::assertTrue($notifier->notify('civipass-backup.service'));
        self::assertFalse($notifier->notify('civipass-backup.service; rm -rf /'), 'nom d\'unité invalide');
        $mails = $this->sentEmails();
        self::assertCount(1, $mails);
        self::assertSame('exploitation@exemple.fr', $mails[0]->getTo()[0]->getAddress());
        $text = (string) $mails[0]->getTextBody();
        self::assertStringContainsString('civipass-backup.service', $text);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $text);
        }
        self::assertSame(0, $this->command('app:ops:notify-failure')->execute(['unit' => 'civipass-anchor.service']));
    }

    public function testTheAnchorCommandEmitsThePointOnTheOperationsMailbox(): void
    {
        self::ensureKernelShutdown();
        $_SERVER['OPS_ALERT_EMAIL'] = 'exploitation@exemple.fr';
        self::bootKernel();
        $this->dueForPurge();
        $anchor = $this->command('app:retention:anchor');

        self::assertSame(0, $anchor->execute([]));
        $mails = array_values(array_filter($this->sentEmails(), static fn ($m): bool => str_contains((string) $m->getSubject(), 'ancrage')));
        self::assertCount(1, $mails, 'canal indépendant du serveur du registre');
        self::assertStringContainsString('"head"', (string) $mails[0]->getTextBody());
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, (string) $mails[0]->getTextBody());
        }
        self::assertStringContainsString('"seq"', file_get_contents(static::getContainer()->get(\App\Retention\Application\RetentionSettings::class)->anchorPath()) ?: '');
    }

    public function testTheAnchorCommandFailsLoudlyOnABrokenChain(): void
    {
        $this->dueForPurge();
        $this->anchor();
        $path = static::getContainer()->get(\App\Retention\Application\RetentionSettings::class)->registerPath();
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $lines[0] = str_replace('NOTICE', 'DOSSIER', $lines[0]);
        file_put_contents($path, implode("\n", $lines)."\n");

        $anchor = $this->command('app:retention:anchor');
        self::assertSame(1, $anchor->execute([]));
        self::assertStringContainsString('NON émis', $anchor->getDisplay());
    }
}
