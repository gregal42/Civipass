<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Domain\EventStatus;
use App\Shared\Domain\ClosedEventAccess;
use Symfony\Component\Uid\Uuid;

/**
 * Garde métier unique : un événement clos n'ouvre plus aucun accès OPÉRATIONNEL (dossiers, justificatifs, autorisations, plaques, liens), quel que
 * soit le rôle (DM-120). À appeler APRÈS le contrôle des habilitations. Protège aussi les appels hors HTTP.
 *
 * Les chemins de cycle de vie (clôture, archives du 05.6.3, purge du 05.6.4, commandes de purge des secrets) ne l'utilisent PAS : ils sont séparés
 * et explicitement autorisés. Un test d'architecture l'impose.
 */
final readonly class EventOperationalGuard
{
    public function __construct(private EventStatusProbe $events, private ClosedEventResolver $dossiers)
    {
    }

    public function isOpen(Uuid $eventId): bool
    {
        return !$this->events->isClosed($eventId);
    }

    /** @throws ClosedEventAccess */
    public function assertOpen(Uuid $eventId): void
    {
        if ($this->events->isClosed($eventId)) {
            throw ClosedEventAccess::create();
        }
    }

    /**
     * Dossier désigné par sa portion communale (commune, demande) : un couple inconnu n'est pas « clos » (le lecteur refuse déjà la ressource absente).
     *
     * @throws ClosedEventAccess
     */
    public function assertDossierOpen(Uuid $municipalityId, Uuid $requestId): void
    {
        if (EventStatus::Closed === $this->dossiers->eventStatusOfDossier($municipalityId, $requestId)) {
            throw ClosedEventAccess::create();
        }
    }

    /** @throws ClosedEventAccess */
    public function assertOpenForPermit(Uuid $permitId): void
    {
        if ($this->events->isClosedByPermit($permitId)) {
            throw ClosedEventAccess::create();
        }
    }
}
