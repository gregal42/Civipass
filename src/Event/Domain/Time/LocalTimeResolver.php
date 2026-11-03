<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

/**
 * Convertit une heure locale en instant UTC sans jamais s'en remettre au comportement implicite de PHP
 * (qui décale silencieusement une heure inexistante et choisit la première occurrence d'une heure répétée).
 *
 * Un horaire local admet 0 (inexistant), 1 (normal) ou 2 (ambigu) instants : ce sont les décalages en vigueur
 * autour de la date, retenus seulement s'ils sont cohérents avec l'instant obtenu.
 */
final readonly class LocalTimeResolver
{
    /**
     * @return list<\DateTimeImmutable> instants UTC possibles, du plus tôt au plus tard
     */
    public function candidates(LocalDate $date, LocalTime $time, EventTimezone $timezone): array
    {
        $zone = $timezone->toDateTimeZone();
        $wall = $date->toWallClock($time)->getTimestamp();

        $offsets = [];
        foreach ($zone->getTransitions($wall - 2 * 86400, $wall + 2 * 86400) as $transition) {
            $offsets[$transition['offset']] = true;
        }

        $instants = [];
        foreach (array_keys($offsets) as $offset) {
            $instant = $wall - $offset;
            if ($zone->getOffset(new \DateTimeImmutable('@'.$instant)) === $offset) {
                $instants[$instant] = true;
            }
        }
        ksort($instants);

        return array_map(
            static fn (int $timestamp): \DateTimeImmutable => new \DateTimeImmutable('@'.$timestamp),
            array_keys($instants),
        );
    }

    /**
     * @throws NonexistentLocalTime
     * @throws AmbiguousLocalTime   si l'horaire est répété et qu'aucune occurrence n'est choisie
     */
    public function resolve(LocalDate $date, LocalTime $time, EventTimezone $timezone, ?Occurrence $choice = null): \DateTimeImmutable
    {
        $candidates = $this->candidates($date, $time, $timezone);

        return match (\count($candidates)) {
            0 => throw new NonexistentLocalTime($date, $time, $timezone),
            1 => $candidates[0],
            default => match ($choice) {
                null => throw new AmbiguousLocalTime($date, $time, $timezone),
                Occurrence::First => $candidates[0],
                Occurrence::Second => $candidates[1],
            },
        };
    }
}
