<?php

declare(strict_types=1);

namespace App\Event\Application;

use App\Event\Application\Read\EventListItem;
use App\Event\Application\Read\EventListPage;
use App\Event\Application\Read\EventListQuery;
use App\Event\Application\Read\EventParticipantHistoryEntry;
use App\Event\Application\Read\EventParticipationDetails;
use App\Event\Application\Read\EventParticipationSummary;
use App\Event\Application\Read\EventTypeOption;
use App\Event\Application\Read\InvitationCandidate;
use App\Event\Application\Read\InvitationView;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures restreintes AU NIVEAU DE LA REQUÊTE : chaque méthode ne sélectionne que les colonnes de sa projection et
 * ne retourne que les lignes de son périmètre (jamais « tout charger puis filtrer »). Aucune entité n'est chargée.
 * Les appelants (EventListReader, EventParticipantReader) ont déjà vérifié le droit de consulter.
 */
interface EventReadModel
{
    /**
     * Événements organisés par la commune OU auxquels elle participe (même invitée ou clôturée), du plus récent au plus ancien.
     *
     * @return list<EventListItem>
     */
    public function eventsOf(Uuid $municipalityId, int $limit): array;

    /**
     * Une page des événements de la commune (mêmes événements que `eventsOf`), filtrée par recherche, statut et type,
     * triée par date de début. Le filtrage, le tri et la pagination se font en SQL : jamais « tout charger puis filtrer ».
     */
    public function searchEventsOf(Uuid $municipalityId, EventListQuery $query): EventListPage;

    /**
     * Types d'événement (actifs ou non) pour le filtre de la liste, dans l'ordre d'affichage.
     *
     * @return list<EventTypeOption>
     */
    public function eventTypes(): array;

    /**
     * Coordination : l'organisatrice et les communes dont la participation est ACTIVE ou SUSPENDED. Jamais les
     * invitations en attente ni les participations clôturées (sauf l'organisatrice).
     *
     * @return list<EventParticipationSummary>
     */
    public function coordinationOf(Uuid $eventId): array;

    /**
     * Historique (U6) : communes dont la participation a été effectivement acceptée (`activated_at` renseigné),
     * organisatrice comprise. Jamais les invitations jamais acceptées.
     *
     * @return list<EventParticipantHistoryEntry>
     */
    public function historyOf(Uuid $eventId): array;

    /**
     * Toutes les participations de l'événement, avec leurs informations de gestion (organisatrice uniquement).
     *
     * @return list<EventParticipationDetails>
     */
    public function participationsOf(Uuid $eventId): array;

    /**
     * Communes à inviter : référentiel national (actif) ET communes déjà inscrites absentes du référentiel, dont le nom contient
     * `term` (`normalizedTerm` : même normalisation que le référentiel) ou dont le code INSEE commence par `term`. Une commune
     * inscrite non ACTIVE n'apparaît pas : « introuvable ou indisponible » (DM-086) ; une commune du référentiel non encore
     * inscrite est proposée (elle le sera à l'invitation). L'organisatrice n'est jamais proposée. Aucune donnée commerciale.
     *
     * @return list<InvitationCandidate>
     */
    public function searchCommunesForInvitation(Uuid $eventId, string $term, string $normalizedTerm, int $limit): array;

    public function invitationOf(Uuid $eventId, Uuid $municipalityId): ?InvitationView;
}
