<?php

declare(strict_types=1);

namespace App\Event\UI\Twig;

use App\Event\Application\Read\SlotView;
use App\Event\Domain\Validity\ValidityKind;
use Twig\Attribute\AsTwigFunction;

/**
 * Présentation des créneaux de validité : phrases en heures LOCALES du fuseau de l'événement (les créneaux sont enregistrés ainsi,
 * jamais en UTC). Aucune règle : le texte ne fait que décrire ce que le moteur a enregistré.
 */
final class ValidityExtension
{
    private const DAYS = [1 => 'lundis', 2 => 'mardis', 3 => 'mercredis', 4 => 'jeudis', 5 => 'vendredis', 6 => 'samedis', 7 => 'dimanches'];

    /** Mode du composant `validity_slot` : continuous | single | recurring. */
    #[AsTwigFunction('cp_slot_mode')]
    public function mode(SlotView $slot): string
    {
        return match ($slot->kind) {
            ValidityKind::Continuous => 'continuous',
            ValidityKind::Punctual => 'single',
            ValidityKind::Recurring => 'recurring',
        };
    }

    #[AsTwigFunction('cp_slot_summary')]
    public function summary(SlotView $slot): string
    {
        return match ($slot->kind) {
            ValidityKind::Continuous => sprintf('Du %s à %s au %s à %s, sans interruption.', self::date($slot->startDate), $slot->startTime, self::date($slot->endDate ?? $slot->startDate), $slot->endTime),
            ValidityKind::Punctual => sprintf('Le %s, de %s à %s.', self::date($slot->startDate), $slot->startTime, $slot->endTime),
            ValidityKind::Recurring => sprintf(
                '%s, de %s à %s%s, du %s au %s.',
                7 === \count($slot->isoWeekdays) ? 'Tous les jours' : 'Les '.self::list(array_map(static fn (int $d): string => self::DAYS[$d], $slot->isoWeekdays)),
                $slot->startTime,
                $slot->endTime,
                $slot->endTime < $slot->startTime ? ' (le lendemain)' : '',
                self::date($slot->startDate),
                self::date($slot->endDate ?? $slot->startDate),
            ),
        };
    }

    /** Précisions : passage de minuit, choix d'occurrence horaire enregistrés (changement d'heure). */
    #[AsTwigFunction('cp_slot_note')]
    public function note(SlotView $slot): string
    {
        $notes = [];
        if (ValidityKind::Recurring === $slot->kind && $slot->endTime < $slot->startTime) {
            $notes[] = 'Chaque créneau passe minuit : il se termine le lendemain.';
        }
        foreach ($slot->dstChoices as $choice) {
            $time = 'start' === $choice['field'] ? $slot->startTime : $slot->endTime;
            $notes[] = sprintf('Changement d’heure : le %s, %s (%s) se produit deux fois ; la %s occurrence est retenue.', self::date($choice['date']), $time, 'start' === $choice['field'] ? 'début' : 'fin', 'first' === $choice['occurrence'] ? 'première' : 'deuxième');
        }

        return implode(' ', $notes);
    }

    private static function date(string $date): string
    {
        [$y, $m, $d] = explode('-', $date);

        return sprintf('%s/%s/%s', $d, $m, $y);
    }

    /**
     * @param list<string> $items
     */
    private static function list(array $items): string
    {
        return \count($items) < 2 ? implode('', $items) : implode(', ', \array_slice($items, 0, -1)).' et '.$items[\count($items) - 1];
    }
}
