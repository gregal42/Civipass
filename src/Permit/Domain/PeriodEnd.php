<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Request\Domain\DurationMode;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Fin demandée pour une période (LOT 05.5.2) : durée fixe AJOUTÉE (24, 48 ou 72 h en temps écoulé, comme DM-109), date et heure LOCALES de l'événement
 * (avec le choix de l'occurrence d'une heure répétée par un changement d'heure), ou « jusqu'à la clôture ». Valeur pure : l'instant UTC est calculé par le
 * planificateur, avec le fuseau de l'événement.
 */
final readonly class PeriodEnd
{
    private function __construct(
        public DurationMode $mode,
        public ?LocalDate $date = null,
        public ?LocalTime $time = null,
        public ?Occurrence $occurrence = null,
    ) {
    }

    public static function fixedHours(DurationMode $mode): self
    {
        if (null === $mode->fixedHours()) {
            throw new DomainRuleViolation('Cette durée n\'est pas une durée fixe.');
        }

        return new self($mode);
    }

    public static function custom(string $date, string $time, ?Occurrence $occurrence = null): self
    {
        return new self(DurationMode::Custom, LocalDate::fromString($date), LocalTime::fromString($time), $occurrence);
    }

    public static function untilClosure(): self
    {
        return new self(DurationMode::UntilClosure);
    }

    /**
     * Depuis les valeurs d'un formulaire.
     *
     * @throws DomainRuleViolation mode inconnu, date ou heure absente ou invalide, occurrence inconnue
     */
    public static function fromInput(string $mode, ?string $date = null, ?string $time = null, ?string $occurrence = null): self
    {
        $parsed = DurationMode::tryFrom($mode) ?? throw new DomainRuleViolation('Choisissez une durée valide.');

        return match (true) {
            DurationMode::UntilClosure === $parsed => self::untilClosure(),
            DurationMode::Custom === $parsed => self::custom(
                null === $date || '' === trim($date) ? throw new DomainRuleViolation('Indiquez la date de fin.') : trim($date),
                null === $time || '' === trim($time) ? throw new DomainRuleViolation('Indiquez l\'heure de fin.') : trim($time),
                null === $occurrence || '' === $occurrence ? null : (Occurrence::tryFrom($occurrence) ?? throw new DomainRuleViolation('Choix d\'occurrence invalide.')),
            ),
            default => self::fixedHours($parsed),
        };
    }
}
