<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Event\Application\Command\ValiditySlotInput;
use App\Event\Application\Command\ZoneValidityCommand;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Time\EventTimezone;
use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\SlotInspector;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\Domain\Validity\ValidityMode;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Identity\Domain\User;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Configuration de la validité d'une zone, partagée par EventZoneService et EventZoneBulkService : aucun moteur de dates
 * nouveau (ValiditySlot, SlotInspector et leurs règles viennent de l'étape 1).
 *
 * Trois temps, pour valider TOUT avant d'écrire : `parse` (forme + inspection dans le fuseau de l'événement),
 * `plan` (comparaison à l'existant, DM-088), `apply` (suppression logique des anciens créneaux, création des nouveaux,
 * audits). Le remplacement est global : modifier = supprimer logiquement puis créer (DM-075). À n'appeler que sous les
 * verrous de la participation et de la zone.
 */
final readonly class ZoneValidityConfigurator
{
    /** Plafond par défaut de créneaux (non supprimés) d'une configuration (DM-096). */
    public const DEFAULT_MAX_SLOTS = 500;

    public function __construct(
        private EventZoneValidityRepository $slots,
        private EventAuditRecorder $audit,
        private SlotInspector $inspector = new SlotInspector(),
        #[Autowire('%env(int:EVENT_MAX_SLOTS_PER_VALIDITY)%')]
        private int $maxSlots = self::DEFAULT_MAX_SLOTS,
    ) {
        if ($maxSlots < 1) {
            throw new \LogicException('Le plafond de créneaux par configuration doit être au moins 1.');
        }
    }

    /**
     * @throws DomainRuleViolation mode ou créneau invalide (InvalidValiditySlot : horaire inexistant, choix manquant, etc.)
     */
    public function parse(ZoneValidityCommand $command, EventTimezone $timezone): ParsedValidity
    {
        $mode = ValidityMode::tryFrom($command->mode);
        if (null === $mode || ValidityMode::Unconfigured === $mode) {
            throw new DomainRuleViolation('Le mode de validité doit être « toute la durée de l\'événement » ou « périodes spécifiques ».');
        }
        if (ValidityMode::EventDuration === $mode && [] !== $command->slots) {
            throw new DomainRuleViolation('Le mode « toute la durée de l\'événement » n\'accepte aucun créneau.');
        }
        if (ValidityMode::SpecificPeriods === $mode && [] === $command->slots) {
            throw new DomainRuleViolation('Le mode « périodes spécifiques » exige au moins un créneau.');
        }

        if (\count($command->slots) > $this->maxSlots) {
            throw new DomainRuleViolation(sprintf('Une configuration est limitée à %d créneaux.', $this->maxSlots));
        }

        $slots = [];
        foreach ($command->slots as $input) {
            $slot = self::toSlot($input);
            $this->inspector->assertValid($slot, $timezone);
            $slots[] = $slot;
        }

        return new ParsedValidity($mode, $slots);
    }

    /**
     * Refuse l'écriture si la configuration de la zone n'est plus celle que l'appelant a vue. À appeler SOUS les verrous de la
     * participation et de la zone, dans la transaction de l'écriture : lecture et écriture ne peuvent plus être séparées par une
     * modification concurrente (toute autre configuration de cette zone attend le verrou).
     *
     * @throws OperationConflict StaleState
     */
    public function assertRevision(EventZone $zone, ?string $expected): void
    {
        if (null === $expected) {
            return;
        }
        $current = ValidityRevision::of($zone->validityMode(), array_map(static fn (EventZoneValidity $v): string => $v->id()->toRfc4122(), $this->slots->findActiveByZone($zone->id())));
        if (!hash_equals($current, $expected)) {
            throw new OperationConflict(ConflictCause::StaleState);
        }
    }

    public function plan(EventZone $zone, ParsedValidity $target): ZoneValidityPlan
    {
        $current = $this->slots->findActiveByZone($zone->id());
        $existing = array_map(static fn (EventZoneValidity $v): string => self::signature($v->toSlot()), $current);
        $wanted = array_map(self::signature(...), $target->slots);
        sort($existing);
        sort($wanted);

        $modeChanged = $zone->validityMode() !== $target->mode;

        return new ZoneValidityPlan($zone, $target, $current, $modeChanged, $modeChanged || $existing !== $wanted);
    }

    /**
     * @return array{created: list<string>, deleted: list<string>}
     */
    public function apply(User $actor, ZoneValidityPlan $plan, \DateTimeImmutable $now, Uuid $batch): array
    {
        $zone = $plan->zone;
        $municipality = $zone->participation()->municipality()->id();
        $previousMode = $zone->validityMode();

        if ($plan->modeChanged) {
            $previous = EventAuditRecorder::zoneState($zone);
            $zone->changeValidityMode($plan->target->mode, $now);
            $this->audit->record(AuditAction::ZoneValidityModeChanged, AuditSubjectType::EventZone, $zone->id(), $municipality, $actor, $now, [...$previous, 'validityMode' => $previousMode->value], EventAuditRecorder::zoneState($zone), $batch);
        }

        $deleted = [];
        foreach ($plan->currentSlots as $old) {
            $before = self::slotState($old->toSlot(), $zone);
            $old->markDeleted($now);
            $this->audit->record(AuditAction::ZoneValiditySlotDeleted, AuditSubjectType::EventZoneValidity, $old->id(), $municipality, $actor, $now, $before, [...$before, 'deleted' => true], $batch);
            $deleted[] = $old->id()->toRfc4122();
        }

        $created = [];
        foreach ($plan->target->slots as $slot) {
            $new = EventZoneValidity::fromSlot($zone, $slot, $now);
            $this->slots->add($new);
            $this->audit->record(AuditAction::ZoneValiditySlotCreated, AuditSubjectType::EventZoneValidity, $new->id(), $municipality, $actor, $now, null, self::slotState($slot, $zone), $batch);
            $created[] = $new->id()->toRfc4122();
        }

        return ['created' => $created, 'deleted' => $deleted];
    }

    private static function toSlot(ValiditySlotInput $input): ValiditySlot
    {
        $kind = ValidityKind::tryFrom($input->kind) ?? throw new DomainRuleViolation('Type de créneau inconnu.');
        $choices = DstChoices::fromArray($input->dstChoices);
        $startDate = LocalDate::fromString($input->startDate);
        $startTime = LocalTime::fromString($input->startTime);
        $endTime = LocalTime::fromString($input->endTime);
        $endDate = null === $input->endDate ? null : LocalDate::fromString($input->endDate);

        return match ($kind) {
            ValidityKind::Punctual => ValiditySlot::punctual($startDate, $startTime, $endTime, $choices),
            ValidityKind::Continuous => ValiditySlot::continuous($startDate, $startTime, $endDate ?? throw new DomainRuleViolation('La date de fin est obligatoire pour un créneau continu.'), $endTime, $choices),
            ValidityKind::Recurring => ValiditySlot::recurring($startDate, $endDate ?? throw new DomainRuleViolation('La date de fin est obligatoire pour une récurrence.'), Weekdays::fromIsoDays($input->isoWeekdays), $startTime, $endTime, $choices),
        };
    }

    /** Forme canonique (champs locaux) : deux créneaux identiques ont la même signature, quel que soit l'ordre des choix. */
    private static function signature(ValiditySlot $slot): string
    {
        return json_encode(self::fields($slot), \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(ValiditySlot $slot): array
    {
        return [
            'kind' => $slot->kind->value,
            'startDate' => $slot->startDate->toString(),
            'startTime' => $slot->startTime->toString(),
            'endDate' => $slot->endDate?->toString(),
            'endTime' => $slot->endTime->toString(),
            'weekdays' => $slot->weekdays?->mask,
            'dstChoices' => $slot->dstChoices->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function slotState(ValiditySlot $slot, EventZone $zone): array
    {
        return ['zone' => $zone->id()->toRfc4122(), ...self::fields($slot)];
    }
}
