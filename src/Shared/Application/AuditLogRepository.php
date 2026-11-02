<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\AuditLog;
use Symfony\Component\Uid\Uuid;

interface AuditLogRepository
{
    /**
     * @return list<AuditLog>
     */
    public function findByMunicipality(Uuid $municipalityId, int $limit = 100): array;

    /**
     * @return list<AuditLog>
     */
    public function findAll(int $limit = 100): array;
}
