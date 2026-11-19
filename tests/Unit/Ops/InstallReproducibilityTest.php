<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ops;

use PHPUnit\Framework\TestCase;

/**
 * Installation reproductible sur un serveur vierge (correctif A, C-01 à C-04) : aucune ressource nécessaire à l'exécution n'est ignorée par Git ni téléchargée
 * à l'installation ; les réglages de téléversement et les chemins du kit sont cohérents entre eux.
 */
final class InstallReproducibilityTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(self::root().'/'.$relative);
    }

    public function testVendoredBrowserAssetsAreNotIgnoredByGitAndArePresent(): void
    {
        $ignored = array_filter(array_map('trim', explode("\n", self::read('.gitignore'))), static fn (string $l): bool => '' !== $l && !str_starts_with($l, '#'));
        foreach ($ignored as $rule) {
            self::assertDoesNotMatchRegularExpression('#^/?assets(/vendor)?/?(\*+)?$|assets/vendor#', $rule, 'C-01 : « '.$rule.' » ignorerait assets/vendor/ (jsQR absent d\'un clone propre)');
        }
        foreach (['assets/vendor/jsqr/jsQR.js', 'assets/vendor/jsqr/LICENSE', 'assets/vendor/@hotwired/stimulus/stimulus.index.js', 'assets/vendor/@hotwired/turbo/turbo.index.js', 'assets/vendor/installed.php'] as $file) {
            self::assertFileExists(self::root().'/'.$file, $file);
        }
    }

    public function testComposerInstallDownloadsNothing(): void
    {
        /** @var array{scripts: array<string, mixed>} $composer */
        $composer = json_decode(self::read('composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $auto = array_keys((array) $composer['scripts']['auto-scripts']);

        self::assertNotContains('importmap:install', $auto, 'C-02 : importmap:install télécharge depuis un CDN à chaque composer install');
        foreach ($auto as $script) {
            self::assertMatchesRegularExpression('#^(cache:clear|assets:install .*)$#', $script, 'script automatique non prévu : '.$script);
        }
    }

    public function testTheImportmapVersionsMatchTheVendoredFiles(): void
    {
        $importmap = require self::root().'/importmap.php';
        $installed = require self::root().'/assets/vendor/installed.php';

        foreach (['@hotwired/stimulus', '@hotwired/turbo'] as $package) {
            self::assertSame($importmap[$package]['version'], $installed[$package]['version'], $package);
        }
    }

    public function testThePoolSetsTheUploadLimitsOfTheApplicationAndMatchesTheDevelopmentImage(): void
    {
        $pool = self::read('ops/nginx/php-fpm/civipass-pool.conf.example');
        $dev = self::read('docker/php-fpm/php.ini');
        foreach (['upload_max_filesize' => '10M', 'post_max_size' => '64M'] as $key => $value) {
            self::assertMatchesRegularExpression('/^php_admin_value\['.$key.'\]\s*=\s*'.$value.'\s*$/m', $pool, 'C-03 : '.$key);
            self::assertMatchesRegularExpression('/^'.$key.'\s*=\s*'.$value.'\s*$/m', $dev, 'image de développement : '.$key);
        }
        self::assertStringContainsString('client_max_body_size 64m;', self::read('ops/nginx/templates/nginx.conf.template'), 'nginx doit accepter au moins post_max_size');
    }

    public function testEveryPathOfTheKitUsesTheSameInstallationDirectory(): void
    {
        self::assertMatchesRegularExpression('#^DOCROOT=/opt/civipass/public$#m', self::read('ops/nginx/civipass.env.example'), 'C-04');
        $units = [...glob(self::root().'/ops/systemd/*.service') ?: [], ...glob(self::root().'/ops/backup/systemd/*.service') ?: []];
        self::assertNotEmpty($units);
        foreach ($units as $unit) {
            $text = (string) file_get_contents($unit);
            self::assertStringContainsString('WorkingDirectory=/opt/civipass', $text, basename($unit));
            self::assertStringNotContainsString('/var/www/civipass', $text, basename($unit));
        }
    }
}
