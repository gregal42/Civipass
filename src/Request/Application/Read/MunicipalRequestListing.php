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
    ) {
    }
}
