<?php

declare(strict_types=1);

namespace App\Tests\Unit\Retention;

use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RetentionSettings;
use App\Retention\Domain\ChainReport;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\TestCase;

/**
 * Registre externe v2 chaîné (LOT 05.6.7, A2) : continuité, détection d'une ligne modifiée, supprimée, réordonnée, non chaînée ou illisible, refus d'étendre une queue corrompue,
 * écritures simultanées. La chaîne seule ne détecte PAS une réécriture complète recalculée (rôle de l'ancrage, voir `RegisterProofTest`).
 */
final class RegisterChainTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/civipass-chain-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
        $this->path = $this->dir.'/register.jsonl';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function register(): PurgeRegisterFile
    {
        return new PurgeRegisterFile(new RetentionSettings(30, false, $this->path));
    }

    private function fill(int $n): void
    {
        for ($i = 1; $i <= $n; ++$i) {
            $this->register()->appendLine(['id' => 'rec-'.$i, 'kind' => 'DOSSIER', 'dossier' => 'dossier-'.$i]);
        }
    }

    /** @return list<string> */
    private function rawLines(): array
    {
        return file($this->path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /** @param list<string> $lines */
    private function write(array $lines): void
    {
        file_put_contents($this->path, implode("\n", $lines)."\n");
    }

    /** @return list<string> */
    private function codes(ChainReport $report): array
    {
        return array_map(static fn (array $b): string => $b['code'].'@'.$b['line'], $report->breaks);
    }

    public function testEveryLineIsChainedWithAContinuousSequence(): void
    {
        $this->fill(4);
        $report = $this->register()->verify();

        self::assertTrue($report->intact());
        self::assertSame([4, 4], [$report->lines, $report->lastSeq]);
        $lines = array_map(static fn (string $l): array => json_decode($l, true), $this->rawLines());
        self::assertSame([1, 2, 3, 4], array_column($lines, 'seq'));
        self::assertSame(PurgeRegisterFile::GENESIS, $lines[0]['prev']);
        self::assertSame(hash('sha256', $this->rawLines()[1]), $lines[2]['prev']);
        self::assertSame(hash('sha256', $this->rawLines()[3]), $report->headHash);
    }

    public function testAMissingFileAndAnEmptyRegisterAreNotBreaks(): void
    {
        $report = $this->register()->verify();
        self::assertFalse($report->fileExists);
        self::assertTrue($report->intact());
        self::assertSame(0, $report->lastSeq);
    }

    public function testAModifiedLineBreaksTheChainAtTheNextLine(): void
    {
        $this->fill(4);
        $lines = $this->rawLines();
        $lines[1] = str_replace('rec-2', 'rec-X', $lines[1]);
        $this->write($lines);

        self::assertSame(['PREVIOUS_HASH_MISMATCH@3'], $this->codes($this->register()->verify()));
    }

    public function testADeletedLineIsDetectedWhereverItWas(): void
    {
        $this->fill(5);
        $lines = $this->rawLines();
        unset($lines[2]);
        $this->write(array_values($lines));

        $codes = $this->codes($this->register()->verify());
        self::assertContains('SEQUENCE_BREAK@3', $codes);
        self::assertContains('PREVIOUS_HASH_MISMATCH@3', $codes);
    }

    public function testReorderedLinesAreDetected(): void
    {
        $this->fill(4);
        $lines = $this->rawLines();
        [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
        $this->write($lines);

        self::assertNotSame([], $this->register()->verify()->breaks);
    }

    public function testAnUnchainedLegacyLineAndAnUnreadableLineAreBreaks(): void
    {
        $this->fill(2);
        file_put_contents($this->path, '{"id":"legacy","kind":"DOSSIER"}'."\n", \FILE_APPEND);
        self::assertSame([ChainReport::BREAK_UNCHAINED.'@3'], $this->codes($this->register()->verify()));

        $this->write(array_slice($this->rawLines(), 0, 2));
        file_put_contents($this->path, "pas du json\n", \FILE_APPEND);
        self::assertSame([ChainReport::BREAK_INVALID.'@3'], $this->codes($this->register()->verify()));
    }

    public function testTheChainIsNeverExtendedOverACorruptTail(): void
    {
        $this->fill(2);
        file_put_contents($this->path, "pas du json\n", \FILE_APPEND);
        $before = (string) file_get_contents($this->path);

        try {
            $this->register()->appendLine(['id' => 'rec-3', 'kind' => 'DOSSIER']);
            self::fail('Ligne ajoutée au-dessus d\'une queue illisible.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Queue du registre illisible', $e->getMessage());
        }
        self::assertSame($before, (string) file_get_contents($this->path), 'aucune écriture silencieuse');
    }

    public function testAnAppendCannotForgeItsOwnChainFields(): void
    {
        $this->register()->appendLine(['id' => 'a', 'kind' => 'NOTICE', 'seq' => 99, 'prev' => 'forged', 'v' => 7]);
        $line = json_decode($this->rawLines()[0], true);

        self::assertSame([2, 1, PurgeRegisterFile::GENESIS], [$line['v'], $line['seq'], $line['prev']]);
    }

    public function testSimultaneousAppendersKeepAContinuousIntactChain(): void
    {
        $results = ParallelPhp::run('register-append.php', [[$this->path, '25', 'p1'], [$this->path, '25', 'p2'], [$this->path, '25', 'p3'], [$this->path, '25', 'p4']]);
        foreach ($results as $r) {
            self::assertSame('{"ok":true}', $r['stdout'], $r['stderr']);
        }

        $report = $this->register()->verify();
        self::assertSame([100, 100], [$report->lines, $report->lastSeq]);
        self::assertTrue($report->intact(), implode(',', $this->codes($report)));
        self::assertCount(100, $this->register()->read()['ids']);
    }
}
