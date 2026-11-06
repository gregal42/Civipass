<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Domain\EventStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestListing;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\RequestEventOption;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Consultation municipale des demandes (lecture seule). La commune vient d'un identifiant NON FIABLE : MunicipalityContext
 * exige un membership effectif (refus uniforme 404), puis le rôle INSTRUCTOR de CE membership est exigé (403). Les dossiers
 * se retrouvent par leur portion communale : un identifiant de demande, de justificatif ou d'événement étranger donne le même
 * refus qu'une ressource inexistante.
 */
final readonly class MunicipalRequestReader
{
    public function __construct(
        private MunicipalityContext $context,
        private MunicipalRequestReadModel $read,
    ) {
    }

    /**
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function listing(User $user, string $municipalityId, ?string $eventId, MunicipalRequestQuery $query): MunicipalRequestListing
    {
        $municipality = $this->municipality($user, $municipalityId);
        $events = $this->read->eventsOf($municipality);
        $selected = null;
        if (null !== $eventId) {
            foreach ($events as $candidate) {
                if ($candidate->eventId === $eventId) {
                    $selected = $candidate;
                }
            }
            $selected ?? throw new OperationNotAllowed('Ressource inaccessible.');
        }
        // Par défaut : l'événement le plus récent qui accepte ou a accepté des dépôts (un brouillon n'a aucune demande).
        $selected ??= $this->firstWithStatus($events, EventStatus::Active) ?? $this->firstWithStatus($events, EventStatus::Suspended, EventStatus::Closed) ?? $events[0] ?? null;
        $categories = $this->read->categories();
        // Une catégorie inconnue n'est pas un filtre : elle est ignorée, jamais exécutée.
        if (null !== $query->categoryCode && [] === array_filter($categories, static fn (array $c): bool => $c['code'] === $query->categoryCode)) {
            $query = new MunicipalRequestQuery($query->search, null, $query->page);
        }

        return new MunicipalRequestListing($events, $selected, $categories, null === $selected ? null : $this->read->search($municipality, $selected, $query));
    }

    /**
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function detail(User $user, string $municipalityId, string $requestId): MunicipalRequestDetail
    {
        $municipality = $this->municipality($user, $municipalityId);

        return $this->read->detail($municipality, $this->uuid($requestId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
    }

    /**
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function attachment(User $user, string $municipalityId, string $requestId, string $attachmentId): DownloadableAttachment
    {
        $municipality = $this->municipality($user, $municipalityId);

        return $this->read->attachment($municipality, $this->uuid($requestId), $this->uuid($attachmentId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
    }

    /**
     * @param list<RequestEventOption> $events
     */
    private function firstWithStatus(array $events, EventStatus ...$statuses): ?RequestEventOption
    {
        foreach ($events as $event) {
            if (\in_array($event->status, $statuses, true)) {
                return $event;
            }
        }

        return null;
    }

    private function municipality(User $user, string $municipalityId): Uuid
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        if (!$membership->hasRole(MunicipalityRole::Instructor)) {
            throw new InstructorRoleRequired('Rôle INSTRUCTOR requis.');
        }

        return $membership->municipality()->id();
    }

    private function uuid(string $value): Uuid
    {
        return Uuid::isValid($value) ? Uuid::fromString($value) : throw new OperationNotAllowed('Ressource inaccessible.');
    }
}
