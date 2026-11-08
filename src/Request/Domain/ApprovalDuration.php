<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Time\Occurrence;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Durée choisie par l'agent à l'approbation (DM-109) : une durée fixe, « jusqu'à clôture », ou une fin personnalisée saisie
 * en date et heure LOCALES de l'événement (jamais un instant brut : la conversion en UTC revient au calculateur, qui
 * refuse une heure inexistante ou ambiguë sans choix explicite). Aucun début n'est programmable : il commence à la décision.
 */
final readonly class ApprovalDuration
{
    private function __construct(
        public DurationMode $mode,
        public ?LocalDate $endDate = null,
        public ?LocalTime $endTime = null,
        public ?Occurrence $occurrence = null,
    ) {
    }

    public static function fixed(DurationMode $mode): self
    {
        if (null === $mode->fixedHours()) {
            throw new DomainRuleViolation('Cette durée n\'est pas une durée fixe.');
        }

        return new self($mode);
    }

    public static function untilClosure(): self
    {
        return new self(DurationMode::UntilClosure);
    }

    /**
     * @param string $endDate AAAA-MM-JJ, date locale de l'événement
     * @param string $endTime HH:MM, heure locale de l'événement
     */
    public static function custom(string $endDate, string $endTime, ?Occurrence $occurrence = null): self
    {
        return new self(DurationMode::Custom, LocalDate::fromString($endDate), LocalTime::fromString($endTime), $occurrence);
    }

    /** Depuis la valeur d'un formulaire ou d'un appel : le mode « CUSTOM » exige la fin, les autres l'interdisent. */
    public static function fromInput(string $mode, ?string $endDate = null, ?string $endTime = null, ?Occurrence $occurrence = null): self
    {
        $parsed = DurationMode::tryFrom($mode) ?? throw new DomainRuleViolation('Durée d\'autorisation inconnue.');
        if (DurationMode::Custom === $parsed) {
            if (null === $endDate || null === $endTime || '' === trim($endDate) || '' === trim($endTime)) {
                throw new DomainRuleViolation('Indiquez la date et l\'heure de fin de l\'autorisation.');
            }

            return self::custom(trim($endDate), trim($endTime), $occurrence);
        }
        if (null !== $endDate && '' !== trim($endDate) || null !== $endTime && '' !== trim($endTime)) {
            throw new DomainRuleViolation('Une date de fin ne se renseigne que pour une durée personnalisée.');
        }

        return DurationMode::UntilClosure === $parsed ? self::untilClosure() : self::fixed($parsed);
    }
}
