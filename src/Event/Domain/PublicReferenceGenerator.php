<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Contrat de génération de la référence publique (DT-025). L'implémentation doit s'exécuter dans la transaction
 * de création de l'événement, verrouiller la ligne unique du compteur global et ne jamais le remettre à zéro.
 * Aucun appelant avant l'étape 5.
 */
interface PublicReferenceGenerator
{
    /**
     * @throws \LogicException hors transaction ou compteur non initialisé
     */
    public function next(\DateTimeImmutable $now): PublicReference;
}
