<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Suivi des notifications d'un laissez-passer pour l'écran agent. `manualDeliveryPossible` : aucun message de mise à disposition n'a atteint le bénéficiaire. */
final readonly class NotificationOverview
{
    /**
     * @param list<NotificationLine> $lines du plus récent au plus ancien
     */
    public function __construct(public array $lines, public bool $manualDeliveryPossible)
    {
    }

    public static function empty(): self
    {
        return new self([], true);
    }
}
