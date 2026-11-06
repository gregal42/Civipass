<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Stockage de fichiers PRIVÉ (STD) : aucune URL, aucun chemin ne remonte à l'appelant, seulement des clés opaques.
 *
 * Deux zones : `tmp/` (téléversements pas encore rattachés à un dossier, purgés après expiration) et `files/`
 * (fichiers rattachés, conservés selon la politique de conservation des dossiers). Les clés sont générées par le stockage
 * (aléatoires, 128 bits) ; toute clé reçue de l'extérieur est revalidée, ce qui exclut toute traversée de répertoires.
 * Le fichier n'est jamais lu ni servi par le serveur web : seule l'application le lit, après contrôle des permissions.
 */
interface FileStorageInterface
{
    public const TEMPORARY_KEY_PATTERN = '/^tmp\/[a-f0-9]{32}$/';
    public const PERMANENT_KEY_PATTERN = '/^files\/[a-f0-9]{32}$/';

    /** Copie un fichier local dans la zone temporaire et renvoie sa clé opaque. */
    public function putTemporary(string $localPath): string;

    /**
     * Rattache un fichier temporaire : crée l'entrée définitive (même contenu) et renvoie sa clé. Le fichier temporaire
     * n'est PAS supprimé : il le sera après la validation du dépôt, ou par la purge. Opération en O(1) sur disque local.
     */
    public function promote(string $temporaryKey): string;

    /**
     * @return resource
     *
     * @throws \RuntimeException fichier absent
     */
    public function readStream(string $key);

    public function exists(string $key): bool;

    /** Suppression sûre et idempotente : un fichier absent n'est pas une erreur. */
    public function delete(string $key): void;

    /**
     * Purge technique : supprime les fichiers TEMPORAIRES plus anciens que `$limit` ; ne touche jamais la zone définitive.
     *
     * @return int nombre de fichiers supprimés
     */
    public function deleteTemporaryOlderThan(\DateTimeImmutable $limit): int;
}
