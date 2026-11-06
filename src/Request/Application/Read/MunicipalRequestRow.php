<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/** Ligne de la liste : uniquement les zones de la commune consultante et le statut de SA portion. */
final readonly class MunicipalRequestRow
{
    /**
     * @param list<string> $zones libellés de zones de la commune courante
     */
    public function __construct(
        public string $requestId,
        public string $reference,
        public string $lastName,
        public string $firstName,
        public \DateTimeImmutable $submittedAt,
        public string $categoryLabel,
        public array $zones,
        public MunicipalRequestStatus $status,
    ) {
    }
}
