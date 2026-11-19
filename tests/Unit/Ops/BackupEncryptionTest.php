<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ops;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * SEC-004 : sauvegardes chiffrées (age) AVANT toute copie hors serveur ; clé privée jamais sur le serveur ; intégrité vérifiable sans clé ; restauration
 * prouvée avec la clé. `mysqldump`, `mysql`, `rsync` et `ssh` sont remplacés par des doublures déterministes (aucune base, aucun réseau) ; `age` est le vrai.
 */
final class BackupEncryptionTest extends TestCase
{
    private const OPS = __DIR__.'/../../../ops';
    private const MARKER = 'MARQUEUR-DONNEE-PERSONNELLE-Dupont-Camille';

    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir().'/civipass-bk-'.bin2hex(random_bytes(5));
        mkdir($this->work.'/stubs', 0o700, true);
        // Doublures : le dump contient un marqueur « personnel » ; `mysql` journalise ses appels et capture le SQL restauré.
        file_put_contents($this->work.'/stubs/mysqldump', "#!/bin/sh\n[ -n \"\$STUB_DUMP_FAIL\" ] && exit 3\necho '-- dump ".self::MARKER."'\necho 'CREATE TABLE t (id int);'\n");
        file_put_contents($this->work.'/stubs/mysql', <<<'SH'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
case "$*" in
  *"SELECT COUNT"*) echo "${STUB_TABLES:-0}" ;;
  *"-e"*) : ;;
  *) cat > "$STUB_CAPTURE" ;;
