<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les champs audités ne se modifient que par leur service applicatif (audit dans la même transaction).
 */
final class ArchitectureTest extends TestCase
{
    /**
     * @param list<string> $allowedFiles
     */
    #[DataProvider('restrictedCalls')]
    public function testAuditedMutatorsAreOnlyCalledByTheirManager(string $call, array $allowedFiles): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' !== $file->getExtension() || [] !== array_filter($allowedFiles, static fn (string $f): bool => str_ends_with($file->getPathname(), $f))) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            // Les définitions (Domain) ne comptent pas : on cherche les appels « ->méthode( ».
            if (str_contains($content, '->'.$call.'(')) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, sprintf('%s() ne doit être appelée que depuis %s.', $call, implode(', ', $allowedFiles)));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function restrictedCalls(): iterable
    {
        yield 'User::changeSuperAdmin' => ['changeSuperAdmin', ['Identity/Application/UserManager.php']];
        yield 'Municipality::changeStatus' => ['changeStatus', ['Municipality/Application/MunicipalityManager.php']];
        yield 'Membership::changeRoles' => ['changeRoles', ['Municipality/Application/MembershipManager.php']];
        // Activation/désactivation de comptes et de memberships : audit + protections des derniers administrateurs.
        yield 'deactivate()' => ['deactivate', ['Identity/Application/UserManager.php', 'Municipality/Application/MembershipManager.php']];
        // Event::activate() (cycle de vie des événements, 5.3) porte le même nom : seul EventLifecycleService l'appelle. Le contrôleur du
        // cycle de vie (UI-02, incrément 6) appelle EventLifecycleService::activate(), l'API du service, jamais Event::activate().
        yield 'activate()' => ['activate', ['Identity/Application/UserManager.php', 'Municipality/Application/MembershipManager.php', 'Event/Application/Service/EventLifecycleService.php', 'Event/UI/Http/EventLifecycleController.php']];
    }

    public function testNoPublicSetterOnAuditLog(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(\App\Shared\Domain\AuditLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression('/^(set|change|update|delete|remove)/', $method, 'AuditLog est immuable.');
        }
    }
}
