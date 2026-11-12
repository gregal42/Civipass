<?php

declare(strict_types=1);

namespace App\Archive\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/** Ligne de liste : AUCUNE donnée personnelle (ni nom, ni plaque, ni pièce). */
final readonly class ArchiveDossierLine
{
    public function __construct(
        public string $dossierId,
        public string $reference,
        public string $categoryLabel,
        public MunicipalRequestStatus $status,
        public ?\DateTimeImmutable $decidedAt,
        public bool $archiveOpen,
        public bool $holdActive,
        public bool $holdOverdue,
    ) {
    }
}
