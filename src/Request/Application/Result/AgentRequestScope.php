<?php

declare(strict_types=1);

namespace App\Request\Application\Result;

use App\Event\Domain\Event;
use App\Municipality\Domain\Municipality;

/** Contexte vérifié d'une saisie par un agent : sa commune d'origine et l'événement, tous deux éligibles au dépôt. */
final readonly class AgentRequestScope
{
    public function __construct(public Municipality $municipality, public Event $event)
    {
    }
}
