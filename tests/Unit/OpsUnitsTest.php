<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Unités systemd et scripts d'exploitation fournis (LOT 05.6.7) : livrés, JAMAIS installés ; aucune tâche destructive dans les unités de mise en service ; purge, avis et alerte
 * seulement dans `optional/` et marqués NON ACTIVÉS ; le rejeu et l'exécution d'un effacement ne sont planifiés nulle part ; chaque tâche passe par l'enveloppe (maintenance, verrou,
 * délai, battement) et alerte en cas d'échec ; scripts sûrs.
 */
final class OpsUnitsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../ops';
    /** Commandes jamais planifiées, où que ce soit. */
    private const NEVER_SCHEDULED = ['app:retention:replay', 'app:retention:register-check --repair', '--adopt', 'rights', 'erasure', 'app:ops:release', 'app:ops:maintenance', 'app:accounts:candidates'];
    private const OPTIONAL = ['civipass-retention-notice', 'civipass-dormancy-alert', 'civipass-retention-purge'];

    /** @return list<string> */
    private static function units(string $dir, string $extension): array
    {
        return glob(self::ROOT.'/systemd/'.$dir.'*.'.$extension) ?: [];
    }

    private static function text(string $path): string
    {
        return (string) file_get_contents($path);
    }

    public function testEveryTimerHasItsServiceAndNothingInstallsAService(): void
    {
        foreach ([...self::units('', 'timer'), ...self::units('optional/', 'timer')] as $timer) {
            self::assertFileExists(str_replace('.timer', '.service', $timer), basename($timer));
            self::assertStringContainsString('WantedBy=timers.target', self::text($timer));
            self::assertStringContainsString('Persistent=true', self::text($timer));
        }
        foreach ([...self::units('', 'service'), ...self::units('optional/', 'service')] as $service) {
            self::assertStringNotContainsString('[Install]', self::text($service), basename($service).' : un service est lancé par son timer, jamais installé seul');
        }
    }

    public function testEveryScheduledJobGoesThroughTheWrapperAndAlertsOnFailure(): void
    {
        foreach ([...self::units('', 'service'), ...self::units('optional/', 'service')] as $service) {
            $name = basename($service, '.service');
            $text = self::text($service);
            if ('civipass-failure@' === $name) {
                self::assertStringContainsString('app:ops:notify-failure %i', $text);
                continue;
            }
            self::assertStringContainsString('OnFailure=civipass-failure@%n.service', $text, $name);
            self::assertStringContainsString('Type=oneshot', $text);
            self::assertStringContainsString('NoNewPrivileges=true', $text);
            if ('civipass-ops-status' === $name) {
                self::assertStringContainsString('app:ops:status', $text, 'la supervision contrôle les battements : elle ne passe pas par l\'enveloppe');
                continue;
            }
            self::assertMatchesRegularExpression('#ExecStart=/opt/civipass/ops/bin/run-job [a-z][a-z0-9-]+ --timeout [0-9]+ -- #', $text, $name);
            self::assertStringContainsString('SuccessExitStatus=75', $text, $name.' : une exécution refusée (maintenance, verrou) n\'est pas un échec');
        }
    }

    public function testNoDestructiveOrOutwardTaskIsInTheGoLiveUnits(): void
    {
        foreach (self::units('', 'service') as $service) {
            $name = basename($service, '.service');
            $text = self::text($service);
            self::assertNotContains($name, self::OPTIONAL);
            self::assertStringNotContainsString('--execute', $text, $name.' : aucune tâche destructive ni émettrice à la mise en service');
            self::assertStringNotContainsString('app:retention:notice', $text, $name);
            self::assertStringNotContainsString('app:event:dormancy-alert', $text, $name);
        }
        $simulation = self::text(self::ROOT.'/systemd/civipass-purge-simulation.service');
        self::assertStringContainsString('app:retention:purge --limit', $simulation);
        self::assertStringNotContainsString('--execute', $simulation, 'la purge planifiée à la mise en service est une SIMULATION');
    }

    public function testPurgeNoticeAndDormancyAreOptionalAndMarkedNotActivated(): void
    {
        foreach (self::OPTIONAL as $name) {
            foreach (['service', 'timer'] as $kind) {
                $path = self::ROOT.'/systemd/optional/'.$name.'.'.$kind;
                self::assertFileExists($path);
                self::assertStringStartsWith('# NON ACTIVÉ', self::text($path), basename($path));
            }
            self::assertFileDoesNotExist(self::ROOT.'/systemd/'.$name.'.service');
        }
        self::assertStringContainsString('app:retention:purge --execute', self::text(self::ROOT.'/systemd/optional/civipass-retention-purge.service'));
        self::assertSame(3, \count(self::units('optional/', 'service')), 'rien d\'autre dans optional/');
    }

    public function testReplayAndErasureAreNeverScheduledAnywhere(): void
    {
        foreach ([...self::units('', 'service'), ...self::units('optional/', 'service'), ...self::units('', 'timer'), ...self::units('optional/', 'timer')] as $unit) {
            $text = strtolower(self::text($unit));
            foreach (self::NEVER_SCHEDULED as $forbidden) {
                if ('rights' === $forbidden && str_contains(basename($unit), 'rights-overdue')) {
                    self::assertStringNotContainsString('erase', $text);
                    continue;
                }
                self::assertStringNotContainsString($forbidden, $text, basename($unit));
            }
        }
    }

    public function testTheUnitsAreSyntacticallyValidForSystemd(): void
    {
        $analyze = new Process(['which', 'systemd-analyze']);
        $analyze->run();
        if (!$analyze->isSuccessful()) {
            self::markTestSkipped('systemd-analyze indisponible.');
        }
        $files = [...self::units('', 'service'), ...self::units('', 'timer'), ...self::units('optional/', 'service'), ...self::units('optional/', 'timer')];
        $check = new Process(['systemd-analyze', 'verify', '--man=no', ...$files]);
        $check->run();
        $output = $check->getOutput().$check->getErrorOutput();
        foreach (['Unknown key', 'Unknown section', 'Failed to parse', 'bad setting', 'Invalid', 'Missing', 'syntax'] as $problem) {
            self::assertStringNotContainsString($problem, $output, $problem);
        }
    }

    public function testTheScriptsAreSyntacticallyValidAndSafe(): void
    {
        foreach (['bin/run-job', 'backup/backup.sh', 'backup/verify-backup.sh'] as $script) {
            $path = self::ROOT.'/'.$script;
            self::assertTrue(is_executable($path), $script);
            $syntax = new Process(['bash', '-n', $path]);
            $syntax->run();
            self::assertTrue($syntax->isSuccessful(), $script.' '.$syntax->getErrorOutput());
        }
        foreach (['backup/backup.sh', 'backup/verify-backup.sh'] as $script) {
            self::assertStringContainsString('set -euo pipefail', self::text(self::ROOT.'/'.$script), $script);
        }
        $backup = self::text(self::ROOT.'/backup/backup.sh');
        self::assertStringContainsString("-regex '.*/[0-9]{8}T[0-9]{6}Z'", $backup, 'rotation limitée aux répertoires horodatés');
        self::assertStringContainsString('rm -rf -- {}', $backup);
        self::assertStringContainsString('BACKUP_RETENTION_DAYS:-35', $backup, 'rotation de 35 jours (hypothèse A1)');
    }

    public function testBackupDryRunExecutesNothingAndRefusesUnsafeSettings(): void
    {
        $dir = sys_get_temp_dir().'/civipass-backup-'.bin2hex(random_bytes(4));
        $dry = new Process([self::ROOT.'/backup/backup.sh', '--dry-run'], null, ['BACKUP_DIR' => $dir, 'DB_NAME' => 'civipass', 'PATH' => (string) getenv('PATH')]);
        $dry->run();
        self::assertSame(0, $dry->getExitCode(), $dry->getErrorOutput());
        self::assertStringContainsString('[dry-run] mysqldump', $dry->getOutput());
        self::assertDirectoryDoesNotExist($dir, 'le mode dry-run ne crée rien');

        foreach ([['BACKUP_DIR' => 'relatif/chemin'], ['BACKUP_DIR' => '/'], ['BACKUP_DIR' => $dir, 'BACKUP_RETENTION_DAYS' => '0'], ['BACKUP_DIR' => $dir, 'BACKUP_RETENTION_DAYS' => 'abc']] as $env) {
            $bad = new Process([self::ROOT.'/backup/backup.sh', '--dry-run'], null, $env + ['DB_NAME' => 'civipass', 'PATH' => (string) getenv('PATH')]);
            $bad->run();
            self::assertSame(64, $bad->getExitCode(), json_encode($env, \JSON_THROW_ON_ERROR));
        }
        $verify = new Process([self::ROOT.'/backup/verify-backup.sh', '--dry-run'], null, ['BACKUP_DIR' => $dir, 'DB_NAME' => 'civipass', 'PATH' => (string) getenv('PATH')]);
        $verify->run();
        self::assertSame(1, $verify->getExitCode(), 'aucune sauvegarde : échec explicite');
    }

    public function testNoUnitOrScriptEnablesASwitch(): void
    {
        foreach ([...self::units('', 'service'), ...self::units('optional/', 'service'), self::ROOT.'/backup/backup.sh', self::ROOT.'/bin/run-job'] as $file) {
            self::assertDoesNotMatchRegularExpression('/(RETENTION_PURGE_ENABLED|RIGHTS_ERASURE_EXECUTION_ENABLED|PUBLIC_VERIFICATION_ENABLED)\s*=\s*(1|true)/i', self::text($file), basename($file));
        }
    }
}
