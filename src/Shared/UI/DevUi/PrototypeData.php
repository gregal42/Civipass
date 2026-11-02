<?php

declare(strict_types=1);

namespace App\Shared\UI\DevUi;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Données FICTIVES du prototype UI-001 du design system et contextes Twig des écrans de prévisualisation (/dev/ui).
 * Aucune entité Doctrine, aucune base, aucun droit : ni service métier ni donnée réelle. Environnement dev uniquement.
 *
 * Portage PHP de prototype/data.mjs (données, exportées en JSON par design-system/tools/import.mjs) et de
 * scripts/prototype.mjs (construction des contextes) du design system.
 */
#[When(env: 'dev')]
final class PrototypeData
{
    private const PAGE_SIZE = 20;
    private const MODE_LABEL = ['vehicle' => 'Véhicule', 'foot' => 'À pied', 'bike' => 'À vélo'];
    private const MODE_ICON = ['vehicle' => 'car', 'foot' => 'walk', 'bike' => 'bike'];

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
    ) {
        $this->data = json_decode((string) file_get_contents(__DIR__.'/Resources/prototype.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public function communeSlugs(): array
    {
        return array_keys($this->data['communes']);
    }

    /**
     * Demandes visibles par la commune, les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function requestsOf(string $slug): array
    {
        $mine = array_values(array_filter($this->data['requests'], static fn (array $q): bool => isset($q['decisions'][$slug])));
        usort($mine, static fn (array $a, array $b): int => $b['n'] <=> $a['n']);

        return $mine;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRequest(string $slug, string $ref): ?array
    {
        foreach ($this->requestsOf($slug) as $q) {
            if ($q['ref'] === $ref) {
                return $q;
            }
        }

        return null;
    }

    /**
     * @return array{total: int, pending: int, approved: int, refused: int, valid: int}
     */
    public function stats(string $slug): array
    {
        $mine = $this->requestsOf($slug);
        $count = static fn (string $s): int => \count(array_filter($mine, static fn (array $r): bool => $r['decisions'][$slug] === $s));
        $valid = \count(array_filter($mine, static fn (array $r): bool => 'approved' === $r['decisions'][$slug] && !('le-temple' === $slug && ($r['upcoming'] ?? false))));

        return ['total' => \count($mine), 'pending' => $count('pending'), 'approved' => $count('approved'), 'refused' => $count('refused'), 'valid' => $valid];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(string $slug, bool $switched): array
    {
        $ctx = $this->shell($slug, 'dashboard', ['page_title' => 'Tableau de bord', 'switch_banner' => $switched]);
        $mine = $this->requestsOf($slug);
        $ctx['recent'] = array_map(fn (array $q): array => $this->row($q, $slug), \array_slice($mine, 0, 5));
        $pending = array_values(array_filter($mine, static fn (array $q): bool => 'pending' === $q['decisions'][$slug]));
        $ctx['pending_cards'] = array_map(fn (array $q): array => $this->row($q, $slug), \array_slice($pending, 0, 3));
        $ctx['ev'] = $this->data['event'];
        $ctx['draft'] = $this->data['draft'];
        $ctx['is_organizer'] = 'le-temple' === $slug;

        return $ctx;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    public function requestList(string $slug, array $query): array
    {
        $ctx = $this->shell($slug, 'requests', ['page_title' => 'Demandes']);
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'statut' => \in_array($query['statut'] ?? '', ['pending', 'approved', 'refused'], true) ? $query['statut'] : '',
            'depot' => \in_array($query['depot'] ?? '', ['today', 'before'], true) ? $query['depot'] : '',
            'tri' => \in_array($query['tri'] ?? '', ['old', 'name'], true) ? $query['tri'] : 'recent',
        ];

        $rows = array_filter($this->requestsOf($slug), function (array $q) use ($slug, $filters): bool {
            if ('' !== $filters['statut'] && $q['decisions'][$slug] !== $filters['statut']) {
                return false;
            }
            if ('today' === $filters['depot'] && !$q['today']) {
                return false;
            }
            if ('before' === $filters['depot'] && $q['today']) {
                return false;
            }

            return '' === $filters['q'] || $this->matches($q, $filters['q']);
        });
        $rows = array_values($rows);
        usort($rows, match ($filters['tri']) {
            'old' => static fn (array $a, array $b): int => $a['n'] <=> $b['n'],
            'name' => static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']),
            default => static fn (array $a, array $b): int => $b['n'] <=> $a['n'],
        });

        $total = \count($rows);
        $pages = (int) ceil($total / self::PAGE_SIZE);
        $page = max(1, min($pages, (int) ($query['page'] ?? 1)));
        $slice = \array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $from = 0 === $total ? 0 : ($page - 1) * self::PAGE_SIZE + 1;
        $to = $from + \count($slice) - ($total > 0 ? 1 : 0);

        $list = $this->urls->generate('dev_ui_requests', ['slug' => $slug]);
        $base = static fn (array $f): string => http_build_query(array_filter($f, static fn (string $v, string $k): bool => '' !== $v && !('tri' === $k && 'recent' === $v), \ARRAY_FILTER_USE_BOTH));
        $withQuery = static fn (string $qs): string => '' === $qs ? $list : $list.'?'.$qs;

        $chips = [];
        $labels = ['statut' => ['pending' => 'En attente', 'approved' => 'Approuvée', 'refused' => 'Refusée'], 'depot' => ['today' => 'Aujourd’hui (01/11)', 'before' => 'Avant aujourd’hui']];
        foreach (['q' => 'Recherche', 'statut' => 'Statut', 'depot' => 'Dépôt'] as $key => $label) {
            if ('' !== $filters[$key]) {
                $value = $labels[$key][$filters[$key]] ?? $filters[$key];
                $chips[] = ['label' => $label.' : '.$value, 'remove_href' => $withQuery($base([...$filters, $key => '']))];
            }
        }
        $sortLabel = ['recent' => 'plus récentes d’abord', 'old' => 'plus anciennes d’abord', 'name' => 'demandeur (A → Z)'][$filters['tri']];
        $pill = fn (string $status): string => trim($this->twig->render('components/status_pill.html.twig', ['status' => $status]));

        return $ctx + [
            'filters' => $filters,
            'total' => $total,
            'chips' => $chips,
            'active_count' => \count($chips),
            'caption' => 0 === $total ? 'Aucun résultat' : sprintf('%d–%d sur %d demandes · %s', $from, $to, $total, $sortLabel),
            'sort_dir' => 'name' === $filters['tri'] ? '' : ('old' === $filters['tri'] ? 'asc' : 'desc'),
            'sort_href' => $withQuery($base([...$filters, 'tri' => 'old' === $filters['tri'] ? 'recent' : 'old'])),
            'pg' => [
                'from' => $from, 'to' => $to, 'page' => $page, 'pages' => $pages,
                'pattern' => '' === ($qs = $base($filters)) ? '?page=__PAGE__' : '?'.$qs.'&page=__PAGE__',
            ],
            'table_rows' => array_map(function (array $q) use ($slug, $pill): array {
                $x = $this->row($q, $slug);

                return ['cells' => [
                    'name' => ['text' => $x['name'], 'href' => $x['href']],
                    'ref' => ['text' => $x['ref'], 'mono' => true],
                    'terr' => ['text' => $x['territory']],
                    'when' => ['text' => $x['when'], 'muted' => true],
                    'status' => ['html' => $pill($x['status'])],
                ]];
            }, $slice),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function request(string $slug, string $ref, bool $flash): ?array
    {
        $q = $this->findRequest($slug, $ref);
        $mine = null === $q ? null : current(array_filter($q['territories'], static fn (array $t): bool => $t['commune'] === $slug));
        if (null === $q || !$mine) {
            return null;
        }
        $status = $q['decisions'][$slug];
        $name = fn (string $c): string => $this->data['communes'][$c]['name'];
        $zone = fn (string $c, string $z): string => $this->zoneName($c, $z);
        $dash = fn (string $s): string => str_replace(' à ', ' · ', $s);
        $approved = array_filter($q['territories'], static fn (array $t): bool => 'approved' === $q['decisions'][$t['commune']]);
        $period = $q['period'] ?? null;

        $ctx = $this->shell($slug, 'request', ['page_title' => 'Demande '.$q['ref']]);

        return $ctx + [
            'flash' => $flash,
            'q' => $q,
            'status' => $status,
            'my_zone' => $zone($slug, $mine['zone']),
            'my_zone_id' => $mine['zone'],
            'zones' => array_map(static fn (array $z): array => $z + ['requested' => $z['id'] === $mine['zone']], $this->data['zones'][$slug]),
            'req_modes' => $q['modes'],
            'all_modes' => array_map(static fn (string $m): array => ['id' => $m, 'label' => self::MODE_LABEL[$m], 'icon' => self::MODE_ICON[$m], 'requested' => \in_array($m, $q['modes'], true)], ['vehicle', 'foot', 'bike']),
            'plate_items' => array_map(static fn (string $p): array => ['number' => $p], $q['plates']),
            'doc_items' => array_map(static fn (array $d): array => $d + ['date' => $q['date'], 'modal' => 'doc-viewer'], $q['docs']),
            'decisions' => array_map(static fn (array $t): array => [
                'commune' => $name($t['commune']), 'territory' => $zone($t['commune'], $t['zone']), 'status' => $q['decisions'][$t['commune']], 'mine' => $t['commune'] === $slug,
                'who' => $t['commune'] === $slug ? ('pending' === $q['decisions'][$t['commune']] ? 'À instruire par vous' : 'Décision de '.$name($slug)) : 'Décision de '.$name($t['commune']).' (lecture seule)',
            ], $q['territories']),
            'grants' => array_values(array_map(static fn (array $t): array => [
                'commune' => $name($t['commune']), 'mine' => $t['commune'] === $slug,
                'by' => $t['commune'] === $slug ? sprintf('Accordé le %s par %s', $q['decided_at'], $q['agent']) : 'Accordé par '.$name($t['commune']),
                'period' => $period ? $period['start'].' → '.$period['end'] : '01/11/2026 à 10:00 → 05/11/2026 à 20:00',
                'zones' => $zone($t['commune'], $t['zone']), 'modes' => $q['modes'], 'plates' => $q['plates'],
            ], $approved)),
            'list_href' => $this->urls->generate('dev_ui_requests', ['slug' => $slug]),
            'pass_rights' => array_values(array_map(static fn (array $t): array => [
                'commune' => $name($t['commune']), 'zone' => $zone($t['commune'], $t['zone']),
                'period' => $period ? substr($period['start'], 0, 5).' '.substr($period['start'], -5).' → 05/11 20:00' : '01/11 10:00 → 05/11 20:00',
            ], $approved)),
            'qr_svg' => \in_array('approved', $q['decisions'], true) ? $this->fakeQr((int) $q['n']) : '',
            'validity' => $this->validity($q, $slug),
            'history' => $this->history($q, $slug, $status, $dash),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forbidden(): array
    {
        return $this->shell('saumos', 'users', ['page_title' => 'Accès refusé']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function shell(string $slug, string $page, array $extra = []): array
    {
        $c = $this->data['communes'][$slug];
        $can = static fn (string $p): bool => \in_array($p, $c['permissions'], true);
        $st = $this->stats($slug);
        $dashboard = $this->urls->generate('dev_ui_dashboard', ['slug' => $slug]);
        $requests = $this->urls->generate('dev_ui_requests', ['slug' => $slug]);

        $all = [
            ['dashboard', 'Tableau de bord', 'home', $dashboard], ['requests', 'Demandes', 'inbox', $requests],
            ['passes', 'Laissez-passer', 'pass', '#'], ['events', 'Événements', 'flag', '#'],
            ['zones', 'Zones', 'map', '#'], ['users', 'Utilisateurs', 'users', '#'], ['settings', 'Paramètres', 'settings', '#'],
        ];
        $navItems = [];
        foreach ($all as [$p, $label, $icon, $href]) {
            if ($can($p)) {
                $navItems[] = ['label' => $label, 'icon' => $icon, 'href' => $href, 'current' => $p === $page, 'count' => 'requests' === $p ? $st['pending'] : 0];
            }
        }

        $other = 'le-temple' === $slug ? 'saumos' : 'le-temple';
        $dashboardOf = fn (string $s): string => $this->urls->generate('dev_ui_dashboard', ['slug' => $s]);
        $commune = [
            'id' => 'commune', 'kicker' => 'Commune active', 'value' => $c['name'],
            'options' => array_map(fn (string $s): array => [
                'label' => $this->data['communes'][$s]['name'], 'description' => $this->data['communes'][$s]['roles'], 'current' => $s === $slug,
                'href' => $s === $slug ? $dashboardOf($s) : $dashboardOf($s).'?bascule=1',
            ], [$slug, $other]),
            'note' => 'Vos rôles et les données affichées dépendent de la commune choisie.',
            'change_href' => $dashboardOf($other).'?bascule=1',
        ];
        $ev = $this->data['event'];
        $draftOption = 'le-temple' === $slug ? [['label' => $this->data['draft']['title'], 'description' => 'dashboard' === $page ? 'Brouillon' : 'Brouillon · aucune demande', 'href' => '#']] : [];
        $event = 'dashboard' === $page
            ? ['id' => 'event', 'kicker' => 'Événement', 'value' => 'Tous les événements', 'options' => [['label' => 'Tous les événements', 'current' => true, 'href' => '#'], ['label' => $ev['title'], 'description' => 'Actif', 'href' => '#'], ...$draftOption]]
            : ['id' => 'event', 'kicker' => 'Événement', 'value' => $ev['title'], 'options' => [['label' => $ev['title'], 'description' => 'Actif', 'current' => true, 'href' => $requests], ...$draftOption]];

        return [
            'slug' => $slug, 'page' => $page, 'st' => $st,
            'can' => array_fill_keys($c['permissions'], true), 'commune_name' => $c['name'],
            'nav_items' => $navItems, 'commune' => $commune, 'event' => $event, 'role' => $c['role'],
            'user' => $this->data['user'] + ['role' => $c['role']],
            'menu_items' => array_values(array_filter($navItems, static fn (array $i): bool => !\in_array($i['label'], ['Tableau de bord', 'Demandes', 'Laissez-passer'], true))),
            'bottom_items' => [
                ['label' => 'Accueil', 'icon' => 'home', 'href' => $dashboard, 'current' => 'dashboard' === $page],
                ['label' => 'Demandes', 'icon' => 'inbox', 'href' => $requests, 'current' => \in_array($page, ['requests', 'request'], true), 'count' => $st['pending']],
                ['label' => 'Pass', 'icon' => 'pass', 'href' => '#'], ['label' => 'Menu', 'icon' => 'menu', 'modal' => 'cp-mobile-menu'],
            ],
            'ctx_parts' => 'dashboard' === $page
                ? [['text' => $c['name'], 'strong' => true], ['text' => '1 événement actif'], ['text' => $st['pending'].' en attente', 'tone' => 'signal']]
                : [['text' => $c['name'], 'strong' => true], ['text' => $ev['title']]],
            'home' => $dashboard,
            // Prévisualisation : la « déconnexion » du menu renvoie à l'index, elle ne touche pas à la session réelle.
            'logout_href' => $this->urls->generate('dev_ui_logout'),
            'links' => ['dashboard' => $dashboard, 'requests' => $requests, 'requests_pending' => $requests.'?statut=pending'],
        ] + $extra;
    }

    /**
     * @param array<string, mixed> $q
     *
     * @return array<string, mixed>
     */
    private function row(array $q, string $slug): array
    {
        $name = fn (string $c): string => $this->data['communes'][$c]['name'];

        return [
            'ref' => $q['ref'], 'n' => $q['n'], 'name' => $q['name'],
            'href' => $this->urls->generate('dev_ui_request', ['slug' => $slug, 'ref' => $q['ref']]),
            'status' => $q['decisions'][$slug], 'date' => $q['date'], 'time' => $q['time'],
            'when' => substr($q['date'], 0, 5).' '.$q['time'], 'today' => $q['today'],
            'territory' => implode(' · ', array_map(fn (array $t): string => $name($t['commune']).' — '.$this->zoneName($t['commune'], $t['zone']), $q['territories'])),
            'terr_list' => array_map(fn (array $t): array => ['commune' => $name($t['commune']), 'zone' => $this->zoneName($t['commune'], $t['zone']), 'mine' => $t['commune'] === $slug], $q['territories']),
            'modes' => $q['modes'], 'plates' => $q['plates'], 'docs' => \count($q['docs']),
        ];
    }

    private function zoneName(string $commune, string $zone): string
    {
        foreach ($this->data['zones'][$commune] as $z) {
            if ($z['id'] === $zone) {
                return $z['name'];
            }
        }

        return $zone;
    }

    /**
     * @param array<string, mixed> $q
     */
    private function matches(array $q, string $term): bool
    {
        $haystack = mb_strtolower(implode(' ', [$q['name'], $q['ref'], $q['pass_ref'], $q['email'], str_replace(' ', '', $q['phone']), ...array_map(static fn (string $p): string => str_replace('-', '', $p), $q['plates'])]));
        $needle = mb_strtolower($term);

        return str_contains($haystack, $needle) || str_contains($haystack, str_replace([' ', '-'], '', $needle));
    }

    /**
     * @param array<string, mixed> $q
     *
     * @return array{state: string, detail: string}|null
     */
    private function validity(array $q, string $slug): ?array
    {
        $ok = \count(array_filter($q['decisions'], static fn (string $s): bool => 'approved' === $s));
        $total = \count($q['decisions']);
        if (0 === $ok) {
            return null;
        }
        if (($q['upcoming'] ?? false) && 'le-temple' === $slug) {
            return ['state' => 'upcoming', 'detail' => 'à partir du '.$q['period']['start']];
        }

        return $ok < $total
            ? ['state' => 'partial', 'detail' => sprintf('%d commune%s sur %d · jusqu’au 05/11/2026 à 20:00', $ok, $ok > 1 ? 's' : '', $total)]
            : ['state' => 'valid', 'detail' => 'jusqu’au 05/11/2026 à 20:00'];
    }

    /**
     * @param array<string, mixed>     $q
     * @param callable(string): string $dash
     *
     * @return list<array<string, string>>
     */
    private function history(array $q, string $slug, string $status, callable $dash): array
    {
        $name = fn (string $c): string => $this->data['communes'][$c]['name'];
        $decidedAt = $dash($q['decided_at']);
        $period = $q['period'] ?? null;
        $items = [['time' => $q['date'].' · '.$q['time'], 'text' => 'Demande déposée via le formulaire public ('.implode(', ', array_map(fn (array $t): string => $name($t['commune']), $q['territories'])).').']];
        if (156 === $q['n']) {
            $items[] = ['time' => '01/11/2026 · 10:03', 'text' => 'Dossier ouvert par Sophie Lambert — instruction en cours.', 'kind' => 'agent'];
        }
        foreach ($q['territories'] as $t) {
            if ($t['commune'] !== $slug && 'pending' !== $q['decisions'][$t['commune']]) {
                $approved = 'approved' === $q['decisions'][$t['commune']];
                $items[] = ['time' => $decidedAt, 'text' => $name($t['commune']).' : demande '.($approved ? 'approuvée' : 'refusée').' pour son territoire.', 'kind' => $approved ? 'ok' : 'bad'];
            }
        }
        if ('approved' === $status) {
            $items[] = ['time' => $decidedAt, 'text' => sprintf('Approuvée par %s pour %s — du %s au %s.', $q['agent'], $name($slug), $period['start'] ?? '01/11/2026 à 10:00', $period['end'] ?? '05/11/2026 à 20:00'), 'kind' => 'ok'];
            $items[] = ['time' => $decidedAt, 'text' => sprintf('Laissez-passer %s émis (QR stable).', $q['pass_ref']), 'kind' => 'ok'];
        }
        if ('refused' === $status) {
            $items[] = ['time' => $decidedAt, 'text' => sprintf('Refusée par %s pour %s%s.', $q['agent'], $name($slug), $q['refuseReason'] ? ' — motif saisi' : ''), 'kind' => 'bad'];
        }

        return $items;
    }

    /**
     * Motif de QR FICTIF et déterministe (non scannable) : aucune bibliothèque de QR n'est installée à ce stade
     * (elle arrivera avec le lot Laissez-passer).
     */
    private function fakeQr(int $seed): string
    {
        $size = 25;
        $cells = '';
        $state = $seed * 2654435761 % 4294967296;
        $finder = static fn (int $x, int $y): bool => ($x < 7 && $y < 7) || ($x >= $size - 7 && $y < 7) || ($x < 7 && $y >= $size - 7);
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                $state = ($state * 1103515245 + 12345) % 2147483648;
                $on = $finder($x, $y)
                    ? (($x % ($size - 7) < 7 && $y % ($size - 7) < 7) && ($x % ($size - 7) <= 0 || $x % ($size - 7) >= 6 || $y % ($size - 7) <= 0 || $y % ($size - 7) >= 6 || ($x % ($size - 7) >= 2 && $x % ($size - 7) <= 4 && $y % ($size - 7) >= 2 && $y % ($size - 7) <= 4)))
                    : 0 === ($state >> 16) % 2;
                if ($on) {
                    $cells .= sprintf('<rect x="%d" y="%d" width="1" height="1"/>', $x, $y);
                }
            }
        }

        return sprintf('<svg aria-hidden="true" focusable="false" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges" fill="currentColor">%2$s</svg>', $size, $cells);
    }
}
