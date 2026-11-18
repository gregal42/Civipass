<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Request\Application\AbandonedDraftPurger;
use App\Shared\Application\FileStorageInterface;
use App\Tests\Support\SampleFiles as S;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Purge serveur des brouillons publics abandonnés (LOT 10, O-2, M3) : le citoyen ne revient jamais, les données sont quand même supprimées
 * (fichier de session et justificatifs temporaires) ; une session authentifiée n'est jamais touchée ; rien n'est supprimé avant 30 minutes.
 */
final class AbandonedDraftPurgerTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/civipass-sessions-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
        parent::tearDown();
    }

    private function storage(): FileStorageInterface
    {
        return self::getContainer()->get(FileStorageInterface::class);
    }

    private function temporaryUpload(): string
    {
        return $this->storage()->putTemporary(S::temporaryFile(S::pdf()));
    }

    /**
     * Écrit une session au format du gestionnaire « php » de PHP, tel que Symfony la produit.
     *
     * @param array<string, mixed> $attributes
     */
    private function session(string $id, array $attributes, int $ageSeconds): string
    {
        $path = $this->dir.'/sess_'.$id;
        file_put_contents($path, '_sf2_attributes|'.serialize($attributes).'_sf2_meta|'.serialize(['u' => time() - $ageSeconds, 'c' => time() - $ageSeconds, 'l' => 0]));
        touch($path, time() - $ageSeconds);

        return $path;
    }

    /** @return array{scanned: int, drafts: int, files: int, skipped_authenticated: int} */
    private function purge(bool $dryRun = false): array
    {
        return self::getContainer()->get(AbandonedDraftPurger::class)->purge($this->dir, 1800, time(), $dryRun);
    }

    /** @return array<string, mixed> */
    private function draft(string $key): array
    {
        return ['last' => 'Martin', 'email' => 'camille@exemple.fr', 'files' => [['key' => $key, 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10]], '__touched' => time()];
    }

    public function testAnAbandonedAnonymousDraftIsDeletedWithItsTemporaryDocuments(): void
    {
        $key = $this->temporaryUpload();
        $file = $this->session('abandoned', ['_csrf/x' => 'jeton', 'public_request.EVT-2026-000001' => $this->draft($key)], 1801);

        $result = $this->purge();

        self::assertSame(['scanned' => 1, 'drafts' => 1, 'files' => 1, 'skipped_authenticated' => 0], $result);
        self::assertFileDoesNotExist($file, 'le fichier de session (donnée personnelle) est supprimé');
        self::assertFalse($this->storage()->exists($key), 'le justificatif temporaire est supprimé');
    }

    public function testNothingIsDeletedBeforeThirtyMinutesOfInactivity(): void
    {
        $key = $this->temporaryUpload();
        $file = $this->session('recent', ['public_request.EVT-2026-000001' => $this->draft($key)], 1500);

        $result = $this->purge();

        self::assertSame(0, $result['drafts']);
        self::assertFileExists($file);
        self::assertTrue($this->storage()->exists($key));
    }

    public function testAnAuthenticatedSessionIsNeverTouchedEvenWithAnOldDraft(): void
    {
        $key = $this->temporaryUpload();
        $file = $this->session('agent', ['_security_main' => 'jeton-agent', 'public_request.agent.u.m.e' => $this->draft($key)], 99999);

        $result = $this->purge();

        self::assertSame(['scanned' => 1, 'drafts' => 0, 'files' => 0, 'skipped_authenticated' => 1], $result);
        self::assertFileExists($file, 'la session de l’agent est conservée');
        self::assertTrue($this->storage()->exists($key), 'et ses pièces aussi');
    }

    public function testAnOldAnonymousSessionWithoutDraftIsLeftToThePhpGarbageCollector(): void
    {
        $file = $this->session('csrf-only', ['_csrf/authenticate' => 'jeton'], 99999);

        $result = $this->purge();

        self::assertSame(0, $result['drafts']);
        self::assertFileExists($file);
    }

    public function testDryRunCountsWithoutDeletingAnything(): void
    {
        $key = $this->temporaryUpload();
        $file = $this->session('abandoned', ['public_request.EVT-2026-000001' => $this->draft($key)], 5000);

        $result = $this->purge(true);

        self::assertSame(1, $result['drafts']);
        self::assertFileExists($file);
        self::assertTrue($this->storage()->exists($key));
    }

    public function testMalformedAndForeignFilesAreIgnoredWithoutError(): void
    {
        file_put_contents($this->dir.'/sess_garbage', "pas|une session\x00\xff");
        touch($this->dir.'/sess_garbage', time() - 99999);
        file_put_contents($this->dir.'/autre.txt', 'x');
        touch($this->dir.'/autre.txt', time() - 99999);
        $target = $this->dir.'/cible';
        file_put_contents($target, '_sf2_attributes|'.serialize(['public_request.EVT-2026-000001' => ['last' => 'X']]));
        touch($target, time() - 99999);
        symlink($target, $this->dir.'/sess_lien');

        $result = $this->purge();

        self::assertSame(0, $result['drafts'], 'ni fichier invalide, ni étranger, ni lien symbolique');
        self::assertFileExists($target);
    }

    public function testADraftWithDocumentsOfAnotherSessionOnlyDeletesItsOwnKeys(): void
    {
        $mine = $this->temporaryUpload();
        $other = $this->temporaryUpload();
        $this->session('abandoned', ['public_request.EVT-2026-000001' => $this->draft($mine)], 5000);

        $this->purge();

        self::assertFalse($this->storage()->exists($mine));
        self::assertTrue($this->storage()->exists($other), 'un justificatif non référencé par le brouillon est laissé à la purge de 24 h');
    }

    public function testTheCommandReportsOnlyCountersAndRefusesAMissingDirectory(): void
    {
        $this->session('abandoned', ['public_request.EVT-2026-000001' => $this->draft($this->temporaryUpload())], 5000);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:request-drafts:purge'));

        self::assertSame(0, $tester->execute(['--path' => $this->dir, '--dry-run' => true]));
        self::assertStringContainsString('SIMULATION', $tester->getDisplay());
        self::assertStringContainsString('brouillons=1', $tester->getDisplay());
        self::assertStringNotContainsString('Martin', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--path' => $this->dir]));
        self::assertStringContainsString('brouillons=1', $tester->getDisplay());
        self::assertSame([], glob($this->dir.'/sess_*') ?: [], 'session supprimée');

        self::assertSame(1, $tester->execute(['--path' => $this->dir.'/absent']));
    }

    public function testTheDecoderReadsTheRealFormatWithoutInstantiatingClasses(): void
    {
        $raw = '_sf2_attributes|'.serialize(['a' => 1, 'b' => ['c' => 'd']]).'_sf2_meta|'.serialize(['u' => 5]).'objet|'.serialize(new \ArrayObject(['x' => 1]));

        $data = AbandonedDraftPurger::decode($raw);

        self::assertSame(['a' => 1, 'b' => ['c' => 'd']], $data['_sf2_attributes']);
        self::assertSame(['u' => 5], $data['_sf2_meta']);
        self::assertNotInstanceOf(\ArrayObject::class, $data['objet'] ?? null, 'aucune classe n’est désérialisée');
    }
}