esac
SH);
        file_put_contents($this->work.'/stubs/rsync', "#!/bin/sh\necho \"RSYNC \$*\" >> \"\$STUB_LOG\"\nfor a in \"\$@\"; do [ -d \"\$a\" ] && ls -1 \"\$a\" >> \"\$STUB_LOG\"; done\nexit 0\n");
        chmod($this->work.'/stubs/mysqldump', 0o755);
        chmod($this->work.'/stubs/mysql', 0o755);
        chmod($this->work.'/stubs/rsync', 0o755);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', '--', $this->work]))->run();
    }

    private function needAge(): void
    {
        $probe = new Process(['age', '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('La commande « age » est requise (paquet age ; à ajouter à l\'image de développement).');
        }
    }

    /** @return array{public: string, identity: string} */
    private function keyPair(string $name): array
    {
        $file = $this->work.'/'.$name.'.key';
        $process = new Process(['age-keygen', '-o', $file]);
        $process->mustRun();
        chmod($file, 0o600);
        preg_match('/age1[0-9a-z]{58}/', $process->getErrorOutput().$process->getOutput(), $m);

        return ['public' => $m[0], 'identity' => $file];
    }

    /**
     * @param array<string, string> $env
     * @param list<string>          $command
     */
    private function exec(array $command, array $env = []): Process
    {
        $process = new Process($command, null, $env + [
            'PATH' => $this->work.'/stubs:'.getenv('PATH'),
            'STUB_LOG' => $this->work.'/calls.log',
            'STUB_CAPTURE' => $this->work.'/restored.sql',
        ]);
        $process->run();

        return $process;
    }

    /** @return array<string, string> */
    private function backupEnv(string $recipients): array
    {
        mkdir($this->work.'/storage', 0o700, true);
        file_put_contents($this->work.'/storage/justificatif.txt', self::MARKER.' pièce jointe');
        file_put_contents($this->work.'/register.jsonl', '{"chain":"'.self::MARKER.'"}'."\n");

        return ['BACKUP_DIR' => $this->work.'/backups', 'DB_NAME' => 'civipass', 'STORAGE_DIR' => $this->work.'/storage', 'REGISTER_PATH' => $this->work.'/register.jsonl',
            'BACKUP_AGE_RECIPIENTS' => $recipients];
    }

    private function backupDir(): string
    {
        $dirs = glob($this->work.'/backups/*', \GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $dirs, 'une seule sauvegarde attendue');

        return $dirs[0];
    }

    public function testBackupRefusesToRunWithoutAValidPublicKeyAndNeverWithThePrivateKey(): void
    {
        $base = ['BACKUP_DIR' => $this->work.'/backups', 'DB_NAME' => 'civipass'];
        foreach ([
            'aucun destinataire' => [],
            'clé invalide' => ['BACKUP_AGE_RECIPIENTS' => 'pas-une-cle'],
            'clé privée au lieu de la publique' => ['BACKUP_AGE_RECIPIENTS' => 'AGE-SECRET-KEY-1QQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQQ'],
            'clé privée présente sur le serveur' => ['BACKUP_AGE_RECIPIENTS' => 'age1'.str_repeat('q', 58), 'BACKUP_AGE_IDENTITY' => '/etc/civipass/backup.key'],
        ] as $label => $extra) {
            $process = $this->exec([self::OPS.'/backup/backup.sh'], $base + $extra);
            self::assertSame(64, $process->getExitCode(), $label.' : '.$process->getErrorOutput());
            self::assertDirectoryDoesNotExist($this->work.'/backups', $label.' : rien ne doit être écrit en clair en repli');
        }
    }

    public function testABackupNeverContainsPlaintextAndDecryptsExactlyWithThePrivateKey(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $process = $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']));
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $dir = $this->backupDir();
        $names = array_map('basename', glob($dir.'/*') ?: []);
        sort($names);
        self::assertSame(['SHA256SUMS', 'db.sql.gz.age', 'purge-register.jsonl.copy.age', 'storage.tar.gz.age'], $names, 'uniquement des fichiers chiffrés');
        foreach (glob($dir.'/*') ?: [] as $file) {
            self::assertStringNotContainsString(self::MARKER, (string) file_get_contents($file), basename($file).' : donnée en clair');
        }
        self::assertStringNotContainsString('age-secret-key', strtolower((string) file_get_contents($dir.'/SHA256SUMS')));

        $dump = new Process(['sh', '-c', 'age -d -i '.escapeshellarg($keys['identity']).' '.escapeshellarg($dir.'/db.sql.gz.age').' | gunzip -c']);
        $dump->mustRun();
        self::assertStringContainsString(self::MARKER, $dump->getOutput(), 'la clé privée restitue exactement le dump');
        $register = new Process(['age', '-d', '-i', $keys['identity'], $dir.'/purge-register.jsonl.copy.age']);
        $register->mustRun();
        self::assertStringContainsString(self::MARKER, $register->getOutput());
    }

    public function testEitherOfTwoRecipientsCanDecryptAndAnotherKeyCannot(): void
    {
        $this->needAge();
        $ops = $this->keyPair('ops');
        $recovery = $this->keyPair('secours');
        $stranger = $this->keyPair('etranger');
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($ops['public'].', '.$recovery['public']))->mustRun();
        $file = $this->backupDir().'/db.sql.gz.age';

        foreach ([$ops, $recovery] as $pair) {
            $ok = new Process(['age', '-d', '-i', $pair['identity'], $file]);
            $ok->run();
            self::assertTrue($ok->isSuccessful());
        }
        $ko = new Process(['age', '-d', '-i', $stranger['identity'], $file]);
        $ko->run();
        self::assertFalse($ko->isSuccessful(), 'une clé étrangère ne déchiffre pas');
    }

    public function testAFailedBackupLeavesNoPartialDirectory(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $process = $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']) + ['STUB_DUMP_FAIL' => '1']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame([], glob($this->work.'/backups/*') ?: [], 'jamais une sauvegarde partielle prise pour la plus récente');
    }

    public function testTheServerVerifiesIntegrityWithoutTheKeyAndSaysThatRestorationIsNotProven(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']))->mustRun();
        $env = ['BACKUP_DIR' => $this->work.'/backups', 'DB_NAME' => 'civipass'];

        $ok = $this->exec([self::OPS.'/backup/verify-backup.sh'], $env);
        self::assertSame(0, $ok->getExitCode(), $ok->getErrorOutput());
        self::assertStringContainsString('RESTAURATION NON VÉRIFIÉE', $ok->getOutput());

        $file = $this->backupDir().'/db.sql.gz.age';
        file_put_contents($file, 'x', \FILE_APPEND);
        self::assertNotSame(0, $this->exec([self::OPS.'/backup/verify-backup.sh'], $env)->getExitCode(), 'altération détectée par la somme de contrôle');
    }

    public function testAClearFileInABackupMakesTheVerificationFail(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']))->mustRun();
        file_put_contents($this->backupDir().'/db.sql.gz', 'en clair');
        $env = ['BACKUP_DIR' => $this->work.'/backups', 'DB_NAME' => 'civipass'];

        $process = $this->exec([self::OPS.'/backup/verify-backup.sh'], $env);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('non chiffré', $process->getErrorOutput());
    }

    public function testRestorationWithThePrivateKeyRebuildsTheDatabaseAndTheStorage(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']))->mustRun();
        $args = [self::OPS.'/backup/restore-from-age.sh', $this->backupDir(), '--identity', $keys['identity'], '--database', 'civipass_restore'];

        $simulation = $this->exec($args);
        self::assertSame(0, $simulation->getExitCode(), $simulation->getErrorOutput());
        self::assertStringContainsString('[simulation]', $simulation->getOutput());
        self::assertFileDoesNotExist($this->work.'/restored.sql', 'la simulation n\'écrit rien');

        $real = $this->exec([...$args, '--storage-dir', $this->work.'/storage-restored', '--archive-dir', $this->work.'/archive', '--apply']);
        self::assertSame(0, $real->getExitCode(), $real->getErrorOutput().$real->getOutput());
        self::assertStringContainsString(self::MARKER, (string) file_get_contents($this->work.'/restored.sql'), 'le SQL déchiffré est transmis à MySQL');
        self::assertStringContainsString(self::MARKER, (string) file_get_contents($this->work.'/storage-restored/justificatif.txt'));
        self::assertFileExists($this->work.'/archive/purge-register.jsonl.copy');
    }

    public function testRestorationRefusesAWrongKeyALooseKeyFileAndANonEmptyDatabaseBeforeWritingAnything(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        $stranger = $this->keyPair('etranger');
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']))->mustRun();
        $base = [self::OPS.'/backup/restore-from-age.sh', $this->backupDir(), '--database', 'civipass_restore', '--apply'];

        $wrong = $this->exec([...$base, '--identity', $stranger['identity']]);
        self::assertSame(1, $wrong->getExitCode());
        self::assertFileDoesNotExist($this->work.'/restored.sql');
        self::assertFileDoesNotExist($this->work.'/calls.log', 'aucune base créée avec une mauvaise clé');

        chmod($keys['identity'], 0o644);
        $loose = $this->exec([...$base, '--identity', $keys['identity']]);
        self::assertSame(64, $loose->getExitCode(), 'clé privée lisible par d\'autres : refusée');
        chmod($keys['identity'], 0o600);

        $nonEmpty = $this->exec([...$base, '--identity', $keys['identity']], ['STUB_TABLES' => '12']);
        self::assertSame(1, $nonEmpty->getExitCode());
        self::assertFileDoesNotExist($this->work.'/restored.sql', 'une base non vide n\'est jamais écrasée sans --force');
    }

    public function testNothingInClearLeavesTheServerWhenCopyingOffsite(): void
    {
        $this->needAge();
        $keys = $this->keyPair('ops');
        file_put_contents($this->work.'/ssh.key', 'k');
        file_put_contents($this->work.'/known_hosts', 'h');
        $offsite = ['OFFSITE_TARGET' => 'sauvegarde@hote.exemple:/srv/civipass', 'OFFSITE_SSH_KEY' => $this->work.'/ssh.key', 'OFFSITE_KNOWN_HOSTS' => $this->work.'/known_hosts',
            'BACKUP_DIR' => $this->work.'/backups', 'REGISTER_PATH' => $this->work.'/register.jsonl'];
        $this->exec([self::OPS.'/backup/backup.sh'], $this->backupEnv($keys['public']))->mustRun();

        $encrypted = $this->exec([self::OPS.'/backup/offsite-sync.sh', 'backups'], $offsite);
        self::assertSame(0, $encrypted->getExitCode(), $encrypted->getErrorOutput());
        self::assertStringContainsString('RSYNC', (string) file_get_contents($this->work.'/calls.log'));
        unlink($this->work.'/calls.log');

        file_put_contents($this->backupDir().'/db.sql.gz', 'en clair');
        $clear = $this->exec([self::OPS.'/backup/offsite-sync.sh', 'backups'], $offsite);
        self::assertSame(1, $clear->getExitCode());
        self::assertStringContainsString('copie refusée', $clear->getErrorOutput());
        self::assertFileDoesNotExist($this->work.'/calls.log', 'rien n\'est envoyé');

        $noKey = $this->exec([self::OPS.'/backup/offsite-sync.sh', 'register'], $offsite);
        self::assertSame(64, $noKey->getExitCode(), 'les preuves ne partent pas sans chiffrement');
        $proofs = $this->exec([self::OPS.'/backup/offsite-sync.sh', 'register'], $offsite + ['BACKUP_AGE_RECIPIENTS' => $keys['public']]);
        self::assertSame(0, $proofs->getExitCode(), $proofs->getErrorOutput());
        $log = (string) file_get_contents($this->work.'/calls.log');
        self::assertStringContainsString('purge-register-', $log);
        self::assertStringContainsString('.jsonl.age', $log);
        self::assertStringNotContainsString('.jsonl'."\n", $log, 'aucun registre en clair dans le répertoire copié');
    }
}
