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

    /**
     * LOT 05.4.2 : seules les routes du bénéficiaire (`/laissez-passer/…`) et la vérification publique (`/v/{token}`, en deux chemins qui ne
     * redirigent pas, GET seulement) sont publiques ; aucune autre route de contrôle n'existe.
     */
    public function testOnlyTheBeneficiaryRoutesAndTheOnePublicVerificationRouteArePublic(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all();
        $paths = array_map(static fn ($route): string => $route->getPath(), array_values($routes));

        foreach ($paths as $path) {
            self::assertDoesNotMatchRegularExpression('#^/(passes?|controle|verification)(/|$)#', $path, 'aucune autre route de contrôle');
        }
        $verification = array_values(array_filter($paths, static fn (string $p): bool => '/v' === $p || str_starts_with($p, '/v/')));
        sort($verification);
        self::assertSame(['/v/{token}', '/v/{token}/'], $verification);
        foreach ($routes as $name => $route) {
            if (str_starts_with($name, 'app_public_verification')) {
                self::assertSame(['GET'], $route->getMethods(), 'lecture seule');
            }
        }
        $beneficiary = array_values(array_filter($paths, static fn (string $p): bool => str_starts_with($p, '/laissez-passer')));
        sort($beneficiary);
        self::assertSame(['/laissez-passer/{secret}', '/laissez-passer/{secret}/pdf'], $beneficiary);
    }

    /** LOT 05.4.2 : la couche HTTP de la vérification publique ne touche ni Doctrine, ni les entités, ni la demande : elle ne voit que le lecteur et ses DTO. */
    public function testThePublicVerificationUiDependsOnlyOnTheReaderAndItsReadModel(): void
    {
        $root = \dirname(__DIR__, 3).'/src/Permit/UI';
        foreach (['/Http/PublicVerificationController.php', '/Http/PublicVerificationHeadersListener.php', '/Http/VerificationClientKey.php', '/Http/VerificationThrottle.php', '/Http/RateLimiterVerificationThrottle.php', '/Http/ThrottleStorageUnavailable.php', '/Twig/PublicVerificationExtension.php'] as $file) {
            $code = (string) file_get_contents($root.$file);
            foreach (['Doctrine\\', 'App\\Request\\', 'App\\Event\\', 'App\\Municipality\\', 'App\\Identity\\', 'App\\Notification\\', 'App\\Permit\\Domain\\Permit;', 'App\\Permit\\Domain\\PermitAuthorization', 'App\\Permit\\Domain\\VerificationToken', 'App\\Permit\\Domain\\PermitRepository'] as $forbidden) {
                self::assertStringNotContainsString('use '.$forbidden, $code, basename($file).' ne doit pas dépendre de '.$forbidden);
            }
        }
    }

    /** Le lecteur public n'est utilisé que par la couche HTTP de la vérification : ni Notification, ni Request. */
    public function testNobodyElseUsesThePublicVerificationReader(): void
    {
        $users = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'PublicVerificationReader') && 'PublicVerificationReader.php' !== $file->getFilename()) {
                $users[] = $file->getFilename();
            }
        }
        self::assertSame(['PublicVerificationController.php'], $users);
    }

    public function testTheControlPageAndTheOfflineMechanismsAreNotDevelopedYet(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach (['src/Control', 'public/service-worker.js', 'public/manifest.webmanifest'] as $path) {
            self::assertFileDoesNotExist($root.'/'.$path, 'LOT 05.4 / LOT 06 : '.$path);
        }
        $lock = (string) file_get_contents($root.'/composer.lock');
        self::assertStringContainsString('endroid/qr-code', $lock, 'LOT 05.2 : bibliothèque QR autorisée');
        self::assertStringContainsString('dompdf/dompdf', $lock, 'LOT 05.2 : bibliothèque PDF autorisée');
    }

    /** LOT 05.4.1 : le modèle de lecture public ne dépend ni de la demande (adresse, motifs, justificatifs…), ni de Doctrine, ni d'un identifiant. */
    public function testThePublicVerificationModelDependsOnNoRequestEntityNorDoctrineNorUuid(): void
    {
        $files = array_merge(glob(\dirname(__DIR__, 3).'/src/Permit/Application/Read/Public*.php') ?: [], glob(\dirname(__DIR__, 3).'/src/Permit/Application/Result/PublicVerification*.php') ?: []);
        self::assertCount(6, $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach (['App\\Request\\', 'Doctrine\\', 'Symfony\\Component\\Uid\\', 'App\\Event\\', 'App\\Municipality\\'] as $forbidden) {
                self::assertStringNotContainsString('use '.$forbidden, $code, basename($file).' ne doit pas dépendre de '.$forbidden);
            }
        }
    }
}
