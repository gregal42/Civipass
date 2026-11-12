<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\EventManagerDesignationRepository;
use App\Archive\Domain\RequestLegalHoldRepository;
use App\Event\Application\EventStatusProbe;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestRepository;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SEULE porte de l'archive (LOT 05.6.3, DM-120) : aucun écran, service ou téléchargement d'archive ne lit un dossier sans passer ici. Tout est vérifié CÔTÉ SERVEUR à
 * CHAQUE requête (jamais mémorisé) : utilisateur actif et appartenance effective à la commune de l'URL (lecture : une commune suspendue garde la lecture), puis rôle
 * `MUNICIPALITY_ADMIN`, OU rôle `EVENT_MANAGER` ET désignation ACTIVE pour (événement, commune) ; dossier cherché par sa PORTION COMMUNALE ; événement CLOS (lecture
 * fraîche) ; archive ouverte (clôture + durée, ou gel actif). `INSTRUCTOR` seul, CWS sans appartenance, autre commune, identifiant forgé : refus UNIFORME (404),
 * jamais « clos », « archive » ni « échue ». N'appelle JAMAIS la garde opérationnelle de clôture (chemins de cycle de vie séparés).
 */
final readonly class ArchiveAccessPolicy
{
    public function __construct(
        private MunicipalityContext $context,
        private PermitRequestRepository $requests,
        private EventRepository $events,
        private EventParticipationRepository $participations,
        private EventManagerDesignationRepository $designations,
        private RequestLegalHoldRepository $holds,
        private EventStatusProbe $closure,
        private ArchiveSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Lecture d'un dossier archivé.
     *
     * @throws OperationNotAllowed refus uniforme
     */
    public function dossier(User $user, string $municipalityId, string $dossierId): ArchiveAccess
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        $part = $this->part($membership, $dossierId);
        $event = $part->request()->event();
        $this->requireClosed($event);
        $viaDesignation = $this->grant($user, $membership, $part->participation()) ?? throw self::deny();
        $hold = $this->holds->findActive($part->id());
        $closedAt = $event->closedAt() ?? throw self::deny();
        $closesAt = $this->settings->archiveClosesAt($closedAt);
        if (null === $hold && $this->clock->now() >= $closesAt) {
            throw self::deny(); // archive échue sans gel : fermée en attendant la purge (05.6.4)
        }

        return new ArchiveAccess($membership, $part, $viaDesignation, $hold, $closesAt);
    }

    /**
     * Gestion d'un dossier (gel) : `MUNICIPALITY_ADMIN` de la commune, écriture (commune ACTIVE), événement clos. Pas de condition d'archive ouverte : on peut geler
     * un dossier dont l'accès est échu pour le protéger de la purge.
     *
     * @throws OperationNotAllowed
     */
    public function administeredDossier(User $user, string $municipalityId, string $dossierId): ArchiveAccess
    {
        $membership = $this->administrator($user, $municipalityId, write: true);
        $part = $this->part($membership, $dossierId);
        $event = $part->request()->event();
        $this->requireClosed($event);
        $closedAt = $event->closedAt() ?? throw self::deny();

        return new ArchiveAccess($membership, $part, false, $this->holds->findActive($part->id()), $this->settings->archiveClosesAt($closedAt));
    }

    /**
     * Appartenance effective en lecture (liste des événements d'archive : chaque ligne est ensuite filtrée par `grant()`).
     *
     * @throws OperationNotAllowed
     */
    public function readMembership(User $user, string $municipalityId): MunicipalityMembership
    {
        return $this->context->requireMembership($user, $municipalityId, write: false);
    }

    /**
     * @throws OperationNotAllowed refus uniforme
     */
    public function administrator(User $user, string $municipalityId, bool $write): MunicipalityMembership
    {
        $membership = $this->context->requireMembership($user, $municipalityId, $write);
        if (!$membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            throw self::deny();
        }

        return $membership;
    }

    /**
     * Lecture des dossiers d'UN événement clos pour la commune de l'écran.
     *
     * @return array{Event, EventParticipation, bool} événement, participation, accès par désignation
     *
     * @throws OperationNotAllowed
     */
    public function event(User $user, string $municipalityId, string $eventId): array
    {
        $membership = $this->context->requireMembership($user, $municipalityId, write: false);
        $event = Uuid::isValid($eventId) ? $this->events->find(Uuid::fromString($eventId)) : null;
        if (null === $event) {
            throw self::deny();
        }
        $participation = $this->participations->findByEventAndMunicipality($event->id(), $membership->municipality()->id());
        if (null === $participation || null === $participation->activatedAt()) {
            throw self::deny();
        }
        $this->requireClosed($event);
        $viaDesignation = $this->grant($user, $membership, $participation) ?? throw self::deny();

        return [$event, $participation, $viaDesignation];
    }

    /** `true` : accès par désignation seulement ; `false` : par le rôle `MUNICIPALITY_ADMIN` ; `null` : aucun. */
    public function grant(User $user, MunicipalityMembership $membership, EventParticipation $participation): ?bool
    {
        if (!$user->isActive() || !$membership->isActive()) {
            return null;
        }
        if ($membership->hasRole(MunicipalityRole::MunicipalityAdmin)) {
            return false;
        }
        if ($membership->hasRole(MunicipalityRole::EventManager) && null !== $this->designations->findActive($participation->id(), $user->id())) {
            return true;
        }

        return null;
    }

    private function part(MunicipalityMembership $membership, string $dossierId): PermitRequestMunicipality
    {
        $part = Uuid::isValid($dossierId) ? $this->requests->findPart(Uuid::fromString($dossierId), $membership->municipality()->id()) : null;

        return $part ?? throw self::deny();
    }

    private function requireClosed(Event $event): void
    {
        if (!$this->closure->isClosed($event->id())) {
            throw self::deny();
        }
    }

    private static function deny(): OperationNotAllowed
    {
        return new OperationNotAllowed('Ressource inaccessible.');
    }
}
