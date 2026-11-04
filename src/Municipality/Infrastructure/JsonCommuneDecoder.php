<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Application\NationalCommuneSourceUnavailable;
use App\Municipality\Domain\NationalCommuneRecord;

/**
 * Décode le format de l'API Géo : tableau JSON de { nom, code, codeDepartement, codeRegion?, codesPostaux?, population? }.
 * Une entrée mal formée est écartée ici ; une réponse qui n'est pas un tableau JSON est inexploitable.
 */
final class JsonCommuneDecoder
{
    /**
     * @return list<NationalCommuneRecord>
     */
    public static function decode(string $json): array
    {
        try {
            $rows = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new NationalCommuneSourceUnavailable('La réponse de la source des communes n\'est pas un JSON valide.');
        }
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new NationalCommuneSourceUnavailable('La réponse de la source des communes n\'a pas la forme attendue.');
        }

        $records = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['code'] ?? null) || !\is_string($row['nom'] ?? null) || !\is_string($row['codeDepartement'] ?? null)) {
                continue;
            }
            $postal = array_values(array_filter(\is_array($row['codesPostaux'] ?? null) ? $row['codesPostaux'] : [], static fn (mixed $c): bool => \is_string($c)));
            $records[] = new NationalCommuneRecord(
                $row['code'],
                $row['nom'],
                $row['codeDepartement'],
                \is_string($row['codeRegion'] ?? null) ? $row['codeRegion'] : null,
                $postal,
                \is_int($row['population'] ?? null) ? $row['population'] : null,
            );
        }

        return $records;
    }
}
