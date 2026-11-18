<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Identity\Application\SessionLifetime;
use App\Request\UI\Http\PublicRequestDraft;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * M6 (LOT 10) : la configuration PHP proposée pour les sessions (exemple livré, jamais appliqué) est COHÉRENTE avec les durées de l'application et se
 * comporte comme annoncé quand PHP la met réellement en œuvre : une session valide pour l'application n'est jamais supprimée trop tôt.
 */
final class SessionGcConfigurationTest extends TestCase
{
    private const EXAMPLE = __DIR__.'/../../ops/php/civipass-session.ini.example';

    private static function maxLifetime(): int
    {
        self::assertSame(1, preg_match('/^session\.gc_maxlifetime\s*=\s*(\d+)\s*$/m', (string) file_get_contents(self::EXAMPLE), $m), 'gc_maxlifetime actif dans l’exemple');

        return (int) $m[1];
    }

    public function testTheProposedLifetimeCoversTheLongestIdleDelayTheApplicationCanAllow(): void
    {
        self::assertGreaterThanOrEqual(SessionLifetime::MAX_IDLE, self::maxLifetime(), 'inactivité maximale autorisée par l’application');
        self::assertGreaterThan(SessionLifetime::MAX_IDLE, self::maxLifetime(), 'avec une marge');
        self::assertGreaterThan(PublicRequestDraft::TTL_SECONDS, self::maxLifetime());
        // Défauts : agents 30 min, CWS 15 min.
        $default = new SessionLifetime();
        self::assertGreaterThan($default->agentIdle, self::maxLifetime());
        self::assertGreaterThan($default->cwsIdle, self::maxLifetime());
    }

    public function testTheApplicationLimitsAreUnchangedByThisProposal(): void
    {
        $default = new SessionLifetime();

        self::assertSame(1800, $default->agentIdle);
        self::assertSame(43200, $default->agentAbsolute);
        self::assertSame(900, $default->cwsIdle);
        self::assertSame(28800, $default->cwsAbsolute);
    }

    public function testPhpKeepsAFileIdleForTheLongestAllowedDelayAndRemovesAnOlderOne(): void
    {
        $dir = sys_get_temp_dir().'/civipass-gc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $files = ['actif-26min' => 26 * 60, 'actif-30min' => 1800, 'inactif-max-autorise' => SessionLifetime::MAX_IDLE, 'trop-ancien' => self::maxLifetime() + 600];
        foreach ($files as $name => $age) {
            file_put_contents($dir.'/sess_'.$name, 'x');
            touch($dir.'/sess_'.$name, time() - $age);
        }

        $process = new Process([\PHP_BINARY, '-d', 'session.save_path='.$dir, '-d', 'session.gc_maxlifetime='.self::maxLifetime(), '-r', 'session_start(); session_gc(); session_write_close();']);
        $process->mustRun();

        foreach (['actif-26min', 'actif-30min', 'inactif-max-autorise'] as $name) {
            self::assertFileExists($dir.'/sess_'.$name, $name.' : une session encore valide pour l’application n’est pas supprimée');
        }
        self::assertFileDoesNotExist($dir.'/sess_trop-ancien');
        array_map('unlink', glob($dir.'/sess_*') ?: []);
        @rmdir($dir);
    }

    public function testTheCurrentDefaultOfOneThousandFourHundredAndFortySecondsWouldRemoveAValidSession(): void
    {
        $dir = sys_get_temp_dir().'/civipass-gc-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/sess_actif', 'x');
        touch($dir.'/sess_actif', time() - 26 * 60);

        (new Process([\PHP_BINARY, '-d', 'session.save_path='.$dir, '-d', 'session.gc_maxlifetime=1440', '-r', 'session_start(); session_gc(); session_write_close();']))->mustRun();

        self::assertFileDoesNotExist($dir.'/sess_actif', 'constat de départ : 26 min d’inactivité supprimées avec 1 440 s alors que l’application en autorise 30');
        @rmdir($dir);
    }
}
