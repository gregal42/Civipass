<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;

/**
 * Frontières du module Notification (LOT 05.3.1, D11) : Permit ne dépend pas de Notification (port côté Permit), Notification ne dépend que du
 * port de Permit et de `SystemAccessLinks` (05.3.2), aucun envoi SMTP, aucune commande de distribution, aucun scheduler ni transport Messenger ajouté.
 */
final class NotificationModuleBoundaryTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** @return list<string> */
    private function sources(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function testPermitAndRequestNeverDependOnNotification(): void
    {
        foreach (['src/Permit', 'src/Request'] as $module) {
            foreach ($this->sources($module) as $source) {
                self::assertStringNotContainsString('App\\Notification\\', $source, $module.' ne doit pas dépendre de Notification');
            }
        }
    }

    public function testNotificationOnlyDependsOnThePermitPortAndSharedKernels(): void
    {
        $allowedPermit = ['App\\Permit\\Application\\NotificationIntentRecorder', 'App\\Permit\\Domain\\NotificationIntent', 'App\\Permit\\Application\\SystemAccessLinks', 'App\\Permit\\Application\\NotificationFactsReader', 'App\\Permit\\Application\\Read\\NotificationFacts', 'App\\Permit\\Application\\ManualNotificationRequester', 'App\\Permit\\Application\\NotificationStatusProvider', 'App\\Permit\\Application\\Read\\NotificationOverview', 'App\\Permit\\Application\\Read\\NotificationLine', 'App\\Permit\\Domain\\ManualNotificationOutcome', 'App\\Event\\Application\\EventStatusProbe', 'App\\Event\\Application\\ClosureNotificationNeutralizer'];
        foreach ($this->sources('src/Notification') as $source) {
            preg_match_all('/^use (App\\\\[A-Za-z\\\\]+);/m', $source, $uses);
            foreach ($uses[1] as $use) {
                if (str_starts_with($use, 'App\\Notification\\') || str_starts_with($use, 'App\\Shared\\')) {
                    continue;
                }
                self::assertContains($use, $allowedPermit, 'dépendance interdite : '.$use);
            }
        }
    }

    public function testSmtpIsOnlyUsedByTheApplicationLayerOfNotificationAndMessengerNeverIs(): void
    {
        foreach (['src/Notification/Domain', 'src/Permit', 'src/Request'] as $dir) {
            foreach ($this->sources($dir) as $source) {
                self::assertStringNotContainsString('Symfony\\Component\\Mailer', $source, 'aucun envoi hors de la couche Application de Notification');
            }
        }
        foreach ($this->sources('src/Notification') as $source) {
            self::assertStringNotContainsString('Messenger', $source, 'ni Messenger ni worker en V1 (D4)');
        }
        $commands = array_map('basename', glob(self::ROOT.'/src/Notification/UI/Console/*.php') ?: []);
        sort($commands);
        self::assertSame(['CheckCryptoCommand.php', 'DispatchCommand.php', 'PurgeOutboxCommand.php', 'PurgeSecretsCommand.php', 'StatusCommand.php'], $commands, 'ni regroupement complet (05.3.4), ni déclenchement manuel');
        self::assertStringNotContainsString('scheduler', (string) file_get_contents(self::ROOT.'/composer.json'));
        self::assertFileDoesNotExist(self::ROOT.'/config/packages/scheduler.yaml');
        self::assertFileDoesNotExist(self::ROOT.'/src/Notification/UI/Http', 'aucune interface agent de suivi avant validation');
    }
}
