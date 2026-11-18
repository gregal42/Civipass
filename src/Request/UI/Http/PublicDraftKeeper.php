<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Request\Application\TemporaryUploadService;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Cycle de vie du brouillon du formulaire PUBLIC (LOT 10, O-2) : ouverture avec expiration après 30 minutes d'inactivité, effacement
 * volontaire. Dans les deux cas les données sont SUPPRIMÉES côté serveur : la clé de session du brouillon est retirée et les justificatifs
 * temporaires qui lui étaient rattachés sont supprimés du stockage (jamais une pièce d'un dossier). Les sessions authentifiées des agents
 * ne sont pas concernées : seule la clé du brouillon public de l'événement est touchée.
 */
final readonly class PublicDraftKeeper
{
    public const FLASH = 'request_cleared';

    public function __construct(private TemporaryUploadService $uploads, private ClockInterface $clock)
    {
    }

    public function open(SessionInterface $session, string $eventReference): PublicRequestDraft
    {
        $draft = new PublicRequestDraft($session, $eventReference);
        $now = $this->clock->now()->getTimestamp();
        $expired = $draft->expireIfStale($now);
        if (null !== $expired) {
            $this->discard($expired);
            $this->flash($session, 'Votre saisie a été effacée après 30 minutes d’inactivité.');
        }
        $draft->touch($now);

        return $draft;
    }

    /** Effacement volontaire (« Effacer ma saisie »). */
    public function clear(SessionInterface $session, PublicRequestDraft $draft): void
    {
        $this->discard($draft->clear());
        $this->flash($session, 'Votre saisie a été effacée.');
    }

    /**
     * @param list<string> $keys
     */
    private function discard(array $keys): void
    {
        foreach ($keys as $key) {
            $this->uploads->discard($key);
        }
    }

    private function flash(SessionInterface $session, string $message): void
    {
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::FLASH, $message);
        }
    }
}
