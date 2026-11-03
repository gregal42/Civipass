<?php

declare(strict_types=1);

namespace App\Municipality\Domain;

use Symfony\Component\Uid\Uuid;

interface MunicipalityRepository
{
    public function add(Municipality $municipality): void;

    public function find(Uuid $id): ?Municipality;

    public function findByInseeCode(InseeCode $code): ?Municipality;

    /**
     * Verrou d'écriture sur la commune (relecture sous verrou) : sérialise les modifications concurrentes de ses memberships.
     * À appeler dans une transaction.
     */
    public function lock(Uuid $id): ?Municipality;
}
