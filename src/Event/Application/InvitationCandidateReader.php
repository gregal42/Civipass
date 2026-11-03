<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\InvitationCandidates;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Recherche d'une commune à inviter à un événement (par nom ou code INSEE). Réservée à la commune organisatrice qui a le droit
 * d'inviter : contexte municipal vérifié (identifiant non fiable), événement organisé par cette commune, politique d'accès
 * « inviter ». Seules les communes actives sont trouvées ; leur situation vis-à-vis de l'événement est indiquée, jamais leurs
 * données commerciales ni de gestion. L'invitation elle-même reste décidée par EventParticipationService.
 */
final readonly class InvitationCandidateReader
{
    public const MIN_LENGTH = 2;
    public const MAX_RESULTS = 20;

    public function __construct(
        private MunicipalityContext $context,
        private EventRepository $events,
        private EventAccessPolicy $policy,
        private EventReadModel $read,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     */
    public function search(User $user, string $municipalityId, string $eventId, string $query): InvitationCandidates
    {
        $viewer = $this->context->requireMembership($user, $municipalityId, write: false)->municipality();
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event || !$event->isOrganizedBy($viewer)) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $this->policy->forEvent($user, EventPermission::InviteMunicipality, $event)->orThrow();

        $term = trim(mb_substr($query, 0, 100));
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return new InvitationCandidates($term, [], tooShort: '' !== $term);
        }

        return new InvitationCandidates($term, $this->read->searchMunicipalitiesForInvitation($event->id(), $term, self::MAX_RESULTS));
    }
}
