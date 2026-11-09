<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Event;

/**
 * Expiration d'un lien bénéficiaire (LOT 05.2, DM-114, DM-118) : FIN EFFECTIVE DE L'ÉVÉNEMENT + 30 jours.
 *
 * Fin effective = le PLUS TÔT de la fin planifiée et de la clôture, soit exactement la fin de la période opérationnelle que retient déjà
 * `GrantWindowCalculator` (intersection de la fin planifiée et de la clôture) : une clôture anticipée raccourcit, une clôture administrative
 * TARDIVE ne prolonge jamais, une fin planifiée explicitement repoussée (avant clôture, seule opération permise : `Event::reschedule`) est
 * celle qui compte. Sans fin planifiée, la clôture fait la fin ; sans fin planifiée ni clôture, il n'y a pas encore de fin effective (le lien
 * reste valable). Calculée à l'usage depuis l'événement ; la date enregistrée à l'émission n'est qu'un instantané informatif. La révocation
 * reste prioritaire sur toute expiration.
 */
final class AccessExpiry
{
    public const GRACE_DAYS = 30;

    private function __construct()
    {
    }

    public static function of(Event $event): ?\DateTimeImmutable
    {
        $planned = $event->endsAt();
        $closed = $event->closedAt();
        $end = null === $planned || null === $closed ? ($planned ?? $closed) : min($planned, $closed);

        return null === $end ? null : $end->modify('+'.self::GRACE_DAYS.' days');
    }
}
