<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Frontières de modules et règles structurelles du LOT TECH-02.1 (étape 2).
 */
final class EventModuleArchitectureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function phpFiles(string $relativeDirectory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/'.$relativeDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public function testMunicipalityNeverDependsOnEvent(): void
    {
        // Event → Municipality est la seule direction permise : pas de dépendance circulaire.
        foreach (self::phpFiles('src/Municipality') as $file) {
            self::assertStringNotContainsString('App\\Event\\', (string) file_get_contents($file), $file);
        }
    }

    public function testEventDomainIsIndependentOfApplicationAndInfrastructure(): void
    {
        foreach (self::phpFiles('src/Event/Domain') as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('App\\Event\\Infrastructure', $content, $file);
            self::assertStringNotContainsString('App\\Event\\Application', $content, $file);
            self::assertStringNotContainsString('Symfony\\Component\\HttpFoundation', $content, $file);
            self::assertStringNotContainsString('Symfony\\Component\\Security', $content, $file);
        }
    }

    public function testEventModuleOnlyReachesOtherModulesThroughTheirDomainAndTheSharedPolicies(): void
    {
        // Seules exceptions : les politiques partagées du module Municipality (accès, création, contexte municipal).
        $allowedApplication = ['App\\Municipality\\Application\\MembershipAccessPolicy', 'App\\Municipality\\Application\\EventCreationPolicy', 'App\\Municipality\\Application\\MunicipalityContext'];
        foreach (self::phpFiles('src/Event') as $file) {
            $content = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/App\\\\(Municipality|Identity)\\\\(Infrastructure|UI)/', $content, $file);
            preg_match_all('/use (App\\\\(?:Municipality|Identity)\\\\Application\\\\\w+);/', $content, $uses);
            foreach ($uses[1] as $used) {
                self::assertContains($used, $allowedApplication, $file);
                self::assertStringContainsString('/src/Event/Application/', $file, 'jamais depuis le domaine ni l\'infrastructure');
            }
        }
    }

    public function testEventAccessPolicyDoesNotDependOnSymfonySecurityOrHttp(): void
    {
        foreach (self::phpFiles('src/Event/Application') as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('Symfony\\Component\\Security', $content, $file);
            self::assertStringNotContainsString('Symfony\\Component\\HttpFoundation', $content, $file);
            self::assertStringNotContainsString('->getUser()', $content, $file);
        }
    }

    public function testRolesAreNeverTestedOutsideThePermissionMatrixAndTheMembershipPolicy(): void
    {
        // Source de vérité unique : les rôles autorisés ne se codent qu'à un endroit (EventPermission::roles()).
        foreach (self::phpFiles('src/Event') as $file) {
            if (str_ends_with($file, 'EventPermission.php')) {
                continue;
            }
            self::assertStringNotContainsString('MunicipalityRole::', (string) file_get_contents($file), $file);
        }
    }

    public function testReadProjectionsAreSelfContainedValueObjects(): void
    {
        foreach (self::phpFiles('src/Event/Application/Read') as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('Doctrine\\', $content, $file);
            self::assertStringNotContainsString('App\\Municipality\\Domain', $content, $file);
            self::assertStringNotContainsString('App\\Identity', $content, $file);
            self::assertStringNotContainsString('App\\Event\\Domain\\EventParticipation;', $content, $file.' : aucune entité exposée');
        }
    }

    public function testReadModelSelectsExplicitColumnsAndNeverLeaksInternalColumnsIntoCoordination(): void
    {
        $content = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Event/Infrastructure/DbalEventReadModel.php');
        self::assertDoesNotMatchRegularExpression('/\'SELECT\s+\*|\b\w+\.\*\s/', $content, 'jamais de SELECT * dans les requêtes');

        preg_match('/function coordinationOf.*?\n    }\n/s', $content, $coordination);
        self::assertNotEmpty($coordination);
        foreach (['suspension_reason', 'invitation_message', 'invited_by', 'invited_at', 'last_invitation_sent_at', 'created_by', 'audit', 'commercial'] as $internal) {
            self::assertStringNotContainsString($internal, $coordination[0], 'colonne interne dans la requête de coordination : '.$internal);
        }
    }

    public function testNothingGrantsMunicipalRightsToSuperAdmins(): void
    {
        foreach (self::phpFiles('src/Event') as $file) {
            self::assertStringNotContainsString('isSuperAdmin', (string) file_get_contents($file), $file);
            self::assertStringNotContainsString('ROLE_SUPER_ADMIN', (string) file_get_contents($file), $file);
        }
    }

    public function testTemporalDomainDoesNotKnowDoctrineEntitiesOfOtherLayers(): void
    {
        foreach (array_merge(self::phpFiles('src/Event/Domain/Time'), self::phpFiles('src/Event/Domain/Validity')) as $file) {
            self::assertStringNotContainsString('Doctrine\\', (string) file_get_contents($file), $file);
        }
    }

    /**
     * @param class-string $repository
     */
    #[DataProvider('repositories')]
    public function testRepositoriesNeverDeletePhysically(string $repository): void
    {
        foreach ((new \ReflectionClass($repository))->getMethods() as $method) {
            self::assertDoesNotMatchRegularExpression('/^(remove|delete|purge|erase|truncate)/i', $method->getName(), $repository);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function repositories(): iterable
    {
        foreach ([EventTypeRepository::class, EventRepository::class, EventParticipationRepository::class, EventZoneRepository::class, EventZoneValidityRepository::class] as $repository) {
            yield $repository => [$repository];
        }
    }

    public function testNoInverseCollectionsAndNoCascadeInTheEventModule(): void
    {
        foreach (self::phpFiles('src/Event/Domain') as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString('OneToMany', $content, $file);
            self::assertStringNotContainsString('ManyToMany', $content, $file);
            self::assertStringNotContainsString('cascade', $content, $file);
            self::assertStringNotContainsString("onDelete: 'CASCADE'", $content, $file);
        }
    }

    public function testNoCartographyNorZoneLibraryInTheEventModule(): void
    {
        foreach (self::phpFiles('src/Event') as $file) {
            self::assertDoesNotMatchRegularExpression('/geojson|geometry|polygon|zone_?library|ZoneLibrary|snapshot/i', (string) file_get_contents($file), $file);
        }
    }

    public function testEveryRepositoryThatServesCommunesOffersAScopedFinder(): void
    {
        foreach ([
            [EventRepository::class, 'findAccessibleTo'],
            [EventRepository::class, 'listForMunicipality'],
            [EventParticipationRepository::class, 'findForMunicipality'],
            [EventZoneRepository::class, 'findForMunicipality'],
        ] as [$repository, $method]) {
            self::assertTrue((new \ReflectionClass($repository))->hasMethod($method), $repository.'::'.$method);
        }
    }
}
