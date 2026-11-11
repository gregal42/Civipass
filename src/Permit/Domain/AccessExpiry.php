<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Event;

/**
 * Expiration d'un lien bénéficiaire (LOT 05.2, DM-114 ; amendé au LOT 05.6.2, DM-120) :
 *  - événement NON clos : fin planifiée + 30 jours (sans fin planifiée, pas encore d'expiration : le lien reste valable) ;
 *  - événement CLOS : le lien est fermé À LA CLÔTURE (plus de délai de grâce) ; si la fin planifiée + 30 jours est déjà dépassée, c'est elle qui a fermé le lien.
 * Calculée à l'usage depuis l'événement ; la date enregistrée à l'émission n'est qu'un instantané informatif. La révocation reste prioritaire sur
 * toute expiration. La fermeture effective est aussi tenue par la garde de clôture (lecture fraîche) dans `BeneficiaryPassReader`.
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
        $graceEnd = null === $planned ? null : $planned->modify('+'.self::GRACE_DAYS.' days');
        $closed = $event->closedAt();
        if (null === $closed) {
            return $graceEnd;
        }

        return null === $graceEnd ? $closed : min($closed, $graceEnd);
    }
}
