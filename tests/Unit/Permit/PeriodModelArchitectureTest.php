<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fous d'architecture du modèle B2 (LOT 05.5.2) : la période courante est dans les colonnes de l'autorisation, les périodes closes dans leur table ;
 * SEULE `PermitAuthorization::windows()` réunit les deux pour calculer des droits. Aucune autre lecture des périodes closes ne doit pouvoir donner des
 * droits sur la seule période courante, ni l'inverse.
 */
final class PeriodModelArchitectureTest extends TestCase
{
    private static function source(string $relative): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$relative);
    }

    public function testRightsAreComputedOnTheWindowsAndNeverOnTheCurrentPeriodAlone(): void
    {
        $evaluator = self::source('src/Permit/Application/PermitRightsEvaluator.php');

        self::assertSame(1, substr_count($evaluator, '$authorization->windows()'), 'les états hors période courante lisent TOUTES les périodes ; la période courante seule sert au cas « valide maintenant » et à la liste publique');
        self::assertStringContainsString('$this->windows->effectivePeriods($windows,', $evaluator, 'les états hors période courante sont calculés sur TOUTES les périodes');
    }

    public function testOnlyTheAuthorizationEntityExposesClosedPeriods(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS));
        $readers = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match('/->closedPeriods\(\)|PermitAuthorizationPeriod::class/', (string) file_get_contents($file->getPathname()))) {
                $readers[] = str_replace(\dirname(__DIR__, 3).'/', '', $file->getPathname());
            }
        }
        sort($readers);

        self::assertSame(['src/Permit/Domain/PermitAuthorization.php'], $readers, 'les périodes closes ne sont lues que par leur propriétaire');
    }

    public function testAClosedPeriodHasNoWayToChange(): void
    {
        $forbidden = '/^(set|change|update|edit|modify|remove|delete|reopen|extend|shorten|reset)/i';
        foreach ((new \ReflectionClass(\App\Permit\Domain\PermitAuthorizationPeriod::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertDoesNotMatchRegularExpression($forbidden, $method->getName(), 'mutateur interdit : '.$method->getName());
        }
    }

    public function testTheCurrentPeriodIsChangedOnlyByTheTwoDomainMethods(): void
    {
        $mutators = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter((new \ReflectionClass(\App\Permit\Domain\PermitAuthorization::class))->getMethods(\ReflectionMethod::IS_PUBLIC), static fn (\ReflectionMethod $m): bool => 1 === preg_match('/^(changeCurrentEnd|resumeAt|revoke)$/', $m->getName())),
        );
        sort($mutators);

        self::assertSame(['changeCurrentEnd', 'resumeAt', 'revoke'], $mutators);
    }

    public function testTheMigrationOnlyCreatesTheClosedPeriodsTableAndRefusesToLoseHistory(): void
    {
        $migration = self::source('src/Migrations/Version20261110155643.php');
        $up = substr($migration, (int) strpos($migration, 'function up'), (int) strpos($migration, 'function down') - (int) strpos($migration, 'function up'));

        self::assertSame(1, substr_count($up, 'CREATE TABLE'));
        self::assertSame(2, substr_count($up, 'FOREIGN KEY'));
        self::assertSame(3, substr_count($up, 'addSql'), 'une table et deux clés étrangères');
        self::assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|UPDATE|INSERT)\b|(?<!ON )\bDELETE\b/i', $up);
        self::assertStringContainsString('REFERENCES permit_authorization (id) ON DELETE RESTRICT', $up);
        self::assertStringContainsString('REFERENCES permit_revision (id) ON DELETE RESTRICT', $up);
        self::assertStringContainsString('UNIQUE INDEX uniq_permit_authorization_period_position (authorization_id, position)', $up);
        self::assertStringContainsString('abortIf', substr($migration, (int) strpos($migration, 'function down')), 'retour arrière refusé dès qu\'une période close existe');
    }
}
