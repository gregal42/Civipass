<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Application\AuditLogRepository;
use App\Shared\Domain\AuditLog;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Lecture de l'audit filtrée par périmètre : un administrateur ne voit que sa commune,
 * jamais les entrées globales (municipalityId NULL, ex. changement de superadmin).
 */
final readonly class AuditLogReader
{
    public function __construct(
        private AuditLogRepository $logs,
        private MembershipAccessPolicy $policy,
    ) {
    }

    /**
     * @return list<AuditLog>
     */
    public function forMunicipality(User $viewer, Municipality $municipality, int $limit = 100): array
    {
        if (!$this->policy->canReadAudit($viewer, $municipality)) {
            throw new OperationNotAllowed('Droit insuffisant pour consulter l\'audit de cette commune.');
        }

        return $this->logs->findByMunicipality($municipality->id(), $limit);
    }

    /**
     * @return list<AuditLog>
     */
    public function all(User $viewer, int $limit = 100): array
    {
        if (!$this->policy->isActiveSuperAdmin($viewer)) {
            throw new OperationNotAllowed('Action réservée à un superadmin actif.');
        }

        return $this->logs->findAll($limit);
    }
}
