<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shell\ShellContextFactory;
use App\Tests\Support\ControlTestCase;

/**
 * O-5 (LOT 10) : navigation mobile à quatre entrées principales au plus et un menu secondaire. La séparation part de la navigation DÉJÀ filtrée par les rôles :
 * elle n'ajoute aucune rubrique et n'en retire aucune (barre basse + feuille « Menu » = barre latérale), pour chaque profil.
 */
final class MobileNavigationTest extends ControlTestCase
{
    /** @return array<string, array{list<array{label: string}>, list<string>, list<string>}> */
    public static function navigations(): array
    {
        $nav = static fn (string ...$labels): array => array_map(static fn (string $l): array => ['label' => $l, 'icon' => 'file', 'href' => '/x', 'current' => false], $labels);

        return [
            'administrateur et instructeur' => [$nav('Accueil', 'Événements', 'Demandes', 'Nouvelle demande', 'Contrôle', 'Corrections d’identité', 'Équipe', 'Archives', 'Conservation des données', 'Droits des personnes'),
                ['Accueil', 'Événements', 'Demandes', 'Nouvelle demande'], ['Contrôle', 'Corrections d’identité', 'Équipe', 'Archives', 'Conservation des données', 'Droits des personnes']],
            'instructeur seul' => [$nav('Accueil', 'Événements', 'Demandes', 'Contrôle', 'Corrections d’identité'),
                ['Accueil', 'Événements', 'Demandes', 'Contrôle'], ['Corrections d’identité']],
            'gestionnaire d’événements seul' => [$nav('Accueil', 'Événements', 'Contrôle', 'Archives'),
                ['Accueil', 'Événements', 'Contrôle', 'Archives'], []],
            'administrateur sans instruction' => [$nav('Accueil', 'Événements', 'Contrôle', 'Corrections d’identité', 'Équipe', 'Archives'),
                ['Accueil', 'Événements', 'Contrôle', 'Corrections d’identité'], ['Équipe', 'Archives']],
        ];
    }

    /**
     * @param list<array{label: string}> $nav
     * @param list<string>               $primary
     * @param list<string>               $secondary
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('navigations')]
    public function testTheSplitKeepsFourMainEntriesInTheOriginalOrderAndNeverAddsOrLosesOne(array $nav, array $primary, array $secondary): void
    {
        [$main, $rest] = ShellContextFactory::splitNavigation($nav);

        self::assertSame($primary, array_column($main, 'label'));
        self::assertSame($secondary, array_column($rest, 'label'));
        self::assertLessThanOrEqual(4, \count($main));
        $all = array_column([...$main, ...$rest], 'label');
        $expected = array_column($nav, 'label');
        sort($all);
        sort($expected);
        self::assertSame($expected, $all, 'rien ajouté ni perdu');
    }

    /** @return list<string> */
    private function labels(string $selector, string $html): array
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        return array_map(static fn (string $t): string => trim((string) preg_replace('/\s+/', ' ', $t)), $crawler->filter($selector)->each(static fn ($n): string => $n->text()));
    }

    public function testThePagesOfEachProfileShowTheSameSectionsOnTheSideBarAsOnTheBottomBarAndTheMenuSheet(): void
    {
        $this->client->disableReboot();
        foreach ([$this->instructorO, $this->adminO] as $user) {
            $this->client->loginUser($this->user($user));
            $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122());
            $html = (string) $this->client->getResponse()->getContent();

            $side = array_values(array_filter($this->labels('.cp-sidebar a.cp-nav__link, aside a.cp-nav__link', $html)));
            $bottom = $this->labels('nav.cp-bottomnav .cp-bottomnav__link', $html);
            $sheet = $this->labels('#cp-mobile-menu .cp-sheet-nav a.cp-nav__link', $html);

            self::assertLessThanOrEqual(5, \count($bottom), 'quatre entrées principales et « Menu »');
            self::assertSame('Menu', end($bottom));
            $sheetSections = array_values(array_filter($sheet, static fn (string $l): bool => !str_contains($l, 'Changer de commune')));
            $mobile = [...array_slice($bottom, 0, -1), ...$sheetSections];
            sort($mobile);
            $desktop = $side;
            sort($desktop);
            self::assertNotSame([], $desktop, 'barre latérale lue');
            self::assertSame($desktop, $mobile, 'barre basse + menu = barre latérale : aucune rubrique ajoutée ni retirée');
        }
    }

    public function testNoProfileEverGainsASectionItsRolesDoNotAllow(): void
    {
        $this->client->disableReboot();
        $this->client->loginUser($this->user($this->instructorO));
        $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();

        foreach (['Équipe', 'Archives', 'Conservation des données', 'Droits des personnes'] as $forbidden) {
            $offered = array_filter($this->labels('nav.cp-bottomnav .cp-bottomnav__link, #cp-mobile-menu .cp-sheet-nav a', $html), static fn (string $l): bool => str_contains($l, $forbidden));
            self::assertCount(0, $offered, $forbidden.' : jamais proposé à un instructeur, ni en barre basse ni en menu');
        }
    }
}
