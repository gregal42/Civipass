<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Application\EventOperationalGuard;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Read\DecisionPage;
use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestListing;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\RequestEventOption;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\RefusalReason;
use App\Request\Domain\RefusalReasonRepository;
use App\Shared\Domain\ClosedEventAccess;
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
        private RefusalReasonRepository $refusalReasons,
        private EventOperationalGuard $closure,
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
            // Événement clos (LOT 05.6.2) : habilitation déjà vérifiée, aucune ligne n'est servie.
            if (EventStatus::Closed === $selected->status) {
                throw ClosedEventAccess::create();
            }
        }
        $closedEventCount = \count(array_filter($events, static fn (RequestEventOption $e): bool => EventStatus::Closed === $e->status));
        // Les événements clos ne sont plus des événements d'exploitation : ils ne sont ni proposés ni sélectionnés par défaut (aucune donnée ne s'y lit).
        $events = array_values(array_filter($events, static fn (RequestEventOption $e): bool => EventStatus::Closed !== $e->status));
        // Par défaut : l'événement le plus récent qui accepte ou a accepté des dépôts (un brouillon n'a aucune demande).
        $selected ??= $this->firstWithStatus($events, EventStatus::Active) ?? $this->firstWithStatus($events, EventStatus::Suspended) ?? $events[0] ?? null;
        $categories = $this->read->categories();
        // Une catégorie inconnue n'est pas un filtre : elle est ignorée, jamais exécutée.
        if (null !== $query->categoryCode && [] === array_filter($categories, static fn (array $c): bool => $c['code'] === $query->categoryCode)) {
            $query = new MunicipalRequestQuery($query->search, null, $query->page, $query->status);
        }

        return new MunicipalRequestListing($events, $selected, $categories, null === $selected ? null : $this->read->search($municipality, $selected, $query), $closedEventCount);
    }

    /**
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function detail(User $user, string $municipalityId, string $requestId): MunicipalRequestDetail
    {
        $municipality = $this->municipality($user, $municipalityId);
        $detail = $this->read->detail($municipality, $this->uuid($requestId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        // Habilitation et portion communale vérifiées : un événement clos ne sert plus aucun dossier (LOT 05.6.2).
        if (EventStatus::Closed === $detail->eventStatus) {
            throw ClosedEventAccess::create();
        }

        return $detail;
    }

    /**
     * Fiche de la commune + ce qu'il faut aux écrans de décision : motifs de refus actifs et raison éventuelle d'indisponibilité
     * (commune suspendue, participation non active, événement non opérationnel). Informatif : le service d'instruction revérifie tout.
     *
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function decisionPage(User $user, string $municipalityId, string $requestId): DecisionPage
    {
        $detail = $this->detail($user, $municipalityId, $requestId);

        return new DecisionPage(
            $detail,
            array_map(static fn (RefusalReason $r): array => ['code' => $r->code(), 'label' => $r->label(), 'needsDetail' => $r->needsDetail()], $this->refusalReasons->active()),
            MunicipalRequestStatus::Pending === $detail->status ? $this->unavailableReason($user, $municipalityId, $detail) : null,
        );
    }

    private function unavailableReason(User $user, string $municipalityId, MunicipalRequestDetail $detail): ?string
    {
        try {
            $this->context->requireMembership($user, $municipalityId, write: true);
        } catch (OperationNotAllowed) {
            return 'Votre commune est suspendue : la décision est impossible, le dossier reste consultable.';
        }

        return match (true) {
            ParticipationStatus::Active !== $detail->participationStatus => 'La participation de votre commune à cet événement n’est pas active : la décision est impossible, le dossier reste consultable.',
            EventStatus::Active !== $detail->eventStatus => 'L’événement n’est pas ouvert aux décisions (suspendu ou clôturé) : le dossier reste consultable.',
            default => null,
        };
    }

    /**
     * @throws OperationNotAllowed
     * @throws InstructorRoleRequired
     */
    public function attachment(User $user, string $municipalityId, string $requestId, string $attachmentId): DownloadableAttachment
    {
        $municipality = $this->municipality($user, $municipalityId);
        $request = $this->uuid($requestId);
        $this->closure->assertDossierOpen($municipality, $request); // le fichier n'est pas ouvert si l'événement est clos (LOT 05.6.2)

        return $this->read->attachment($municipality, $request, $this->uuid($attachmentId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
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
