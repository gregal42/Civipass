<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LOT 10 (O-1) : un tableau de l'espace communal doit défiler DANS son conteneur (`cp-table-wrap`), jamais élargir la page sur un téléphone.
 * Hors périmètre : back-office CWS (poste de bureau), PDF et e-mails (mise en page propre).
 */
final class ResponsiveTablesTest extends TestCase
{
    private const EXEMPT = ['admin/', 'permit/pass_pdf.html.twig', 'email/', 'components/data_table.html.twig'];

    public function testEveryTableOfTheMunicipalAreaIsInsideTheScrollingContainer(): void
    {
        $root = \dirname(__DIR__, 2).'/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = 0;
        foreach ($iterator as $file) {
            $relative = substr((string) $file, \strlen($root) + 1);
            if (!str_ends_with($relative, '.twig') || array_filter(self::EXEMPT, static fn (string $e): bool => str_starts_with($relative, $e))) {
                continue;
            }
            $source = (string) file_get_contents((string) $file);
            $tables = substr_count($source, '<table');
            if (0 === $tables) {
                continue;
            }
            ++$checked;
            self::assertGreaterThanOrEqual($tables, substr_count($source, 'cp-table-wrap'), $relative.' : chaque <table> doit être dans un conteneur cp-table-wrap');
        }
        self::assertGreaterThanOrEqual(6, $checked, 'le test regarde bien les gabarits à tableaux');
    }
}
