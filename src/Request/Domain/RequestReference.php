<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Time\EventTimezone;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Référence d'une demande : `DEM-AAAA-NNNN` (DM-105, S1). Compteur distinct par année civile, repartant à 1 chaque
 * année ; quatre chiffres minimum, extensibles au-delà de 9 999. L'année est l'année civile de l'instant du dépôt dans
 * le fuseau de l'événement (et non en UTC, contrairement à `EVT-`). `LP-` est réservé aux laissez-passer délivrés.
 */
final readonly class RequestReference
{
    private const PATTERN = '/^DEM-(\d{4})-(\d{4,})$/';

    private function __construct(public string $value)
    {
    }

    public static function yearOf(\DateTimeImmutable $at, EventTimezone $timezone): int
    {
        return (int) $at->setTimezone($timezone->toDateTimeZone())->format('Y');
    }

    public static function of(int $year, int $number): self
    {
        if ($year < 1000 || $year > 9999) {
            throw new DomainRuleViolation('Année de référence invalide.');
        }
        if ($number < 1) {
            throw new DomainRuleViolation('Le numéro de référence doit être strictement positif.');
        }

        return new self(sprintf('DEM-%04d-%04d', $year, $number));
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new DomainRuleViolation(sprintf('Référence de demande invalide : « %s ».', $value));
        }

        return new self($value);
    }

    public function year(): int
    {
        preg_match(self::PATTERN, $this->value, $m);

        return (int) $m[1];
    }

    public function number(): int
    {
        preg_match(self::PATTERN, $this->value, $m);

        return (int) $m[2];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
