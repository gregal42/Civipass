<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Request\Application\Result\ApprovalPreview;
use App\Request\Domain\ApprovalDuration;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Prévisualisation d'une délivrance directe (DM-108), en LECTURE SEULE : aucune demande n'existe encore, rien n'est écrit ni verrouillé.
 * Même droits qu'à la délivrance (AgentRequestAccess : adhésion effective, rôle, commune active, participation et événement
 * opérationnels) et mêmes règles de durée et de zones que le moteur de décision (MunicipalDecisionApplier). Informatif : la délivrance
 * rejoue tout sous verrou. Les zones se relisent par identifiant et doivent appartenir à la participation DE L'AGENT.
 */
final readonly class DirectIssuancePreview
{
    public function __construct(
        private AgentRequestAccess $access,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private MunicipalDecisionApplier $decisions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $zoneIds identifiants non fiables
     *
     * @throws OperationNotAllowed commune, événement ou participation inaccessible
     * @throws AgentRequestDenied  rôle insuffisant ou participation non opérationnelle
     * @throws DomainRuleViolation zone étrangère ou inconnue, durée invalide (AmbiguousLocalTime si un choix est requis)
     */
    public function preview(User $agent, string $municipalityId, string $eventId, array $zoneIds, ApprovalDuration $duration): ApprovalPreview
    {
        $scope = $this->access->authorize($agent, $municipalityId, $eventId);
        $own = $this->participations->findByEventAndMunicipality($scope->event->id(), $scope->municipality->id()) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $zones = [];
        foreach (array_unique($zoneIds) as $id) {
            $zone = Uuid::isValid($id) ? $this->zones->find(Uuid::fromString($id)) : null;
            if (!$zone instanceof EventZone || $zone->isDeleted() || !$zone->participation()->id()->equals($own->id())) {
                throw new DomainRuleViolation('Une saisie municipale ne peut porter que sur les zones de votre commune.');
            }
            $zones[] = $zone;
        }
        if ([] === $zones) {
            throw new DomainRuleViolation('Choisissez au moins une zone.');
        }

        return $this->decisions->previewZones($own, $zones, $duration, $this->clock->now());
    }
}
