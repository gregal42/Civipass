<?php

declare(strict_types=1);

namespace App\Control\UI\Http;

/**
 * Liste blanche EXPLICITE de ce que l'origine dédiée au contrôle peut servir (LOT 10, G1, DT-056) : routes de la PWA et de l'API d'appareil avec leur
 * méthode HTTP, plus les ressources statiques strictement nécessaires à la PWA. Tout le reste est inaccessible depuis cette origine. La même liste
 * sert de source au futur vhost Nginx ; un test échoue si une route `/controle…` de l'application n'y figure pas.
 *
 * La vérification en ligne `/v/{jeton}` n'en fait PAS partie : la PWA l'ouvre sur l'origine de gestion (`verification_base`, voir `scan.js`).
 */
final class ControlRouteAllowlist
{
    /** @var list<array{string, string}> méthode et chemin exact */
    public const EXACT = [
        ['GET', '/controle/'],
        ['GET', '/controle/manifest.webmanifest'],
        ['GET', '/controle/sw.js'],
        ['GET', '/controle/api/ping'],
        ['GET', '/controle/api/device'],
        ['POST', '/controle/api/activation'],
        ['POST', '/controle/api/sync'],
    ];

    /** Modules de l'application (même contrainte que la route `app_control_script`). */
    public const SCRIPT_PATTERN = '#^/controle/js/[a-z]+(?:-[a-z]+)*\.js$#';

    /**
     * @var list<string> ressources compilées strictement nécessaires à la PWA (lecture seule) : même expression que le gabarit Nginx
     *                   (caractères sûrs seulement, jamais `..` ni `%`), pour qu'un chemin détourné ne passe pas non plus par l'application
     */
    public const STATIC_PATTERNS = [
        '#^/assets/(?:styles|vendor/jsqr|fonts)/(?!.*\.\.)[A-Za-z0-9_./-]+$#',
        '#^/assets/images/control-icon-[A-Za-z0-9_.-]+$#',
    ];

    public static function allows(string $method, string $path): bool
    {
        $method = 'HEAD' === strtoupper($method) ? 'GET' : strtoupper($method);
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '//')) {
            return false;
        }
        foreach (self::EXACT as [$allowedMethod, $allowedPath]) {
            if ($method === $allowedMethod && $path === $allowedPath) {
                return true;
            }
        }
        if ('GET' !== $method) {
            return false;
        }
        if (1 === preg_match(self::SCRIPT_PATTERN, $path)) {
            return true;
        }
        foreach (self::STATIC_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
