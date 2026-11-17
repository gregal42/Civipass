<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous du script de test de charge de la recette (`ops/recette/charge-verification.sh`, LOT 07.3.4) : il ne peut viser qu'un serveur de recette
 * en HTTPS, avec une confirmation explicite, un jeton bien formé et des bornes. Aucune requête réseau n'est émise par ces tests (tous les cas sont refusés avant).
 */
final class RecetteLoadScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../../ops/recette/charge-verification.sh';
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567';
    private const CONFIRM = 'serveur-de-recette-donnees-fictives';

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function script(array $args, array $env = []): array
    {
        $process = proc_open(['bash', self::SCRIPT, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    public function testTheScriptExistsIsExecutableAndHasValidSyntax(): void
    {
        self::assertFileExists(self::SCRIPT);
        self::assertTrue(is_executable(self::SCRIPT));
        [$code, , $err] = $this->script(['--help']);
        self::assertSame(0, $code, $err);
        [$syntax, , $syntaxError] = (static function (): array {
            $p = proc_open(['bash', '-n', self::SCRIPT], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $o = (string) stream_get_contents($pipes[1]);
            $e = (string) stream_get_contents($pipes[2]);

            return [proc_close($p), $o, $e];
        })();
        self::assertSame(0, $syntax, $syntaxError);
    }

    public function testItRefusesToRunWithoutTheExplicitRecetteConfirmation(): void
    {
        [$code, , $err] = $this->script(['--base', 'https://recette.exemple.fr', '--token', self::TOKEN]);

        self::assertSame(2, $code);
        self::assertStringContainsString('CIVIPASS_RECETTE_CONFIRM', $err);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'HTTP en clair' => [['--base', 'http://recette.exemple.fr', '--token', self::TOKEN], 'https://'];
        yield 'domaine d\'exemple' => [['--base', 'https://civipass.example', '--token', self::TOKEN], 'exemple'];
        yield 'localhost' => [['--base', 'https://localhost', '--token', self::TOKEN], 'refusé'];
        yield 'sans base' => [['--token', self::TOKEN], '--base requis'];
        yield 'sans jeton' => [['--base', 'https://recette.exemple.fr'], '--token requis'];
        yield 'jeton mal formé' => [['--base', 'https://recette.exemple.fr', '--token', 'PAS-UN-JETON'], 'bien formé'];
        yield 'trop de requêtes' => [['--base', 'https://recette.exemple.fr', '--token', self::TOKEN, '--requests', '999999'], '--requests entre'];
        yield 'trop de simultanéité' => [['--base', 'https://recette.exemple.fr', '--token', self::TOKEN, '--concurrency', '5000'], '--concurrency entre'];
        yield 'option inconnue' => [['--base', 'https://recette.exemple.fr', '--token', self::TOKEN, '--destroy'], 'option inconnue'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('refusals')]
    public function testItRefusesUnsafeOrMalformedInvocationsBeforeAnyRequestAndNeverEchoesTheToken(array $args, string $reason): void
    {
        [$code, $out, $err] = $this->script($args, ['CIVIPASS_RECETTE_CONFIRM' => self::CONFIRM]);

        self::assertSame(2, $code, $out.$err);
        self::assertStringContainsString($reason, $err);
        self::assertStringNotContainsString(self::TOKEN, $out.$err, 'le jeton n\'est jamais affiché');
        self::assertSame('', $out, 'aucun résultat de mesure : rien n\'a été envoyé');
    }

    public function testTheScriptOnlySendsGetRequestsAndNeverWrites(): void
    {
        $source = (string) file_get_contents(self::SCRIPT);

        self::assertStringNotContainsString('-X POST', $source);
        self::assertStringNotContainsString('--data', $source);
        self::assertStringNotContainsString(' -d ', $source);
        self::assertStringNotContainsString('rm -rf', $source);
        self::assertStringContainsString("n'envoie que des requêtes GET", $source);
    }
}
