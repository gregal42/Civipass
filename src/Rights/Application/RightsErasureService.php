<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeOutcome;
use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RetentionSettings;
use App\Retention\Application\SharedPurgeService;
use App\Retention\Domain\PurgeRecord;
use App\Rights\Domain\RightsRefusalReason;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Exécution d'un effacement anticipé ACCORDÉ (LOT 05.6.6, arbitrage D1). DÉSACTIVÉE par défaut : exige `RIGHTS_ERASURE_EXECUTION_ENABLED=1` ET `RETENTION_PURGE_ENABLED=1`, c'est-à-dire
 * la validation préalable de la politique juridique. Ne contourne JAMAIS : un gel actif, l'événement non clos, une autre demande de droits ouverte (contrôles du service de purge,
 * relus sous verrou), ni la règle de partage (données partagées supprimées seulement avec le dernier dossier). Idempotente et reprenable : les liens de la demande sont supprimés
 * avec chaque dossier ; la demande est marquée exécutée quand plus aucun dossier ne reste.
 */
final class RightsErasureService
{
    public function __construct(
        private readonly ArchiveAccessPolicy $policy,
        private readonly RightsRequestStore $store,
        private readonly DossierPurgeService $dossiers,
        private readonly SharedPurgeService $shared,
        private readonly PurgeRegisterFile $register,
        private readonly RightsSettings $settings,
        private readonly RetentionSettings $retention,
        private readonly Connection $connection,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{purged: int, remaining: int}
     *
     * @throws OperationNotAllowed                          refus uniforme
     * @throws ErasureExecutionDisabled|DomainRuleViolation
     */
    public function execute(User $actor, string $municipalityId, string $requestId): array
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $row = $this->store->find($requestId, $municipality) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        if ('ERASURE' !== $row['kind']) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if ('DECIDED' !== $row['status'] || 'GRANTED' !== $row['decision'] || null === $row['conservation_checked_at']) {
            throw new DomainRuleViolation('Seule une demande d’effacement accordée, avec vérification des obligations de conservation, peut être exécutée.');
        }
        if (!$this->settings->erasureExecutionEnabled() || !$this->retention->purgeEnabled()) {
            throw ErasureExecutionDisabled::create();
        }
        $id = Uuid::fromBinary((string) $row['id']);
        $purged = 0;
        foreach ($this->store->dossierIds($id) as $dossierId) {
            $outcome = $this->dossiers->purge($dossierId, true, $actor, $id, early: true);
            if (PurgeOutcome::NOT_ELIGIBLE === $outcome->status) {
                throw new DomainRuleViolation(sprintf('Le dossier ne peut pas être effacé : %s. Les dossiers déjà effacés le restent ; la demande reste en cours.', $this->label($outcome->reason)));
            }
            if (PurgeOutcome::PURGED === $outcome->status) {
                ++$purged;
                $this->append($outcome->record);
                if ($outcome->sharedDue && null !== $outcome->requestId) {
                    $this->append($this->shared->purge($outcome->requestId, true, $actor, $id)->record);
                }
            }
        }
        $remaining = \count($this->store->dossierIds($id));
        if (0 === $remaining) {
            $this->transaction->run(function () use ($actor, $id, $municipality, $purged): void {
                $now = RightsRequestStore::utc($this->clock->now());
                $done = $this->connection->executeStatement('UPDATE rights_request SET executed_at = :now WHERE id = :id AND executed_at IS NULL', ['now' => $now->format('Y-m-d H:i:s'), 'id' => $id->toBinary()]);
                if (1 === $done) {
                    $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::RightsErasureExecuted, AuditSubjectType::RightsRequest, $id, $municipality, AuditActor::user($actor->id()), null, ['purged' => $purged]));
                }
            });
        }

        return ['purged' => $purged, 'remaining' => $remaining];
    }

    private function label(string $reason): string
    {
        return match ($reason) {
            DossierPurgeService::LEGAL_HOLD => RightsRefusalReason::LegalHold->label(),
            DossierPurgeService::NOT_CLOSED => RightsRefusalReason::EventOngoing->label(),
            DossierPurgeService::RIGHTS_REQUEST_OPEN => 'une autre demande de droits est ouverte sur ce dossier',
            default => $reason,
        };
    }

    /** Copie hors base écrite APRÈS la validation SQL ; un échec est réparable par `app:retention:register-check --repair`. */
    private function append(?PurgeRecord $record): void
    {
        if (null === $record) {
            return;
        }
        try {
            $this->register->append($record);
        } catch (\Throwable) {
        }
    }
}
