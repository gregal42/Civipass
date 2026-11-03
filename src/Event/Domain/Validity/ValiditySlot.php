<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Créneau de validité d'une zone, en horaires locaux du fuseau de l'événement (jamais en UTC).
 * Construit uniquement par les fabriques, qui garantissent les champs de chaque type (DM-014 à DM-018, DM-069).
 * Les règles dépendant du fuseau (heure inexistante, ambiguë) sont vérifiées par SlotInspector.
 */
final readonly class ValiditySlot
{
    /** Garde technique contre une expansion démesurée d'une récurrence (environ 10 ans). */
    public const MAX_RECURRENCE_DAYS = 3660;

    private function __construct(
        public ValidityKind $kind,
        public LocalDate $startDate,
        public LocalTime $startTime,
        public ?LocalDate $endDate,
        public LocalTime $endTime,
        public ?Weekdays $weekdays,
        public DstChoices $dstChoices,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    /** Début et fin complets ; la fin est strictement postérieure au début. */
    public static function continuous(LocalDate $startDate, LocalTime $startTime, LocalDate $endDate, LocalTime $endTime, DstChoices $dstChoices = new DstChoices()): self
    {
        $endsBefore = $endDate->isBefore($startDate)
            || ($endDate->equals($startDate) && !$startTime->isBefore($endTime));
        if ($endsBefore) {
            throw new DomainRuleViolation('La fin du créneau doit être strictement postérieure à son début.');
        }

        return new self(ValidityKind::Continuous, $startDate, $startTime, $endDate, $endTime, null, $dstChoices);
    }

    /** Une seule date ; début strictement antérieur à la fin, sans passage de minuit. */
    public static function punctual(LocalDate $date, LocalTime $startTime, LocalTime $endTime, DstChoices $dstChoices = new DstChoices()): self
    {
        if (!$startTime->isBefore($endTime)) {
            throw new DomainRuleViolation('Un créneau ponctuel tient sur une seule journée : l\'heure de début doit précéder l\'heure de fin.');
        }

        return new self(ValidityKind::Punctual, $date, $startTime, null, $endTime, null, $dstChoices);
    }

    /** Dates de début et de fin de période obligatoires ; une heure de fin antérieure à l'heure de début franchit minuit. */
    public static function recurring(LocalDate $startDate, LocalDate $endDate, Weekdays $weekdays, LocalTime $startTime, LocalTime $endTime, DstChoices $dstChoices = new DstChoices()): self
    {
        if ($endDate->isBefore($startDate)) {
            throw new DomainRuleViolation('La date de fin de la récurrence ne peut pas précéder sa date de début.');
        }
        if ($startTime->equals($endTime)) {
            throw new DomainRuleViolation('Les heures de début et de fin doivent être différentes.');
        }
        if ($startDate->addDays(self::MAX_RECURRENCE_DAYS)->isBefore($endDate)) {
            throw new DomainRuleViolation(sprintf('La période d\'une récurrence est limitée à %d jours.', self::MAX_RECURRENCE_DAYS));
        }

        return new self(ValidityKind::Recurring, $startDate, $startTime, $endDate, $endTime, $weekdays, $dstChoices);
    }

    public function withDstChoices(DstChoices $dstChoices): self
    {
        return new self($this->kind, $this->startDate, $this->startTime, $this->endDate, $this->endTime, $this->weekdays, $dstChoices, $this->deletedAt);
    }

    /** Suppression logique (DM-075) : sans retour, un créneau supprimé n'est jamais réactivé. */
    public function markDeleted(\DateTimeImmutable $at): self
    {
        if (null !== $this->deletedAt) {
            throw new DomainRuleViolation('Ce créneau est déjà supprimé.');
        }

        return new self($this->kind, $this->startDate, $this->startTime, $this->endDate, $this->endTime, $this->weekdays, $this->dstChoices, $at);
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}
