<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestReferentialCatalog;
use App\Request\Domain\RequestReferentialRepository;
use App\Shared\Application\TransactionRunner;

/**
 * Initialisation idempotente des référentiels de demande (DT-039) : crée uniquement les entrées absentes (clé : code).
 * Une entrée existante n'est jamais modifiée : libellé, position et état actif/inactif (éventuellement changés ensuite
 * par l'administration) sont conservés. La migration insère les mêmes valeurs ; ce service rejoue l'initialisation
 * (tests, environnements purgés).
 */
final readonly class RequestReferentialInitializer
{
    public function __construct(
        private RequestReferentialRepository $referentials,
        private TransactionRunner $transactions,
    ) {
    }

    /**
     * @return array{motifs: list<string>, categories: list<string>} codes créés lors de cet appel
     */
    public function initialize(): array
    {
        return $this->transactions->run(function (): array {
            $created = ['motifs' => [], 'categories' => []];
            foreach (RequestReferentialCatalog::MOTIFS as $index => $default) {
                if (null === $this->referentials->findMotifByCode($default['code'])) {
                    $this->referentials->addMotif(new RequestMotif($default['code'], $default['label'], ($index + 1) * 10, $default['needsDetail']));
                    $created['motifs'][] = $default['code'];
                }
            }
            foreach (RequestReferentialCatalog::CATEGORIES as $index => $default) {
                if (null === $this->referentials->findCategoryByCode($default['code'])) {
                    $this->referentials->addCategory(new BeneficiaryCategory($default['code'], $default['label'], ($index + 1) * 10));
                    $created['categories'][] = $default['code'];
                }
            }

            return $created;
        });
    }
}
