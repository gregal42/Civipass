<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventTypeCatalog;
use PHPUnit\Framework\TestCase;

final class EventTypeCatalogTest extends TestCase
{
    private const VALIDATED = [
        'fire' => 'Incendie',
        'flood' => 'Inondation',
        'storm' => 'Tempête / événement climatique',
        'accident' => 'Accident / incident majeur',
        'security' => 'Sécurité publique',
        'sport' => 'Manifestation sportive',
        'cultural' => 'Manifestation culturelle',
        'works' => 'Travaux / intervention technique',
        'other' => 'Autre événement',
    ];

    public function testCatalogIsTheValidatedList(): void
    {
        self::assertSame(self::VALIDATED, array_column(EventTypeCatalog::DEFAULTS, 'label', 'code'));
        self::assertSame(array_keys(self::VALIDATED), array_column(EventTypeCatalog::DEFAULTS, 'code'), 'ordre d\'affichage');
    }

    public function testCodesAreLowercaseTechnicalCodes(): void
    {
        foreach (EventTypeCatalog::DEFAULTS as $default) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]{1,40}$/', $default['code']);
        }
    }

    public function testMigrationSeedsExactlyTheSameList(): void
    {
        $migration = (string) file_get_contents(\dirname(__DIR__, 3).'/src/Migrations/Version20261103070731.php');
        preg_match_all("/\\['([a-z_]+)', '([^']+)'\\],/", $migration, $matches, \PREG_SET_ORDER);

        self::assertSame(self::VALIDATED, array_column(array_map(static fn (array $m): array => ['code' => $m[1], 'label' => $m[2]], $matches), 'label', 'code'));
    }
}
