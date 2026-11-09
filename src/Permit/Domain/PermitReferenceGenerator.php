<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\Time\EventTimezone;

/**
 * Référence `LP-AAAA-NNNN` : compteur annuel, verrouillé EN DERNIER dans la transaction de décision (ordre DT-024).
 */
interface PermitReferenceGenerator
{
    /**
     * Prend le verrou du compteur de l'année SANS l'incrémenter : sérialise les créateurs de laissez-passer avant la relecture
     * verrouillante (évite les interblocages de verrous d'intervalle). Un rollback libère tout.
     *
     * @throws \LogicException hors transaction
     */
    public function lock(\DateTimeImmutable $now, EventTimezone $eventTimezone): void;

    /**
     * @throws \LogicException hors transaction
     */
    public function next(\DateTimeImmutable $now, EventTimezone $eventTimezone): PermitReference;
}
