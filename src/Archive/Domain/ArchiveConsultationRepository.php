<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use Symfony\Component\Uid\Uuid;

interface ArchiveConsultationRepository
{
    public function add(ArchiveConsultation $consultation): void;

    public function find(Uuid $id): ?ArchiveConsultation;

    /** @return list<ArchiveConsultation> consultations des dossiers de CETTE commune, plus récentes d'abord */
    public function forMunicipality(Uuid $municipalityId, int $limit): array;
}
