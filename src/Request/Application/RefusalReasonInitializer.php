<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Domain\RefusalReason;
use App\Request\Domain\RefusalReasonRepository;
use App\Request\Domain\RequestReferentialCatalog;
use App\Shared\Application\TransactionRunner;

/**
 * Initialisation idempotente du référentiel des motifs de refus (DM-110, DT-039) : crée uniquement les entrées absentes (clé : code),
 * sans jamais modifier une entrée existante (libellé, position et activation éventuellement changés ensuite sont conservés).
 * La migration insère les mêmes valeurs ; ce service rejoue l'initialisation (tests, environnements purgés).
 */
final readonly class RefusalReasonInitializer
{
    public function __construct(
        private RefusalReasonRepository $reasons,
        private TransactionRunner $transactions,
    ) {
    }

    /**
     * @return list<string> codes créés lors de cet appel
     */
    public function initialize(): array
    {
        return $this->transactions->run(function (): array {
            $created = [];
            foreach (RequestReferentialCatalog::REFUSAL_REASONS as $index => $default) {
                if (null === $this->reasons->findByCode($default['code'])) {
                    $this->reasons->add(new RefusalReason($default['code'], $default['label'], ($index + 1) * 10, $default['needsDetail']));
                    $created[] = $default['code'];
                }
            }

            return $created;
        });
    }
}
