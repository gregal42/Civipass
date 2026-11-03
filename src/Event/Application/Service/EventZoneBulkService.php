<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Command\BulkZoneCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventPermission;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\BulkZoneOperation;
use App\Event\Application\Result\BulkZoneResult;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Application\Support\ZoneValidityConfigurator;
use App\Event\Application\Support\ZoneValidityPlan;
use App\Event\Domain\EventZone;
use App\Event\Domain\ZoneStatus;
use App\Identity\Domain\User;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Opérations groupées sur les zones d'UNE participation (sous-étape 5.5) : suspension et réactivation (DM-024),
 * application d'une même configuration de validité (DM-022, DM-023, DM-025). Aucune autre opération n'est prévue par les
 * décisions (ni suppression groupée, ni modification groupée de propriétés).
 *
 * Une opération = une transaction, un verrou partagé de l'événement, un verrou exclusif de la participation, puis des
 * zones par identifiant croissant (même ordre que les opérations individuelles). TOUT est validé avant la première
 * modification : une zone étrangère, supprimée, inexistante ou dans un état interdit refuse l'opération entière. Le
 * service n'ajoute aucun droit : la politique de zone est appliquée à CHAQUE zone, comme pour une opération individuelle.
 * Une configuration appliquée est copiée dans chaque zone (indépendantes ensuite, DM-026, DM-027). Audit : un par objet
 * modifié, un `batch_id` pour tout le lot, plus une ligne ZONE_BULK_OPERATION seulement si quelque chose a changé.
 */
