<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Référence publique d'un événement : `EVT-YYYY-NNNNNN` (DM-072). Compteur global permanent, jamais remis à zéro :
 * l'année est celle de la création (UTC) ; le numéro compte six chiffres au minimum et s'allonge au-delà de 999 999.
 */
final readonly class PublicReference
{
    private const PATTERN = '/^EVT-(\d{4})-(\d{6,})$/';

    private function __construct(public string $value)
    {
    }

    public static function fromCounter(int $number, \DateTimeImmutable $createdAt): self
    {
        if ($number < 1) {
            throw new DomainRuleViolation('Le numéro de référence doit être strictement positif.');
        }
        $year = $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y');

        return new self(sprintf('EVT-%s-%06d', $year, $number));
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new DomainRuleViolation(sprintf('Référence d\'événement invalide : « %s ».', $value));
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

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
