<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Time\EventTimezone;

/**
 * Contrat de génération de la référence d'une demande (S1). L'implémentation s'exécute dans la transaction du dépôt,
 * verrouille la ligne du compteur de l'année et ne délivre jamais deux fois le même numéro ; un rollback annule
 * l'incrément. L'année est celle de `$now` dans le fuseau de l'événement.
 */
interface RequestReferenceGenerator
{
    /**
     * @throws \LogicException hors transaction
     */
    public function next(\DateTimeImmutable $now, EventTimezone $eventTimezone): RequestReference;
}
