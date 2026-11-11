<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Domain;

use App\Event\Domain\ClosureStatistics;
use App\Event\Domain\ClosureStatisticsPublication as P;
use App\Event\Domain\MunicipalityCounters;
use PHPUnit\Framework\TestCase;

/**
 * Secret statistique des bilans de clôture (LOT 05.6.2, Q6, P5) : règles R1 à R4 et ÉNUMÉRATION EXHAUSTIVE des déductions : aucune cellule sensible (effectif
 * de 1 à seuil − 1) d'une commune ne se déduit des vues d'un lecteur, ni de leur combinaison avec celles de ses autres communes d'appartenance.
 */
final class ClosureStatisticsPublicationTest extends TestCase
{
    private const IDS = ['0192a5f0-0000-7000-8000-00000000000a', '0192a5f0-0000-7000-8000-00000000000b', '0192a5f0-0000-7000-8000-00000000000c'];

    /** @param list<array{int, int, int}> $rows approuvés, refusés, en attente par commune (autorisations = approuvés) */
    private static function stats(array $rows): ClosureStatistics
    {
        $municipalities = [];
        $total = 0;
        $max = 0;
        foreach ($rows as $i => [$a, $r, $p]) {
            $municipalities[] = new MunicipalityCounters(self::IDS[$i], $a, $r, $p, $a);
            $total += $a + $r + $p;
            $max = max($max, $a + $r + $p);
        }

        return new ClosureStatistics($max, $municipalities); // chaque demande ne touche qu'une commune (minimum légal)
    }

    public function testAZeroIsPublishedAndASmallNonZeroCountIsNot(): void
    {
        self::assertTrue(P::publishable(0, 5));
        foreach ([1, 2, 3, 4] as $small) {
            self::assertFalse(P::publishable($small, 5));
        }
        self::assertTrue(P::publishable(5, 5));
        self::assertSame(2, P::threshold(0), 'le seuil ne descend jamais sous 2');
    }

    public function testAnEventWithoutAnyRequestPublishesZeros(): void
    {
        $view = P::forOrganizer(self::stats([[0, 0, 0]]), 5, true);

        self::assertSame(0, $view->dossiers);
        self::assertSame(['approved' => 0, 'refused' => 0, 'pending' => 0, 'authorizations' => 0], $view->byStatus);
        self::assertSame([], $view->masked);
    }

    public function testASingleMunicipalityEventPublishesTheStatusBreakdownWhenEveryCellIsPublishable(): void
    {
        $view = P::forOrganizer(self::stats([[20, 6, 0]]), 5, true);

        self::assertSame(26, $view->dossiers);
        self::assertSame(['approved' => 20, 'refused' => 6, 'pending' => 0, 'authorizations' => 20], $view->byStatus);
        self::assertNull($view->communes, 'une seule commune : pas de ventilation');
    }

    public function testASingleSmallCellMasksTheWholeStatusGroupAndNeverOneCellAlone(): void
    {
        $view = P::forOrganizer(self::stats([[20, 2, 0]]), 5, true);

        self::assertSame(22, $view->dossiers, 'le total reste publié');
        self::assertNull($view->byStatus, 'le groupe entier est masqué : aucune cellule ne se déduit du total');
        self::assertContains('byStatus', $view->masked);
    }

    public function testTheGlobalStatusBreakdownIsNeverPublishedForAMultiMunicipalityEvent(): void
    {
        $view = P::forOrganizer(self::stats([[20, 6, 0], [30, 5, 0]]), 5, true);

        self::assertNull($view->byStatus, 'R4 : global − commune donnerait la répartition des autres communes');
        self::assertSame(61, $view->dossiers);
        self::assertSame([['municipalityId' => self::IDS[0], 'dossiers' => 26], ['municipalityId' => self::IDS[1], 'dossiers' => 35]], $view->communes);
    }

    public function testTheBreakdownByMunicipalityNeedsTheCoordinationRightAndEveryMunicipalityToBePublishable(): void
    {
        $rows = [[20, 6, 0], [30, 5, 0]];

        self::assertNull(P::forOrganizer(self::stats($rows), 5, false)->communes, 'sans habilitation de coordination : aucune ventilation');
        self::assertSame(61, P::forOrganizer(self::stats($rows), 5, false)->dossiers, 'mais le total reste lisible');
        $small = P::forOrganizer(self::stats([[20, 6, 0], [3, 0, 0]]), 5, true);
        self::assertNull($small->communes, 'R3 : tout ou rien');
        self::assertNull($small->dossiers, 'R3 : sans ventilation, le total d\'un événement multicommunal est masqué aussi');
        self::assertContains('communes', $small->masked);
        self::assertContains('dossiers', $small->masked);
    }

    public function testAMunicipalityOnlyEverSeesItsOwnRow(): void
    {
        $stats = self::stats([[20, 6, 0], [30, 5, 0]]);

        $b = P::forMunicipality($stats, self::IDS[1], 5);

        self::assertSame(PublishedClosureStatisticsScope::COMMUNE, $b->scope);
        self::assertSame(35, $b->dossiers);
        self::assertSame(['approved' => 30, 'refused' => 5, 'pending' => 0, 'authorizations' => 30], $b->byStatus ?? self::fail('la propre répartition est publiable'));
        self::assertNull($b->communes, 'jamais la liste des autres communes');
        $masked = P::forMunicipality(self::stats([[20, 6, 0], [30, 2, 3]]), self::IDS[1], 5);
        self::assertNull($masked->byStatus, 'cellule de 2 : groupe masqué');
        self::assertSame(35, $masked->dossiers);
    }

