<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\ClosureStatistics;
use Symfony\Component\Uid\Uuid;

/**
 * Port de la clôture vers les dossiers et les autorisations (LOT 05.6.2) : compteurs EXACTS d'un événement, lus DANS la transaction de clôture, après les verrous
 * événement → participations. Les décisions et les dépôts prennent ces verrous : plus aucun écrivain n'est actif, les compteurs sont donc cohérents.
 */
interface ClosureCounters
{
    public function count(Uuid $eventId): ClosureStatistics;
}
