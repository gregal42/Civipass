<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\EventStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Événement d'un dossier communal, trouvé par sa portion (commune, demande) : un couple étranger ou inexistant donne `null`, comme une ressource absente.
 * Lecture SQL directe : aucune dépendance de code vers les modules Request et Permit.
 */
interface ClosedEventResolver
{
    public function eventStatusOfDossier(Uuid $municipalityId, Uuid $requestId): ?EventStatus;
}
