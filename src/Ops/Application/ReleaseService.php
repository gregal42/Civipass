<?php

declare(strict_types=1);

namespace App\Ops\Application;

use App\Identity\Domain\User;
use App\Retention\Application\ReplayRefused;
use App\Retention\Application\RestoreChecker;
use App\Retention\Application\RetentionReplayService;
use App\Retention\Domain\RestoreItem;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Remise en service après restauration (LOT 05.6.7) : lève la maintenance SEULEMENT si le contrôle de restauration ne laisse aucune anomalie bloquante. Les anomalies de perte possible
 * (données postérieures à la sauvegarde, événements ou communes manquants) ne sont pas réparables : elles doivent être RECONNUES par une référence écrite, tracée. Tout le reste
 * (dossiers réintroduits, preuve rompue, fichiers manquants, compteurs incohérents…) bloque. Aucun droit ni aucune autorisation n'est modifié ici.
 */
final class ReleaseService
{
    private const ACKNOWLEDGEABLE = [RestoreItem::POSSIBLE_DATA_LOSS, RestoreItem::EVENT_MISSING, RestoreItem::MUNICIPALITY_MISSING];

    public function __construct(
        private readonly RestoreChecker $checker,
        private readonly MaintenanceMode $maintenance,
        private readonly LossFindings $findings,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     * @throws ReleaseRefused
     * @throws ReplayRefused       référence de reconnaissance invalide
     */
    public function release(User $operator, ?string $lossAcknowledgement, ?string $externalAnchors = null): int
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $report = $this->checker->check($externalAnchors);
        $this->findings->record($report->items); // un constat de perte reste consigné même si un rejeu l'a fait disparaître du contrôle
        $blocking = [...$report->blocking(), ...array_filter($this->findings->all(), static fn (RestoreItem $f): bool => [] === array_filter($report->blocking(), static fn (RestoreItem $b): bool => $b->code === $f->code && $b->detail === $f->detail && $b->eventReference === $f->eventReference && $b->municipalityId === $f->municipalityId))];
        $unacknowledged = array_values(array_filter($blocking, static fn (RestoreItem $i): bool => !\in_array($i->code, self::ACKNOWLEDGEABLE, true)));
        $acknowledgeable = array_values(array_filter($blocking, static fn (RestoreItem $i): bool => \in_array($i->code, self::ACKNOWLEDGEABLE, true)));
        if ([] !== $acknowledgeable) {
            if (null === $lossAcknowledgement || 1 !== preg_match(RetentionReplayService::APPROVAL_PATTERN, $lossAcknowledgement)) {
                $unacknowledged = [...$unacknowledged, ...$acknowledgeable];
            }
        }
        if ([] !== $unacknowledged) {
            throw new ReleaseRefused($unacknowledged);
        }
        $this->transaction->run(function () use ($operator, $lossAcknowledgement, $report, $acknowledgeable): void {
            $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::OpsMaintenanceReleased, AuditSubjectType::Retention, Uuid::v7(), null, AuditActor::user($operator->id()), null, [
                'report' => $report->hash,
                'acknowledged' => \count($acknowledgeable),
                'acknowledgement' => $lossAcknowledgement,
            ]));
        });
        $this->findings->clear();
        $this->maintenance->release();

        return \count($acknowledgeable);
    }
}
