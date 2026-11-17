<?php

declare(strict_types=1);

namespace App\Control\Application\Dataset;

/**
 * Format du jeu de données de contrôle hors connexion (LOT 09.3, DT-056) — SPÉCIFICATION PARTAGÉE avec le code JavaScript de l'appareil (`assets/controle/dataset.js`,
 * qui implémente exactement les mêmes calculs ; tests croisés sur des vecteurs communs).
 *
 * Un enregistrement est une ligne de texte : le JSON compact (UTF-8, barres obliques et caractères non ASCII non échappés, ordre de clés fixe) d'un tableau associatif.
 * Deux sortes, identifiées par la clé `k` = SHA-256 hexadécimal (64 minuscules) du JETON du QR en minuscules (jamais le jeton) :
 *  - `{"k","t":"P","r":référence,"f":prénom,"l":nom,"p":[plaques],"z":[{"l":libellé,"s":"V|S|E|F","w":[[début,fin],…]}]}` laissez-passer de la commune ;
 *  - `{"k","t":"R"}` (révoqué) ou `{"k","t":"X"}` (QR remplacé) : empreinte et état seulement, sans nom.
 * Les lignes sont triées par `k` croissant. Condensat racine : SHA-256 hexadécimal des lignes jointes par « \n » (jeu vide : condensat de la chaîne vide). 256
 * compartiments : le compartiment d'une ligne est la valeur (0–255) des deux premiers caractères hexadécimaux de `k` ; condensat d'un compartiment : les 8 premiers
 * caractères hexadécimaux du SHA-256 de ses lignes (triées) jointes par « \n ». Les fenêtres sont des instants UTC en secondes depuis l'époque.
 */
final class DatasetCodec
{
    public const BUCKETS = 256;
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $record */
    public static function line(array $record): string
    {
        return json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public static function tokenKey(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', strtolower($token));
    }

    /**
     * @param array<string, string> $linesByKey lignes indexées par clé
     *
     * @return list<string> lignes triées par clé
     */
    public static function sorted(array $linesByKey): array
    {
        ksort($linesByKey, \SORT_STRING);

        return array_values($linesByKey);
    }

    /** @param list<string> $sortedLines */
    public static function root(array $sortedLines): string
    {
        return hash('sha256', implode("\n", $sortedLines));
    }

    public static function bucketOf(string $key): int
    {
        return (int) hexdec(substr($key, 0, 2));
    }

    /**
     * @param array<string, string> $linesByKey
     *
     * @return list<string> 256 condensats de 8 caractères hexadécimaux
     */
    public static function bucketDigests(array $linesByKey): array
    {
        $buckets = array_fill(0, self::BUCKETS, []);
        ksort($linesByKey, \SORT_STRING);
        foreach ($linesByKey as $key => $line) {
            $buckets[self::bucketOf((string) $key)][] = $line;
        }

        return array_map(static fn (array $lines): string => substr(hash('sha256', implode("\n", $lines)), 0, 8), $buckets);
    }

    /**
     * @param array<string, string> $linesByKey
     *
     * @return list<string> lignes du compartiment, triées
     */
    public static function bucketLines(array $linesByKey, int $bucket): array
    {
        $lines = [];
        ksort($linesByKey, \SORT_STRING);
        foreach ($linesByKey as $key => $line) {
            if (self::bucketOf((string) $key) === $bucket) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Condensats de compartiments reçus d'un appareil : 256 chaînes de 8 caractères hexadécimaux, sinon nul (réponse complète).
     *
     * @return list<string>|null
     */
    public static function parseClientBuckets(mixed $raw): ?array
    {
        if (!\is_array($raw) || self::BUCKETS !== \count($raw) || !array_is_list($raw)) {
            return null;
        }
        foreach ($raw as $digest) {
            if (!\is_string($digest) || 1 !== preg_match('/^[0-9a-f]{8}$/', $digest)) {
                return null;
            }
        }

        return $raw;
    }
}
