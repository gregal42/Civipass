<?php

declare(strict_types=1);

namespace App\Request\Domain;

interface RequestReferentialRepository
{
    public function addMotif(RequestMotif $motif): void;

    public function findMotifByCode(string $code): ?RequestMotif;

    public function addCategory(BeneficiaryCategory $category): void;

    public function findCategoryByCode(string $code): ?BeneficiaryCategory;

    /**
     * Entrées actives, dans l'ordre d'affichage (jamais les désactivées).
     *
     * @return list<RequestMotif>
     */
    public function activeMotifs(): array;

    /**
     * @return list<BeneficiaryCategory>
     */
    public function activeCategories(): array;
}
