<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Seuils de dormance (LOT 05.6.5) : HYPOTHÈSES paramétrables, à confirmer avant production ; jamais des constantes dans la logique. */
final readonly class DormancySettings
{
    /** Durées de report autorisées, en jours. */
    public const SNOOZE_DAYS = [30, 60, 90];

    public function __construct(
        #[Autowire('%env(int:EVENT_DORMANCY_DAYS)%')] private int $days,
        #[Autowire('%env(int:EVENT_DORMANCY_REMINDER_DAYS)%')] private int $reminderDays,
        #[Autowire('%env(int:EVENT_DORMANCY_LEASE_MINUTES)%')] private int $leaseMinutes,
    ) {
    }

    /** Inactivité à partir de laquelle un événement sans fin est signalé. */
    public function days(): int
    {
        return max(1, $this->days);
    }

    /** Délai minimal entre deux alertes confirmées pour un même événement. */
    public function reminderDays(): int
    {
        return max(1, $this->reminderDays);
    }

    /** Durée pendant laquelle une prise d'alerte NON confirmée bloque les autres exécutions (reprise après plantage). */
    public function leaseMinutes(): int
    {
        return max(1, $this->leaseMinutes);
    }
}
