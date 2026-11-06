<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

/** Téléversement accepté et stocké en zone temporaire : seule la clé opaque est communicable au citoyen. */
final readonly class StoredTemporaryUpload
{
    public function __construct(
        public string $temporaryKey,
        public InspectedDocument $document,
    ) {
    }
}
