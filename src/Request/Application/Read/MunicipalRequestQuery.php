<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/** Critères d'une page de la liste municipale : recherche libre (référence, nom, prénom), catégorie (code), statut de la portion communale et page. */
final readonly class MunicipalRequestQuery
{
    public const PAGE_SIZE = 20;

    public function __construct(
        public string $search = '',
        public ?string $categoryCode = null,
        public int $page = 1,
        public ?MunicipalRequestStatus $status = null,
    ) {
    }

    public function hasFilters(): bool
    {
        return '' !== $this->search || null !== $this->categoryCode || null !== $this->status;
    }
}
