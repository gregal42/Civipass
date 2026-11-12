<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use PHPUnit\Framework\TestCase;

/**
 * Décision D2 du 11 novembre 2026 : l'ordre chronologique TOTAL de deux révisions écrites dans la même seconde par deux processus n'est pas garanti (V1, aucune migration de séquence).
 * Condition : cet ordre ne sert JAMAIS à calculer les droits ni l'état courant. Garde-fou statique : le calcul des droits ne lit pas les révisions ; la lecture ordonnée de l'historique
 * n'existe que pour l'affichage et la description d'un message ; les regroupements par lot (`batch_id`) et les liens explicites de remplacement sont conservés.
 */
final class RevisionOrderIsDisplayOnlyTest extends TestCase
{
    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function testTheComputationOfRightsNeverReadsRevisions(): void
    {
        foreach (['src/Permit/Application/PermitRightsEvaluator.php', 'src/Request/Domain/GrantWindowCalculator.php', 'src/Permit/Domain/AuthorizationRights.php', 'src/Permit/Domain/PermitRights.php', 'src/Permit/Domain/ZoneRight.php', 'src/Permit/Domain/RightState.php'] as $file) {
            self::assertDoesNotMatchRegularExpression('/PermitRevision|RevisionRepository|forPermit\(/', (string) file_get_contents(self::root().'/'.$file), $file.' : le calcul des droits ne doit jamais dépendre des révisions ni de leur ordre');
        }
    }

    public function testTheOrderedHistoryIsReadOnlyByTheDisplayAndTheMessageDescription(): void
    {
        $readers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && 1 === preg_match('/revisions->forPermit\(/', (string) file_get_contents($file->getPathname()))) {
                $readers[] = str_replace(self::root().'/', '', $file->getPathname());
            }
        }
        sort($readers);

        self::assertSame(['src/Archive/Application/ArchiveDossierReader.php', 'src/Permit/Application/NotificationFactsReader.php', 'src/Permit/Application/PermitRightsOverviewReader.php', 'src/Rights/Application/RightsDossierExporter.php'], $readers, 'seuls l\'affichage de l\'historique (écran des droits, archive en lecture seule, export d\'accès d\'une personne, LOT 05.6.6) et la description d\'un message lisent l\'historique ordonné');
    }

    public function testBatchGroupingAndExplicitReplacementLinksAreKept(): void
    {
        $vehicle = (string) file_get_contents(self::root().'/src/Permit/Application/PermitVehicleService.php');
        self::assertStringContainsString("'pair' => \$change->pair", $vehicle, 'le lien explicite entre la plaque sortante et la plaque entrante d\'un remplacement');
        self::assertStringContainsString('$batch', $vehicle, 'un lot par opération');
        $revision = (string) file_get_contents(self::root().'/src/Permit/Domain/PermitRevision.php');
        self::assertSame(4, substr_count($revision, 'Uuid $batchId'), 'zones, plaques, QR et opérations de période partagent le même mécanisme de lot (période et révocation créent leur propre lot)');
    }
}