    public function testAMunicipalityWithoutAnyRowSeesZeros(): void
    {
        $view = P::forMunicipality(self::stats([[20, 6, 0]]), '0192a5f0-0000-7000-8000-0000000000ff', 5);

        self::assertSame(0, $view->dossiers);
        self::assertSame(['approved' => 0, 'refused' => 0, 'pending' => 0, 'authorizations' => 0], $view->byStatus);
    }

    // ------------------------------------------------------------------ énumération exhaustive des déductions

    /**
     * Domaine COMPLET de chaque commune sur deux cellules (approuvés, refusés ; en attente fixé à 0) : un domaine tronqué créerait de fausses déductions
     * (somme atteinte par une seule décomposition). Les cellules sensibles sont celles de 1 à seuil − 1.
     *
     * @param list<int> $values valeurs possibles de chaque cellule
     *
     * @return list<array{int, int, int}>
     */
    private static function cellsOf(array $values): array
    {
        $cells = [];
        foreach ($values as $a) {
            foreach ($values as $r) {
                $cells[] = [$a, $r, 0];
            }
        }

        return $cells;
    }

    /**
     * Vue d'un lecteur : liste de ses sections, chacune sous forme de chaîne canonique. Contexte = ensemble de communes d'appartenance du lecteur ;
     * l'organisatrice (indice 0) est l'une d'elles si `$organizer`.
     *
     * @param list<array{int, int, int}> $x
     * @param list<int>                  $member indices des communes dont le lecteur est membre
     */
    private static function view(array $x, array $member, bool $organizer, int $threshold): string
    {
        $stats = self::stats($x);
        $parts = [];
        if ($organizer) {
            $g = P::forOrganizer($stats, $threshold, true);
            $parts[] = json_encode([$g->dossiers, $g->byStatus, $g->communes]);
        }
        foreach ($member as $i) {
            $o = P::forMunicipality($stats, self::IDS[$i], $threshold);
            $parts[] = json_encode([$i, $o->dossiers, $o->byStatus]);
        }

        return implode('|', $parts);
    }

    /**
     * @param list<int> $values
     * @param list<int> $member
     */
    private function assertNoSensitiveCellIsDeduced(array $values, int $threshold, array $member, bool $organizer): void
    {
        $cells = self::cellsOf($values);
        $classes = [];
        foreach ($cells as $c0) {
            foreach ($cells as $c1) {
                foreach ($cells as $c2) {
                    $x = [$c0, $c1, $c2];
                    $classes[self::view($x, $member, $organizer, $threshold)][] = $x;
                }
            }
        }
        $checked = 0;
        foreach ($classes as $vectors) {
            foreach ([0, 1, 2] as $commune) {
                if (\in_array($commune, $member, true)) {
                    continue; // ses propres communes : le lecteur y a droit
                }
                for ($cell = 0; $cell < 3; ++$cell) {
                    $possible = [];
                    foreach ($vectors as $y) {
                        $possible[$y[$commune][$cell]] = true;
                    }
                    foreach ($vectors as $x) {
                        $v = $x[$commune][$cell];
                        if ($v >= 1 && $v < $threshold) {
                            ++$checked;
                            self::assertGreaterThanOrEqual(2, \count($possible), sprintf('cellule %d de la commune %d (valeur %d) déduite par la vue du lecteur', $cell, $commune, $v));
                        }
                    }
                    // Les effectifs TOTAUX d'une commune (somme des cellules) sont aussi sensibles.
                    $totals = [];
                    foreach ($vectors as $y) {
                        $totals[array_sum($y[$commune])] = true;
                    }
                    foreach ($vectors as $x) {
                        $t = array_sum($x[$commune]);
                        if ($t >= 1 && $t < $threshold) {
                            ++$checked;
                            self::assertGreaterThanOrEqual(2, \count($totals), sprintf('total de la commune %d (valeur %d) déduit', $commune, $t));
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(0, $checked, 'le test doit réellement exercer des cellules sensibles');
    }

    /** @return iterable<string, array{list<int>, int, list<int>, bool}> */
    public static function readers(): iterable
    {
        foreach ([[range(0, 7), 5], [range(0, 5), 3]] as [$values, $threshold]) {
            yield "T=$threshold organisatrice seule" => [$values, $threshold, [], true];
            yield "T=$threshold organisatrice et sa commune" => [$values, $threshold, [0], true];
            yield "T=$threshold participante seule" => [$values, $threshold, [1], false];
            yield "T=$threshold deux appartenances (organisatrice et participante)" => [$values, $threshold, [0, 1], true];
            yield "T=$threshold deux participantes" => [$values, $threshold, [1, 2], false];
        }
    }

    /**
     * @param list<int> $values
     * @param list<int> $member
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('readers')]
    public function testNoSensitiveCellOfAnotherMunicipalityCanBeDeducedFromWhatOneReaderSees(array $values, int $threshold, array $member, bool $organizer): void
    {
        $this->assertNoSensitiveCellIsDeduced($values, $threshold, $member, $organizer);
    }
}

/** Constantes de portée relues sans dépendre du détail de la classe publiée. */
final class PublishedClosureStatisticsScope
{
    public const COMMUNE = \App\Event\Domain\PublishedClosureStatistics::SCOPE_COMMUNE;
}
