<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Tests\Support\EventSchemaTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Frontières du module Permit au LOT 05.1 : Request ne dépend pas de Permit (le port est implémenté côté Permit), aucune route publique ni
 * aucun écran n'est créé (le scan public, la page bénéficiaire et le PDF relèvent des LOT 05.2 et 05.4), aucune dépendance Endroid ni dompdf.
 */
final class PermitModuleBoundaryTest extends EventSchemaTestCase
{
    public function testTheRequestModuleNeverDependsOnThePermitModule(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src/Request', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'App\\Permit\\')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'Request ne doit pas dépendre de Permit (aucune dépendance circulaire)');
    }

    /** LOT 05.2 : seules les routes du bénéficiaire (`/laissez-passer/…`) sont publiques ; le contrôle public (`/v/…`, LOT 05.4) n'existe pas encore. */
    public function testOnlyTheBeneficiaryRoutesArePublicAndTheControlPageDoesNotExistYet(): void
    {
        $paths = array_map(static fn ($route): string => $route->getPath(), array_values(self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all()));

        foreach ($paths as $path) {
            self::assertDoesNotMatchRegularExpression('#^/(v|passes?|controle|verification)(/|$)#', $path, 'la page publique de contrôle relève du LOT 05.4');
        }
        $beneficiary = array_values(array_filter($paths, static fn (string $p): bool => str_starts_with($p, '/laissez-passer')));
        sort($beneficiary);
        self::assertSame(['/laissez-passer/{secret}', '/laissez-passer/{secret}/pdf'], $beneficiary);
    }

    public function testTheControlPageAndTheOfflineMechanismsAreNotDevelopedYet(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach (['src/Control', 'src/Permit/UI/Http/VerificationController.php', 'public/service-worker.js', 'public/manifest.webmanifest'] as $path) {
            self::assertFileDoesNotExist($root.'/'.$path, 'LOT 05.4 / LOT 06 : '.$path);
        }
        $lock = (string) file_get_contents($root.'/composer.lock');
        self::assertStringContainsString('endroid/qr-code', $lock, 'LOT 05.2 : bibliothèque QR autorisée');
        self::assertStringContainsString('dompdf/dompdf', $lock, 'LOT 05.2 : bibliothèque PDF autorisée');
    }
}
