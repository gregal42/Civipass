<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain\Time;

use App\Event\Domain\Time\CommuneTimezoneOverrides;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\TerritoryTimezoneResolver;
use App\Municipality\Domain\InseeCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerritoryTimezoneResolverTest extends TestCase
{
    #[DataProvider('resolvable')]
    public function testResolvesTerritoriesWithASingleTimezone(string $insee, string $expected): void
    {
        $zone = (new TerritoryTimezoneResolver())->resolve(InseeCode::fromString($insee));

        self::assertNotNull($zone);
        self::assertSame($expected, $zone->identifier());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resolvable(): iterable
    {
        yield 'Paris' => ['75056', 'Europe/Paris'];
        yield 'Marseille' => ['13055', 'Europe/Paris'];
        yield 'Corse-du-Sud' => ['2A004', 'Europe/Paris'];
        yield 'Haute-Corse' => ['2B033', 'Europe/Paris'];
        yield 'Ain' => ['01001', 'Europe/Paris'];
        yield 'Val-d\'Oise' => ['95500', 'Europe/Paris'];
        yield 'Guadeloupe' => ['97101', 'America/Guadeloupe'];
        yield 'Martinique' => ['97209', 'America/Martinique'];
        yield 'Guyane' => ['97302', 'America/Cayenne'];
        yield 'La Réunion' => ['97411', 'Indian/Reunion'];
        yield 'Saint-Pierre-et-Miquelon' => ['97501', 'America/Miquelon'];
        yield 'Mayotte' => ['97601', 'Indian/Mayotte'];
        yield 'Saint-Barthélemy' => ['97701', 'America/St_Barthelemy'];
        yield 'Saint-Martin' => ['97801', 'America/Marigot'];
        yield 'Wallis-et-Futuna' => ['98601', 'Pacific/Wallis'];
        yield 'Nouvelle-Calédonie' => ['98818', 'Pacific/Noumea'];
    }

    #[DataProvider('unresolvable')]
    public function testDoesNotGuessWhenTheTimezoneIsNotKnown(string $insee): void
    {
        self::assertNull((new TerritoryTimezoneResolver())->resolve(InseeCode::fromString($insee)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unresolvable(): iterable
    {
        // Polynésie française : trois fuseaux, aucune correspondance commune → fuseau n'est supposée.
        yield 'Polynésie française' => ['98714'];
        yield 'Polynésie française (autre commune)' => ['98731'];
        yield 'TAAF' => ['98401'];
        yield 'Clipperton' => ['98901'];
    }

    public function testCommuneOverridesTakePrecedence(): void
    {
        // Code fictif et fuseau de test : le domaine ne fournit aucune donnée de Polynésie.
        $overrides = new class () implements CommuneTimezoneOverrides {
            public function find(InseeCode $code): ?EventTimezone
            {
                return '98799' === $code->value ? EventTimezone::fromIdentifier('Pacific/Gambier') : null;
            }
        };
        $resolver = new TerritoryTimezoneResolver($overrides);

        self::assertSame('Pacific/Gambier', $resolver->resolve(InseeCode::fromString('98799'))?->identifier());
        self::assertNull($resolver->resolve(InseeCode::fromString('98714')));
        self::assertSame('Europe/Paris', $resolver->resolve(InseeCode::fromString('75056'))?->identifier());
    }
}
