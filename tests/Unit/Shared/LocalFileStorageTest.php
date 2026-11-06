<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\LocalFileStorage;
use App\Tests\Support\SampleFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalFileStorageTest extends TestCase
{
    private string $root;
    private LocalFileStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/civipass-storage-'.bin2hex(random_bytes(4));
        $this->storage = new LocalFileStorage($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testATemporaryFileGetsAnOpaqueKeyAndIsStoredPrivately(): void
    {
        $source = SampleFiles::temporaryFile(SampleFiles::pdf());
        $key = $this->storage->putTemporary($source);

        self::assertMatchesRegularExpression('/^tmp\/[a-f0-9]{32}$/', $key);
        self::assertNotSame($key, $this->storage->putTemporary($source), 'clés imprévisibles, jamais déterministes');
        self::assertSame(SampleFiles::pdf(), stream_get_contents($this->storage->readStream($key)));
        $path = $this->root.'/'.$key;
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->root.'/tmp')), -4));
        self::assertStringNotContainsString(basename($source), $path, 'le nom du client ne sert jamais de chemin');
        self::assertSame([], glob($this->root.'/tmp/*.part'), 'aucun fichier partiel laissé');
    }

    public function testPromoteKeepsTheTemporaryFileUntilItIsDeleted(): void
    {
        $temporary = $this->storage->putTemporary(SampleFiles::temporaryFile(SampleFiles::png()));
        $permanent = $this->storage->promote($temporary);

        self::assertMatchesRegularExpression('/^files\/[a-f0-9]{32}$/', $permanent);
        self::assertTrue($this->storage->exists($temporary));
        self::assertSame(SampleFiles::png(), stream_get_contents($this->storage->readStream($permanent)));

        $this->storage->delete($temporary);
        self::assertFalse($this->storage->exists($temporary));
        self::assertTrue($this->storage->exists($permanent), 'la pièce rattachée survit à la suppression du fichier temporaire');
    }

    public function testDeleteIsIdempotent(): void
    {
        $key = $this->storage->putTemporary(SampleFiles::temporaryFile(SampleFiles::pdf()));
        $this->storage->delete($key);
        $this->storage->delete($key);
        $this->storage->delete('tmp/'.str_repeat('a', 32));

        self::assertFalse($this->storage->exists($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedKeys(): iterable
    {
        yield 'traversée' => ['../../etc/passwd'];
        yield 'traversée dans la zone' => ['tmp/../files/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'zone inconnue' => ['other/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
        yield 'clé trop courte' => ['tmp/abc'];
        yield 'majuscules' => ['tmp/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'];
        yield 'octet nul' => ["tmp/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\0"];
        yield 'vide' => [''];
    }

    /**
     * @dataProvider forgedKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forgedKeys')]
    public function testAnyKeyNotGeneratedByTheStorageIsRefused(string $key): void
    {
        foreach ([fn () => $this->storage->exists($key), fn () => $this->storage->delete($key), fn () => $this->storage->promote($key)] as $operation) {
            try {
                $operation();
                self::fail('Clé forgée acceptée : '.$key);
            } catch (\InvalidArgumentException|\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPurgeOnlyDeletesExpiredTemporaryFilesAndNeverPermanentOnes(): void
    {
        $old = $this->storage->putTemporary(SampleFiles::temporaryFile(SampleFiles::pdf()));
        $fresh = $this->storage->putTemporary(SampleFiles::temporaryFile(SampleFiles::pdf()));
        $promotedFromOld = $this->storage->promote($old);
        $permanentOnly = $this->storage->promote($this->storage->putTemporary(SampleFiles::temporaryFile(SampleFiles::png())));
        touch($this->root.'/'.$old, time() - 25 * 3600);
        touch($this->root.'/'.$permanentOnly, time() - 90 * 24 * 3600);
        touch($this->root.'/'.$promotedFromOld, time() - 90 * 24 * 3600);

        $deleted = $this->storage->deleteTemporaryOlderThan(new \DateTimeImmutable('-24 hours'));

        // Deux noms temporaires expirés (l'ancien et celui du fichier rattaché) : seules ces entrées temporaires disparaissent.
        self::assertSame(2, $deleted);
        self::assertFalse($this->storage->exists($old));
        self::assertTrue($this->storage->exists($fresh));
        self::assertTrue($this->storage->exists($promotedFromOld), 'une pièce rattachée n\'est jamais purgée');
        self::assertTrue($this->storage->exists($permanentOnly));
    }

    public function testPurgeWithoutStorageDirectoryDoesNothing(): void
    {
        self::assertSame(0, $this->storage->deleteTemporaryOlderThan(new \DateTimeImmutable()));
    }
}
