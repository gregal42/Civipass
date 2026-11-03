<?php

declare(strict_types=1);

namespace App\Event\UI\Http;

use App\Event\Application\Read\EventDetail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Variables communes des pages d'un événement (fiche, communes) : contexte du layout, URLs, message d'erreur éventuel.
 * Utilisée aussi pour réafficher une page après un échec : elle ne lit RIEN en base (le détail et le contexte sont lus avant
 * l'écriture, l'EntityManager pouvant être fermé après un échec transactionnel).
 */
final readonly class EventViewFactory
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param array<string, mixed> $shell
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function page(array $shell, EventDetail $detail, string $municipalityId, string $title, ?string $alert = null, array $extra = []): array
    {
        $route = ['municipalityId' => $municipalityId, 'eventId' => $detail->eventId];

        return array_merge($shell, [
            'page_title' => $title,
            'event' => ['id' => 'event', 'kicker' => 'Événement', 'value' => $detail->name, 'options' => []],
            'ctx_parts' => [...$shell['ctx_parts'], ['text' => $detail->name, 'strong' => true]],
            'detail' => $detail,
            'list_url' => $this->urls->generate('app_municipal_events', ['municipalityId' => $municipalityId]),
            'event_url' => $this->urls->generate('app_municipal_event', $route),
            'edit_url' => $this->urls->generate('app_municipal_event_edit', $route),
            'invite_url' => $this->urls->generate('app_municipal_event_invite', $route),
            'communes_url' => $this->urls->generate('app_municipal_event_communes', $route),
            'alert' => $alert,
            'open_panel' => null,
            'suspend_reason' => '',
        ], $extra);
    }
}
