<?php

declare(strict_types=1);

namespace App\Onboarding\Domain;

use Symfony\Component\Uid\Uuid;

interface AdministratorValidationRepository
{
    public function add(AdministratorValidation $validation): void;

    public function find(Uuid $id): ?AdministratorValidation;

    /** @return list<AdministratorValidation> demandes en attente, de la plus ancienne à la plus récente */
    public function pending(): array;
}
