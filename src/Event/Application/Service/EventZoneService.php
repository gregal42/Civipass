<?php

declare(strict_types=1);

namespace App\Event\Application\Service;

use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventPermission;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\ZoneResult;
use App\Event\Application\Result\ZoneValidityResult;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Application\Support\ZoneValidityConfigurator;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ZoneType;
use App\Identity\Domain\User;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Zones et validité d'une participation communale, opérations individuelles (sous-étape 5.4).
 *
 * Les invariants restent dans EventZone / EventZoneValidity et le moteur temporel ; ce service orchestre. Gabarit :
 * pré-autorisation hors transaction → verrou PARTAGÉ de l'événement en première instruction → verrou exclusif de la
 * participation → verrou exclusif de la zone → politique rappelée sous verrou → préconditions relues sous verrou →
 * validation avant toute écriture → mutation → audit → commit. Ordre unique : événement → participation → zone.
 * L'activation et la clôture (événement exclusif) ne coexistent donc jamais avec une opération de zone ; deux communes
 * ne se bloquent pas entre elles (participations distinctes). Administrateur communal seul (DM-009, DM-057) ; aucun
 * abonnement exigé pour gérer une participation existante.
 */
final readonly class EventZoneService
{
    public function __construct(
        private EventTransactions $transactions,
        private ClockInterface $clock,
        private EventResourceResolver $resolver,
        private EventAccessPolicy $policy,
        private EventLocker $locker,
        private EventZoneRepository $zones,
        private ZoneValidityConfigurator $validity,
        private EventAuditRecorder $audit,
    ) {
    }

    /**
     * @throws OperationNotAllowed droit insuffisant, participation inaccessible ou non éditable, événement clôturé
     * @throws DomainRuleViolation libellé invalide, « Commune entière » non confirmée ou déjà présente
     * @throws OperationConflict   verrou non obtenu à temps
     */
    public function create(User $actor, string $participationId, CreateZoneCommand $command): ZoneResult
    {
        $known = $this->resolver->participation($actor, $participationId, EventPermission::CreateZone);
        $type = ZoneType::tryFrom($command->type) ?? throw new DomainRuleViolation('Type de zone inconnu.');

        return $this->transactions->run(function () use ($actor, $known, $command, $type): ZoneResult {
            $event = $this->locker->sharedEvent($known->event()->id());
            $participation = $this->locker->participation($known->id(), $event);
            $this->policy->forParticipation($actor, EventPermission::CreateZone, $participation)->orThrow();
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            if (ZoneType::WholeMunicipality === $type) {
                foreach ($this->zones->findActiveByParticipation($participation->id()) as $existing) {
                    if (ZoneType::WholeMunicipality === $existing->type()) {
                        throw new DomainRuleViolation('Une zone « Commune entière » existe déjà pour cette participation.');
                    }
                }
            }
            $zone = EventZone::create($participation, $command->label, $type, $command->description, $actor, $now, $command->wholeMunicipalityConfirmed);
            $this->zones->add($zone);

            $this->audit->record(AuditAction::ZoneCreated, AuditSubjectType::EventZone, $zone->id(), $participation->municipality()->id(), $actor, $now, null, EventAuditRecorder::zoneState($zone, 'MANUAL'));

            return self::result($zone, true);
        });
    }

    /**
     * Libellé et description uniquement (type immuable, DM-080) ; ne touche jamais aux créneaux. Aucun changement réel :
     * ni écriture ni audit.
     */
    public function update(User $actor, string $zoneId, UpdateZoneCommand $command): ZoneResult
    {
        return $this->mutate($actor, $zoneId, EventPermission::ModifyZone, AuditAction::ZoneUpdated, static function (EventZone $zone, \DateTimeImmutable $now) use ($command): array {
            $label = null === $command->label ? $zone->label() : $command->label;
            $description = null === $command->description ? $zone->description() : $command->description;
            $before = [$zone->label(), $zone->description()];
            $changes = [];
            $probe = clone $zone;
            $probe->update($label, $description, $now); // valide (libellé) sans toucher à la zone gérée
            if ($before[0] !== $probe->label()) {
                $changes[] = 'label';
            }
            if ($before[1] !== $probe->description()) {
                $changes[] = 'description';
            }
            if ([] !== $changes) {
                $zone->update($label, $description, $now);
            }

            return $changes;
        });
    }

    /** Idempotent : une zone déjà confirmée n'est pas modifiée et n'est pas auditée. Aucune précondition temporelle (DM-051, DM-059). */
    public function confirm(User $actor, string $zoneId): ZoneResult
    {
        return $this->mutate($actor, $zoneId, EventPermission::ModifyZone, AuditAction::ZoneConfirmed, static function (EventZone $zone, \DateTimeImmutable $now): array {
            if ($zone->isConfirmed()) {
                return [];
            }
            $zone->confirm($now);

            return ['confirmed'];
        });
    }

    public function suspend(User $actor, string $zoneId): ZoneResult
    {
        return $this->mutate($actor, $zoneId, EventPermission::ModifyZone, AuditAction::ZoneSuspended, static function (EventZone $zone, \DateTimeImmutable $now): array {
            $zone->suspend($now);

            return ['status'];
        });
    }

    public function reactivate(User $actor, string $zoneId): ZoneResult
    {
        return $this->mutate($actor, $zoneId, EventPermission::ModifyZone, AuditAction::ZoneReactivated, static function (EventZone $zone, \DateTimeImmutable $now): array {
            $zone->reactivate($now);

            return ['status'];
        });
    }

    /** Suppression logique, définitive (DM-052) : créneaux et historique conservés. */
    public function delete(User $actor, string $zoneId): ZoneResult
    {
        return $this->mutate($actor, $zoneId, EventPermission::DeleteZone, AuditAction::ZoneDeleted, static function (EventZone $zone, \DateTimeImmutable $now): array {
            $zone->markDeleted($now);

            return ['deleted'];
        });
    }

    /**
     * Remplace la configuration de validité (DM-075). Fuseau de l'événement relu SOUS verrou ; tout est validé
     * (mode, créneaux, heures inexistantes ou ambiguës, limite de 3 660 jours) avant la première écriture. Remplacement
     * identique : ni écriture ni audit (DM-088). Un seul `batch_id` pour les audits d'un même remplacement. Si la commande porte une
     * empreinte attendue, elle est comparée à la configuration lue sous les verrous de la zone : périmée, rien n'est écrit
     * (OperationConflict, cause StaleState).
     *
     * @throws DomainRuleViolation configuration invalide (InvalidValiditySlot pour un créneau)
     * @throws OperationConflict   empreinte attendue périmée, verrou non obtenu à temps ou interblocage
     */
    public function configureValidity(User $actor, string $zoneId, ZoneValidityCommand $command): ZoneValidityResult
    {
        $known = $this->resolver->zone($actor, $zoneId, EventPermission::ConfigureZoneValidity);

        return $this->transactions->run(function () use ($actor, $known, $command): ZoneValidityResult {
            $event = $this->locker->sharedEvent($known->participation()->event()->id());
            $participation = $this->locker->participation($known->participation()->id(), $event);
            $zone = $this->locker->zone($known->id(), $participation);
            $this->policy->forZone($actor, EventPermission::ConfigureZoneValidity, $zone)->orThrow();
            $this->validity->assertRevision($zone, $command->expectedRevision); // sous les verrous, dans la transaction de l'écriture
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            $plan = $this->validity->plan($zone, $this->validity->parse($command, $event->timezone()));
            if (!$plan->changed) {
                return new ZoneValidityResult($zone->id()->toRfc4122(), $zone->validityMode(), [], [], false);
            }
            $changes = $this->validity->apply($actor, $plan, $now, Uuid::v7());

            return new ZoneValidityResult($zone->id()->toRfc4122(), $zone->validityMode(), $changes['created'], $changes['deleted'], true);
        });
    }

    /**
     * @param \Closure(EventZone, \DateTimeImmutable): list<string> $mutate applique la mutation et renvoie les champs modifiés (liste vide : aucun changement, aucun audit)
     */
    private function mutate(User $actor, string $zoneId, EventPermission $permission, AuditAction $action, \Closure $mutate): ZoneResult
    {
        $known = $this->resolver->zone($actor, $zoneId, $permission);

        return $this->transactions->run(function () use ($actor, $known, $permission, $action, $mutate): ZoneResult {
            $event = $this->locker->sharedEvent($known->participation()->event()->id());
            $participation = $this->locker->participation($known->participation()->id(), $event);
            $zone = $this->locker->zone($known->id(), $participation);
            $this->policy->forZone($actor, $permission, $zone)->orThrow();
            $now = $this->clock->now(); // après l'acquisition des verrous (DT-038)

            $previous = EventAuditRecorder::zoneState($zone);
            $changed = $mutate($zone, $now);
            if ([] === $changed) {
                return self::result($zone, false);
            }
            $this->audit->record($action, AuditSubjectType::EventZone, $zone->id(), $participation->municipality()->id(), $actor, $now, $previous, [...EventAuditRecorder::zoneState($zone), 'changed' => $changed]);

            return self::result($zone, true);
        });
    }

    private static function result(EventZone $zone, bool $changed): ZoneResult
    {
        return new ZoneResult(
            $zone->id()->toRfc4122(),
            $zone->participation()->id()->toRfc4122(),
            $zone->participation()->municipality()->id()->toRfc4122(),
            $zone->status(),
            $zone->isConfirmed(),
            $zone->isDeleted(),
            $zone->validityMode(),
            $changed,
        );
    }
}
