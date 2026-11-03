<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Jours de la semaine d'une récurrence, en masque de bits : lundi = 1 … dimanche = 64 (valeur 1 à 127).
 */
final readonly class Weekdays
{
    private function __construct(public int $mask)
    {
    }

    public static function fromMask(int $mask): self
    {
        if ($mask < 1 || $mask > 127) {
            throw new DomainRuleViolation('Sélectionnez au moins un jour de la semaine.');
        }

        return new self($mask);
    }

    /**
     * @param list<int> $isoDays 1 = lundi … 7 = dimanche
     */
    public static function fromIsoDays(array $isoDays): self
    {
        $mask = 0;
        foreach ($isoDays as $day) {
            if ($day < 1 || $day > 7) {
                throw new DomainRuleViolation('Jour de la semaine invalide.');
            }
            $mask |= 1 << ($day - 1);
        }

        return self::fromMask($mask);
    }

    public function includes(int $isoDay): bool
    {
        return 0 !== ($this->mask & (1 << ($isoDay - 1)));
    }
}
