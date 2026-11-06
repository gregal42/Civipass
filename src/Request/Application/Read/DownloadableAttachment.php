<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/** Justificatif autorisé au téléchargement : la clé de stockage ne sort jamais de la couche application. */
final readonly class DownloadableAttachment
{
    public function __construct(
        public string $storageKey,
        public string $mimeType,
        public string $displayName,
        public int $sizeBytes,
    ) {
    }
}
