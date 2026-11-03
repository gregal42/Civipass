<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/**
 * Une page de la liste des événements d'une commune, avec le total des résultats et l'action « créer » calculée côté serveur.
 */
final readonly class EventListPage
{
    public int $pages;
    public int $page;

    /**
     * @param list<EventListItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        int $page,
        public int $perPage,
        public ?ActionAvailability $create = null,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
        $this->page = max(1, min($page, $this->pages));
    }

    /** Rang du premier résultat de la page (1 si la page est vide). */
    public function from(): int
    {
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return $this->from() + \count($this->items) - ($this->total > 0 ? 1 : 0);
    }

    public function withCreate(ActionAvailability $create): self
    {
        return new self($this->items, $this->total, $this->page, $this->perPage, $create);
    }
}
