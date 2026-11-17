<?php

declare(strict_types=1);

namespace App\Control\Application\Dataset;

/** Jeu de données COMPLET construit pour un appareil à un instant : enveloppe, lignes indexées par clé, condensats. Jamais conservé côté serveur. */
final readonly class ControlDataset
{
    /**
     * @param array<string, mixed>  $envelope    métadonnées (voir DatasetBuilder)
     * @param array<string, string> $linesByKey  lignes indexées par clé de jeton
     */
    public function __construct(public array $envelope, public array $linesByKey)
    {
    }

    /** @return list<string> */
    public function sortedLines(): array
    {
        return DatasetCodec::sorted($this->linesByKey);
    }

    public function root(): string
    {
        return DatasetCodec::root($this->sortedLines());
    }

    /** @return list<string> */
    public function bucketDigests(): array
    {
        return DatasetCodec::bucketDigests($this->linesByKey);
    }
}
