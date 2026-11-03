<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\EventListItem;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Liste des événements d'une commune, restreinte à la source. Le contexte municipal vient d'un identifiant NON FIABLE :
 * MunicipalityContext exige un membership effectif de l'utilisateur dans cette commune (utilisateur actif, membership
 * actif, commune ACTIVE ou SUSPENDED en lecture ; INACTIVE refusée), avec le même refus pour « inexistante » et
 * « non autorisée ». Le rôle n'importe pas : la consultation est ouverte à tout membre (agent instructeur compris,
 * DM-084). Rien n'est chargé hors du périmètre de la commune.
 */
final readonly class EventListReader
{
    public const MAX_LIMIT = 200;

    public function __construct(
        private MunicipalityContext $context,
        private EventReadModel $read,
    ) {
    }

    /**
     * @return list<EventListItem>
     *
     * @throws OperationNotAllowed
     */
    public function forMunicipality(User $user, string $municipalityId, int $limit = 100): array
    {
        $municipality = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();

        return $this->read->eventsOf($municipality->id(), max(1, min($limit, self::MAX_LIMIT)));
    }
}
