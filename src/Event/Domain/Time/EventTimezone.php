<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Fuseau IANA d'un événement (DM-062) : un seul par événement, limité à une liste blanche
 * couvrant l'Hexagone et l'outre-mer. Seuls Europe/Paris et America/Miquelon ont une heure d'été.
 */
final readonly class EventTimezone
{
    /** @var list<string> */
    public const SUPPORTED = [
        'Europe/Paris',
        'America/Guadeloupe',
        'America/Martinique',
        'America/Cayenne',
        'Indian/Reunion',
        'Indian/Mayotte',
        'America/Miquelon',
        'America/St_Barthelemy',
        'America/Marigot',
        'Pacific/Wallis',
        'Pacific/Tahiti',
        'Pacific/Marquesas',
        'Pacific/Gambier',
        'Pacific/Noumea',
    ];

    private function __construct(private string $identifier)
    {
    }

    public static function fromIdentifier(string $identifier): self
    {
        if (!\in_array($identifier, self::SUPPORTED, true)) {
            throw new DomainRuleViolation(sprintf('Fuseau horaire « %s » non pris en charge.', $identifier));
        }

        return new self($identifier);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function toDateTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->identifier);
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }

    public function __toString(): string
    {
        return $this->identifier;
    }
}
