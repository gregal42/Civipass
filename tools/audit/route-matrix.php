<?php

declare(strict_types=1);

/**
 * LOT 11.1 — matrice routes -> contrôle d'accès (LECTURE SEULE, aucune écriture, aucune requête HTTP).
 *
 * Usage : docker exec saas_civipass_php-fpm php tools/audit/route-matrix.php [--format=md|tsv]
 *
 * Pour chaque route : classe de surface (public, public-jeton, agent, cws, controle, sonata, dev), contrôle du pare-feu
 * (access_control de config/packages/security.yaml appliqué par motif), puis marqueurs applicatifs trouvés dans le code du
 * contrôleur (IsGranted, denyAccessUnlessGranted, ShellContextFactory::forMunicipality, politiques, lecteurs à périmètre…).
 * Une route « agent » ou « cws » sans AUCUN marqueur applicatif est signalée « SANS_MARQUEUR » : à relire à la main
 * (heuristique : un marqueur prouve la présence d'un contrôle, pas sa justesse).
 */

use App\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('dev', false);
$kernel->boot();
$router = $kernel->getContainer()->get('router');
$format = 'md';
foreach ($argv as $a) {
    if (str_starts_with($a, '--format=')) {
        $format = substr($a, 9);
    }
}

const MARKERS = [
    'IsGranted' => '/#\[IsGranted|denyAccessUnlessGranted|->isGranted\(/',
    'forMunicipality' => '/shell->forMunicipality|ShellContextFactory|->forMunicipality\(/',
    'politique' => '/Policy|AccessPolicy|Authorizer|Authorization|CwsPermission|PermissionChecker/',
    'lecteur-périmètre' => '/(Reader|Finder|Locator|Resolver|Loader)->\w+\(\s*\$user/',
    'csrf' => '/isCsrfTokenValid|csrf_token|CsrfToken/',
    'secret-url' => '/\$token|\$secret|\$eventReference/',
];

/** @var list<array<string, string>> $rows */
$rows = [];
foreach ($router->getRouteCollection() as $name => $route) {
    if (str_starts_with($name, '_')) {
        continue;
    }
    $path = $route->getPath();
    $ctl = $route->getDefault('_controller');
    $class = null;
    $method = '__invoke';
    if (\is_string($ctl)) {
        [$class, $m] = str_contains($ctl, '::') ? explode('::', $ctl, 2) : [$ctl, '__invoke'];
        $method = $m;
    } elseif (\is_array($ctl)) {
        [$class, $method] = $ctl;
    }
    $surface = match (true) {
        str_starts_with($path, '/dev/') => 'dev',
        str_starts_with($path, '/admin/cws') => 'cws',
        str_starts_with($path, '/admin') => 'sonata',
        str_starts_with($path, '/controle') => 'controle',
        str_starts_with($path, '/v/') => 'public-jeton',
        str_starts_with($path, '/laissez-passer/') || str_starts_with($path, '/invitation') || str_starts_with($path, '/demandes') => 'public-secret',
        str_starts_with($path, '/communes') => 'agent',
        \in_array($path, ['/', '/login', '/logout'], true) || str_starts_with($path, '/forgot-password') || str_starts_with($path, '/reset-password') => 'public',
        str_starts_with($path, '/session') => 'agent-session',
        default => 'autre',
    };
    $found = [];
    $src = '';
    if (\is_string($class) && class_exists($class)) {
        $rc = new ReflectionClass($class);
        $file = $rc->getFileName();
        $all = file_get_contents($file);
        if ($rc->hasMethod($method)) {
            $rm = $rc->getMethod($method);
            $lines = file($file);
            $body = implode('', \array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
            $src = $body;
            $attrs = array_map(static fn ($a) => $a->getName(), array_merge($rm->getAttributes(), $rc->getAttributes()));
            foreach ($attrs as $a) {
                if (str_contains($a, 'IsGranted')) {
                    $found['IsGranted(attr)'] = true;
                }
            }
        }
        foreach (MARKERS as $label => $re) {
            if (preg_match($re, $src) || ('politique' === $label && preg_match($re, $all)) || ('forMunicipality' === $label && preg_match($re, $src))) {
                $found[$label] = true;
            }
        }
        // Contrôle délégué au constructeur / à un service injecté dans la méthode (forMunicipality appelé via un helper privé de la classe).
        if (!isset($found['forMunicipality']) && preg_match('/\$this->\w+\(\$user,\s*\$municipalityId/', $src)) {
            $found['helper(user,municipalityId)'] = true;
        }
    }
    $gate = 'aucun';
    if (str_starts_with($path, '/admin')) {
        $gate = str_starts_with($path, '/admin/cws') ? 'ROLE_SUPER_ADMIN+FULLY (access_control)' : 'ROLE_SUPER_ADMIN+FULLY ; écriture refusée (access_control)';
    } elseif (str_starts_with($path, '/communes')) {
        $gate = (bool) preg_match('#^/communes/[^/]+/(equipe/.+|evenements/(nouveau|[^/]+/(modifier|inviter|invitations/|participation/|cycle/|controle/.+|zones/(nouvelle|[^/]+/(modifier|confirmer|suspendre|reactiver|supprimer|validite)))))#', $path) ? 'FULLY' : 'REMEMBERED';
    } elseif (str_starts_with($path, '/v/') || str_starts_with($path, '/controle')) {
        $gate = 'pare-feu sans sécurité (voulu)';
    } elseif ('public' === $surface || 'public-secret' === $surface) {
        $gate = 'public (voulu) ; secret en URL';
    }
    $needs = \in_array($surface, ['agent', 'cws'], true) && !$found;
    $rows[] = [
        'name' => $name, 'methods' => implode('|', $route->getMethods() ?: ['ANY']), 'path' => $path, 'surface' => $surface, 'gate' => $gate,
        'markers' => implode(',', array_keys($found)) ?: '-', 'flag' => $needs ? 'SANS_MARQUEUR' : '',
        'ctl' => \is_string($class) ? substr(strrchr('\\'.$class, '\\'), 1).'::'.$method : '?',
    ];
}
usort($rows, static fn (array $a, array $b): int => [$a['surface'], $a['path']] <=> [$b['surface'], $b['path']]);
if ('tsv' === $format) {
    foreach ($rows as $r) {
        echo implode("\t", $r), "\n";
    }
    exit(0);
}
echo "| Surface | Méthode | Chemin | Contrôleur | Pare-feu / access_control | Marqueurs applicatifs | Alerte |\n|---|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    echo "| {$r['surface']} | {$r['methods']} | `{$r['path']}` | {$r['ctl']} | {$r['gate']} | {$r['markers']} | {$r['flag']} |\n";
}
