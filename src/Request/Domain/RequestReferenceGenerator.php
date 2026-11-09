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
     * Prend le verrou de la ligne du compteur de l'année SANS l'incrémenter (la ligne est créée si besoin). Sert à sérialiser les créateurs AVANT la
     * relecture verrouillante de l'empreinte d'idempotence (clé absente : verrou d'intervalle) : sans lui, deux dépôts de participations différentes
     * tiennent chacun un verrou d'intervalle puis s'interbloquent à l'insertion de l'empreinte. Même verrou que `next()` : aucun nouvel ordre.
     *
     * @throws \LogicException hors transaction
     */
    public function lock(\DateTimeImmutable $now, EventTimezone $eventTimezone): void;

    /**
     * @throws \LogicException hors transaction
     */
    public function next(\DateTimeImmutable $now, EventTimezone $eventTimezone): RequestReference;
}
