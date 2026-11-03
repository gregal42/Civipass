<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

use App\Event\Domain\EventStatus;

/**
 * Critères d'une liste d'événements (recherche, statut, type, tri, page). Les valeurs sont normalisées à la
 * construction : une saisie hors limites ne produit jamais une requête hors limites.
 */
final readonly class EventListQuery
{
    public const SEARCH_MAX_LENGTH = 100;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    public ?string $search;
    public string $direction;
    public int $page;
    public int $perPage;

    /**
     * @param string $direction `desc` (début le plus récent d'abord) ou `asc` ; toute autre valeur vaut `desc`
     */
    public function __construct(
        ?string $search = null,
        public ?EventStatus $status = null,
        public ?string $typeCode = null,
        string $direction = 'desc',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $search = null === $search ? '' : trim($search);
        $this->search = '' === $search ? null : mb_substr($search, 0, self::SEARCH_MAX_LENGTH);
        $this->direction = 'asc' === $direction ? 'asc' : 'desc';
        $this->page = max(1, $page);
        $this->perPage = max(1, min($perPage, self::MAX_PER_PAGE));
    }

    public function hasFilters(): bool
    {
        return null !== $this->search || null !== $this->status || null !== $this->typeCode;
    }
}