final readonly class EventZoneBulkService
{
    /** Plafond par défaut de zones d'une opération groupée (DM-096). */
    public const DEFAULT_MAX_ZONES = 100;

    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private EventResourceResolver $resolver,
        private EventAccessPolicy $policy,
        private EventLocker $locker,
        private ZoneValidityConfigurator $validity,
        private EventAuditRecorder $audit,
        #[Autowire('%env(int:EVENT_MAX_ZONES_PER_BULK_OPERATION)%')]
        private int $maxZones = self::DEFAULT_MAX_ZONES,
    ) {
        if ($maxZones < 1) {
            throw new \LogicException('Le plafond de zones par opération groupée doit être au moins 1.');
        }
    }

    /**
     * @throws OperationNotAllowed droit insuffisant ; participation inaccessible ou non éditable ; zone étrangère, supprimée ou inconnue (refus uniforme)
     * @throws DomainRuleViolation sélection vide ou en double, transition interdite, configuration invalide, écrasement non confirmé
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function apply(User $actor, string $participationId, BulkZoneCommand $command): BulkZoneResult
    {
        if (\count($command->zoneIds) > $this->maxZones) {
            throw new DomainRuleViolation(sprintf('Une opération groupée est limitée à %d zones.', $this->maxZones));
        }
        $zoneIds = self::selection($command);
        $zonePermission = BulkZoneOperation::ApplyValidity === $command->operation ? EventPermission::ConfigureZoneValidity : EventPermission::ModifyZone;
        // Garde de participation : même matrice que la gestion des zones (administrateur communal, participation éditable).
        $known = $this->resolver->participation($actor, $participationId, EventPermission::CreateZone);

        return $this->transactions->run(function () use ($actor, $known, $command, $zoneIds, $zonePermission): BulkZoneResult {
            $event = $this->locker->sharedEvent($known->event()->id());
            $participation = $this->locker->participation($known->id(), $event);
            $this->policy->forParticipation($actor, EventPermission::CreateZone, $participation)->orThrow();
            $zones = $this->locker->zones($zoneIds, $participation);
            foreach ($zones as $zone) {
                $this->policy->forZone($actor, $zonePermission, $zone)->orThrow();
            }

            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            // Validation de TOUTES les zones avant la première écriture.
            $plans = [];
            if (BulkZoneOperation::ApplyValidity === $command->operation) {
                $parsed = $this->validity->parse($command->validity ?? throw new DomainRuleViolation('Une configuration de validité est obligatoire.'), $event->timezone());
                $overwrites = false;
                foreach ($zones as $zone) {
                    $plan = $this->validity->plan($zone, $parsed);
                    $overwrites = $overwrites || $plan->overwritesExistingConfiguration();
                    $plans[$zone->id()->toRfc4122()] = $plan;
                }
                if ($overwrites && !$command->confirmOverwrite) {
                    throw new DomainRuleViolation('Une confirmation est exigée avant de remplacer une configuration existante.');
                }
            } else {
                $required = BulkZoneOperation::Suspend === $command->operation ? ZoneStatus::Active : ZoneStatus::Suspended;
                foreach ($zones as $zone) {
                    if ($zone->status() !== $required) {
                        throw new DomainRuleViolation(BulkZoneOperation::Suspend === $command->operation ? 'Une zone sélectionnée est déjà suspendue.' : 'Une zone sélectionnée est déjà active.');
                    }
                }
            }

            $batch = Uuid::v7();
            $modified = [];
            foreach ($zones as $zone) {
                if ($this->applyTo($actor, $command->operation, $zone, $plans[$zone->id()->toRfc4122()] ?? null, $now, $batch)) {
                    $modified[] = $zone->id()->toRfc4122();
                }
            }
            if ([] === $modified) {
                return new BulkZoneResult($command->operation, $participation->id()->toRfc4122(), self::ids($zoneIds), [], false, null);
            }

            $this->audit->record(AuditAction::ZoneBulkOperation, AuditSubjectType::EventParticipation, $participation->id(), $participation->municipality()->id(), $actor, $now, null, [
                'operation' => $command->operation->value,
                'requested' => \count($zoneIds),
                'modified' => \count($modified),
                'zones' => $modified,
            ], $batch);

            return new BulkZoneResult($command->operation, $participation->id()->toRfc4122(), self::ids($zoneIds), $modified, true, $batch->toRfc4122());
        });
    }

    /** @return bool la zone a été modifiée (donc auditée) */
    private function applyTo(User $actor, BulkZoneOperation $operation, EventZone $zone, ?ZoneValidityPlan $plan, \DateTimeImmutable $now, Uuid $batch): bool
    {
        if (BulkZoneOperation::ApplyValidity === $operation) {
            if (null === $plan || !$plan->changed) {
                return false;
            }
            $this->validity->apply($actor, $plan, $now, $batch);

            return true;
        }

        $previous = EventAuditRecorder::zoneState($zone);
        if (BulkZoneOperation::Suspend === $operation) {
            $zone->suspend($now);
            $action = AuditAction::ZoneSuspended;
        } else {
            $zone->reactivate($now);
            $action = AuditAction::ZoneReactivated;
        }
        $this->audit->record($action, AuditSubjectType::EventZone, $zone->id(), $zone->participation()->municipality()->id(), $actor, $now, $previous, [...EventAuditRecorder::zoneState($zone), 'changed' => ['status']], $batch);

        return true;
    }

    /**
     * Sélection normalisée : non vide, identifiants valides et distincts (un identifiant invalide est refusé comme un
     * identifiant étranger, sans information).
     *
     * @return list<Uuid>
     */
    private static function selection(BulkZoneCommand $command): array
    {
        if (BulkZoneOperation::ApplyValidity !== $command->operation && null !== $command->validity) {
            throw new DomainRuleViolation('Aucune configuration de validité n\'est attendue pour cette opération.');
        }
        if ([] === $command->zoneIds) {
            throw new DomainRuleViolation('Sélectionnez au moins une zone.');
        }
        $seen = [];
        $ids = [];
        foreach ($command->zoneIds as $raw) {
            if (!Uuid::isValid($raw)) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $id = Uuid::fromString($raw);
            if (isset($seen[$id->toRfc4122()])) {
                throw new DomainRuleViolation('Une zone est sélectionnée plusieurs fois.');
            }
            $seen[$id->toRfc4122()] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);
    }
}
