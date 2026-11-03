<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\EventType;
use App\Event\Domain\EventTypeCatalog;
use App\Event\Domain\EventTypeRepository;
use App\Shared\Application\TransactionRunner;

/**
 * Initialisation idempotente des types d'événement (DT-023) : crée uniquement les types absents (clé : code).
 * Un type existant n'est jamais modifié ni recréé : identifiant, libellé, ordre et état actif/inactif
 * (éventuellement changés ensuite par l'administration) sont conservés.
 */
final readonly class EventTypeInitializer
{
    public function __construct(
        private EventTypeRepository $types,
        private TransactionRunner $transactions,
    ) {
    }

    /**
     * @return list<string> codes créés lors de cet appel (vide si tout existait déjà)
     */
    public function initialize(): array
    {
        return $this->transactions->run(function (): array {
            $created = [];
            foreach (EventTypeCatalog::DEFAULTS as $position => $default) {
                if (null !== $this->types->findByCode($default['code'])) {
                    continue;
                }
                $this->types->add(new EventType($default['code'], $default['label'], ($position + 1) * 10));
                $created[] = $default['code'];
            }

            return $created;
        });
    }
}
