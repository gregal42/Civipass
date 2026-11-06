<?php

declare(strict_types=1);

namespace App\Request\Application\Command;

/** Justificatif téléversé, désigné par sa clé temporaire (non fiable : revérifiée) et son nom d'affichage. */
final readonly class AttachmentReference
{
    public function __construct(
        public string $temporaryKey,
        public string $originalName,
    ) {
    }
}
