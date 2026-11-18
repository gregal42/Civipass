<?php

declare(strict_types=1);

namespace App\Request\Application;

/**
 * Purge SERVEUR des brouillons publics abandonnés (LOT 10, O-2, M3 : garantie d'effacement même si le citoyen ne revient jamais).
 *
 * Parcourt le dossier des sessions PHP (gestionnaire « files ») et supprime, avec les justificatifs temporaires qui leur sont rattachés, les
 * sessions ANONYMES inactives depuis plus de `olderThanSeconds` qui portent un brouillon (`public_request.*`). Ne touche JAMAIS une session
 * authentifiée (clé `_security_*`) : un agent connecté n'est pas affecté. Ne lit que des chaînes et des tableaux (aucune classe désérialisée) ;
 * ne journalise aucune donnée : seulement des compteurs.
 */
final readonly class AbandonedDraftPurger
{
    private const MAX_FILE_BYTES = 2_000_000;

    public function __construct(private TemporaryUploadService $uploads)
    {
    }

    /**
     * @return array{scanned: int, drafts: int, files: int, skipped_authenticated: int}
     */
    public function purge(string $directory, int $olderThanSeconds, int $now, bool $dryRun = false): array
    {
        $result = ['scanned' => 0, 'drafts' => 0, 'files' => 0, 'skipped_authenticated' => 0];
        foreach (glob(rtrim($directory, '/').'/sess_*') ?: [] as $file) {
            $modified = is_file($file) && !is_link($file) ? filemtime($file) : false;
            if (false === $modified || $now - $modified <= $olderThanSeconds || (int) filesize($file) > self::MAX_FILE_BYTES) {
                continue;
            }
            $raw = file_get_contents($file);
            $attributes = false === $raw ? null : (self::decode($raw)['_sf2_attributes'] ?? null);
            if (!\is_array($attributes)) {
                continue;
            }
            ++$result['scanned'];
            if (self::authenticated($attributes)) {
                ++$result['skipped_authenticated'];
                continue;
            }
            [$drafts, $keys] = self::drafts($attributes);
            if (0 === $drafts) {
                continue;
            }
            $result['drafts'] += $drafts;
            $result['files'] += \count($keys);
            if (!$dryRun) {
                foreach ($keys as $key) {
                    $this->uploads->discard($key);
                }
                @unlink($file);
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $attributes
     */
    private static function authenticated(array $attributes): bool
    {
        foreach (array_keys($attributes) as $name) {
            if (\is_string($name) && str_starts_with($name, '_security_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $attributes
     *
     * @return array{int, list<string>} nombre de brouillons et clés de leurs pièces temporaires
     */
    private static function drafts(array $attributes): array
    {
        $count = 0;
        $keys = [];
        foreach ($attributes as $name => $draft) {
            if (!\is_string($name) || !str_starts_with($name, 'public_request.')) {
                continue;
            }
            ++$count;
            foreach (\is_array($draft) && \is_array($draft['files'] ?? null) ? $draft['files'] : [] as $file) {
                if (\is_array($file) && \is_string($file['key'] ?? null)) {
                    $keys[] = $file['key'];
                }
            }
        }

        return [$count, $keys];
    }

    /**
     * Décode le format du gestionnaire « php » (`nom|valeur sérialisée` répété), sans instancier aucune classe.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        $out = [];
        $position = 0;
        $length = \strlen($raw);
        while ($position < $length) {
            $bar = strpos($raw, '|', $position);
            if (false === $bar) {
                break;
            }
            $rest = substr($raw, $bar + 1);
            $value = @unserialize($rest, ['allowed_classes' => false]);
            if (false === $value && !str_starts_with($rest, 'b:0;')) {
                break;
            }
            $out[substr($raw, $position, $bar - $position)] = $value;
            $position = $bar + 1 + \strlen(serialize($value));
        }

        return $out;
    }
}
