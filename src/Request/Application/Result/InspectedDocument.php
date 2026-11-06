<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

/** Justificatif contrôlé : nom d'affichage assaini, type MIME normalisé et taille RÉELLE. */
final readonly class InspectedDocument
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
    ) {
    }
}
