<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Rights\Application\RightsSettings;
use App\Retention\Application\RetentionSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Interrupteurs de l'effacement et de la purge (LOT 05.4–05.6) : DÉSACTIVÉS par défaut dans la configuration versionnée, et jamais activés par un fichier d'environnement du dépôt.
 * Une activation exige une variable d'environnement posée EXPLICITEMENT sur le serveur concerné.
 */
final class ErasureSwitchesDefaultTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    public function testBothSwitchesDefaultToZeroInTheVersionedConfiguration(): void
    {
        $services = Yaml::parseFile(self::ROOT.'/config/services.yaml');

        self::assertSame('0', $services['parameters']['env(RIGHTS_ERASURE_EXECUTION_ENABLED)']);
        self::assertSame('0', $services['parameters']['env(RETENTION_PURGE_ENABLED)']);
        foreach (['when@prod', 'when@dev', 'when@test'] as $environment) {
            foreach (['env(RIGHTS_ERASURE_EXECUTION_ENABLED)', 'env(RETENTION_PURGE_ENABLED)'] as $name) {
                self::assertArrayNotHasKey($name, $services[$environment]['parameters'] ?? [], $name.' ne doit être surchargé dans aucun environnement');
            }
        }
    }

    public function testNoEnvironmentFileOfTheRepositoryEnablesThem(): void
    {
        foreach (glob(self::ROOT.'/.env*') ?: [] as $file) {
            if (str_ends_with($file, '.local')) {
                continue; // fichiers locaux non versionnés
            }
            self::assertDoesNotMatchRegularExpression('/^\s*(RIGHTS_ERASURE_EXECUTION_ENABLED|RETENTION_PURGE_ENABLED)\s*=\s*(1|true|on|yes)/mi', (string) file_get_contents($file), basename($file));
        }
        foreach (['docker-compose.yml', 'compose.yaml', 'compose.yml', 'docker-compose.yaml', 'Makefile'] as $name) {
            if (is_file(self::ROOT.'/'.$name)) {
                self::assertDoesNotMatchRegularExpression('/(RIGHTS_ERASURE_EXECUTION_ENABLED|RETENTION_PURGE_ENABLED)\s*[=:]\s*["\']?(1|true)/i', (string) file_get_contents(self::ROOT.'/'.$name), $name);
            }
        }
    }

    public function testTheSettingsAreOffUnlessExplicitlyConfigured(): void
    {
        self::assertFalse((new RightsSettings(30, 60, 30, 3, false))->erasureExecutionEnabled());
        self::assertFalse((new RetentionSettings(30, false, '/tmp/x'))->purgeEnabled());
        // Plafonds de l'export : jamais plus de 3 téléchargements ni 30 jours, quelle que soit la configuration.
        $loose = new RightsSettings(30, 60, 365, 99, false);
        self::assertSame(30, $loose->exportWindowDays());
        self::assertSame(3, $loose->exportMaxDownloads());
    }

    public function testTheErasureServiceRequiresBothSwitches(): void
    {
        $source = (string) file_get_contents(self::ROOT.'/src/Rights/Application/RightsErasureService.php');

        self::assertMatchesRegularExpression('/!\$this->settings->erasureExecutionEnabled\(\)\s*\|\|\s*!\$this->retention->purgeEnabled\(\)/', $source);
        self::assertStringContainsString('early: true', $source, 'l\'effacement passe par le service de purge, jamais par une suppression directe');
        self::assertStringNotContainsString('DELETE FROM', $source, 'aucune suppression directe dans le service d\'effacement');
    }
}
