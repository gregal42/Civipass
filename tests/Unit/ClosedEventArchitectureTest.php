<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Chemins de cycle de vie séparés et explicitement autorisés (LOT 05.6.2) : la clôture, la purge des secrets et de l'outbox, les commandes console et les
 * futurs modules d'archive (05.6.3) et de purge (05.6.4) ne passent JAMAIS par la garde opérationnelle, sinon ils seraient bloqués par la clôture qu'ils
 * servent. Inversement, la garde ne s'applique qu'aux accès opérationnels listés (liste fermée : un nouvel appelant se déclare ici).
 */
final class ClosedEventArchitectureTest extends TestCase
{
    /** Fichiers autorisés à dépendre de la garde ou de la sonde (accès opérationnels, notifications, barrière). */
    private const OPERATIONAL_USERS = [
        'src/Event/Application/EventOperationalGuard.php',
        'src/Event/Application/EventStatusProbe.php',
        'src/Event/Infrastructure/DbalClosedEventResolver.php',
        'src/Request/Application/MunicipalRequestReader.php',
        'src/Request/Application/DirectIssuanceReader.php',
        'src/Request/Application/RequestIdentityCorrectionService.php',
        'src/Request/Application/RequestSubmissionService.php',
        'src/Permit/Application/PermitRevisionGuard.php',
        'src/Permit/Application/PermitAccessService.php',
    ];

    /** @return list<string> */
    private static function files(string $directory): array
    {
        $root = \dirname(__DIR__, 2);
        if (!is_dir($root.'/'.$directory)) {
            return [];
        }
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $found[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }
        sort($found);

        return $found;
    }

    public function testOnlyTheListedOperationalPathsDependOnTheClosureGuard(): void
    {
        $users = [];
        foreach (self::files('src') as $file) {
            $content = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file);
            if (str_contains($content, 'EventOperationalGuard') || str_contains($content, 'EventStatusProbe')) {
                $users[] = $file;
            }
        }
        sort($users);
        $expected = self::OPERATIONAL_USERS;
        sort($expected);

        self::assertSame($expected, $users, 'Un nouvel appelant de la garde doit être un accès opérationnel déclaré ici ; un chemin de cycle de vie ne l\'appelle jamais.');
    }

    public function testLifecyclePathsNeverGoThroughTheGuard(): void
    {
        $lifecycle = [
            'src/Event/Application/Service/EventLifecycleService.php',
            'src/Notification/Application/EventClosureNotifications.php',
            'src/Onboarding/Application/EventClosureInvitations.php',
            'src/Notification/Application/SecretPurger.php',
            'src/Notification/Application/OutboxPurger.php',
            ...self::files('src/Archive'),
            ...self::files('src/Retention'),
            ...self::files('src/Event/UI/Console'),
            ...self::files('src/Notification/UI/Console'),
            ...self::files('src/Permit/UI/Console'),
        ];
        foreach ($lifecycle as $file) {
            if (!is_file(\dirname(__DIR__, 2).'/'.$file)) {
                continue; // chemins réservés ou fichiers pas encore livrés
            }
            $content = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file);
            self::assertStringNotContainsString('EventOperationalGuard', $content, $file);
            self::assertStringNotContainsString('ClosedEventAccess', $content, $file);
        }
    }

    public function testTheBarrierNeverGuardsTheReservedArchiveAndPurgePrefixes(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Event/UI/Http/ClosedEventRouteClassifier.php');

        self::assertStringContainsString("'archives' === \$family || 'retention' === \$family", $source);
        self::assertStringContainsString('RouteClosure::Reserved', $source);
    }

    public function testNoSonataAdminExposesDossiersPermitsPlatesOrAttachments(): void
    {
        foreach (self::files('src/Admin') as $file) {
            $content = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file);
            self::assertDoesNotMatchRegularExpression('/App\\\\(Request|Permit|Notification)\\\\Domain/', $content, $file);
        }
    }
}
