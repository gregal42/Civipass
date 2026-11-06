<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Event\Domain\Event;

/**
 * Contexte d'un parcours de dépôt : l'événement, le brouillon (propre au contexte), les URL des étapes et de la confirmation,
 * et, pour la saisie par un agent, la commune d'origine déjà vérifiée. `view` porte les variables propres au gabarit (menu
 * municipal pour l'agent).
 */
final readonly class RequestFormContext
{
    /**
     * @param \Closure(PublicRequestStep): string $stepUrl
     * @param \Closure(): string                  $confirmationUrl
     * @param array<string, mixed>                $view
     */
    public function __construct(
        public Event $event,
        public PublicRequestDraft $draft,
        public string $csrfId,
        public \Closure $stepUrl,
        public \Closure $confirmationUrl,
        public bool $agent = false,
        public ?\App\Identity\Domain\User $user = null,
        public ?string $municipalityId = null,
        public array $view = [],
    ) {
    }
}
