<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/** Liste municipale complète : événements sélectionnables, événement courant, options de catégorie et page. */
final readonly class MunicipalRequestListing
{
    /**
     * @param list<RequestEventOption>                 $events
     * @param list<array{code: string, label: string}> $categories
     */
    public function __construct(
        public array $events,
        public ?RequestEventOption $event,
        public array $categories,
        public ?MunicipalRequestPage $page,
        /** Événements CLOS de la commune (LOT 05.6.2) : ni listés ni sélectionnés, mais leur existence explique l'état vide. */
        public int $closedEventCount = 0,
    ) {
    }
}
