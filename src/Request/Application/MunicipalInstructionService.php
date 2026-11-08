<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipation;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Result\ApprovalPreview;
use App\Request\Application\Result\MunicipalDecisionResult;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DecisionPath;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\RefusalReasonRepository;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Instruction classique d'une part communale (DM-106, DM-107) : approuver ou refuser, par un INSTRUCTOR de CETTE commune.
 *
 * Aucun identifiant du client n'est cru : la commune vient du membership effectif de l'agent (écriture : commune ACTIVE), jamais d'un
 * paramètre ; la demande se retrouve par sa part dans cette commune seulement (autre commune ou inexistante : même refus uniforme) ;
 * événement, participation, zones et part sont relus sous verrou.
 *
 * Ordre des verrous (DT-024, DT-041) : événement (partagé) → participation de la commune (exclusif) → zones demandées (approbation
 * seulement) → part. L'état, les droits et l'horodatage (DT-038) ne sont lus qu'après. Deux décisions concurrentes sur une même part :
 * la seconde, une fois le verrou obtenu, voit la part décidée et reçoit un OperationConflict(StaleState) ; l'unicité de la décision
 * en base est le dernier garde-fou. Dans tous les cas d'échec, ni décision ni audit ne subsistent.
 */
final readonly class MunicipalInstructionService
{
    private const UNIQUE_DECISION = 'uniq_request_decision_part';

    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private ClockInterface $clock,
        private MunicipalityContext $context,
        private PermitRequestRepository $requests,
        private RefusalReasonRepository $reasons,
        private MunicipalDecisionApplier $applier,
    ) {
    }

    /**
     * @throws OperationNotAllowed    commune, demande ou participation inaccessible, rôle INSTRUCTOR absent, participation non active
     * @throws DomainRuleViolation    zone inutilisable, durée invalide
     * @throws OperationConflict      part déjà décidée (concurrence ou rejeu) ou conflit technique
     * @throws InstructorRoleRequired rôle INSTRUCTOR absent (vérifié avant toute transaction)
     */
    public function approve(User $agent, string $municipalityId, string $requestId, ApprovalDuration $duration): MunicipalDecisionResult
    {
        return $this->decide($agent, $municipalityId, $requestId, true, fn (PermitRequestMunicipality $part, EventParticipation $participation, \DateTimeImmutable $now): PermitRequestDecision => $this->applier->approve($part, $participation, $agent, $duration, DecisionPath::Instruction, $now));
    }

    /**
     * @throws OperationNotAllowed    voir approve()
     * @throws DomainRuleViolation    motif inconnu ou inactif, commentaire manquant (« Autre ») ou trop long
     * @throws OperationConflict      part déjà décidée ou conflit technique
     * @throws InstructorRoleRequired rôle INSTRUCTOR absent
     */
    public function refuse(User $agent, string $municipalityId, string $requestId, string $reasonCode, ?string $comment): MunicipalDecisionResult
    {
        $reason = $this->reasons->findByCode($reasonCode) ?? throw new DomainRuleViolation('Choisissez un motif de refus.');

        return $this->decide($agent, $municipalityId, $requestId, false, fn (PermitRequestMunicipality $part, EventParticipation $participation, \DateTimeImmutable $now): PermitRequestDecision => $this->applier->refuse($part, $participation, $agent, $reason, $comment, DecisionPath::Instruction, $now));
    }

    /**
     * Prévisualisation de l'approbation, en LECTURE SEULE (aucune transaction, aucun verrou, aucune écriture) : fenêtre globale et zones
     * incompatibles avec la durée. Mêmes droits et même périmètre que approve() ; informatif seulement, la confirmation revalide tout.
     *
     * @throws OperationNotAllowed    commune, demande ou participation inaccessible ou non opérationnelle
     * @throws DomainRuleViolation    durée invalide (AmbiguousLocalTime si un choix est requis)
     * @throws InstructorRoleRequired rôle INSTRUCTOR absent
     */
    public function previewApproval(User $agent, string $municipalityId, string $requestId, ApprovalDuration $duration): ApprovalPreview
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        if (!$membership->hasRole(MunicipalityRole::Instructor)) {
            throw new InstructorRoleRequired('Rôle INSTRUCTOR requis.');
        }
        $municipality = $membership->municipality()->id();
        $locator = Uuid::isValid($requestId) ? $this->requests->locatePart(Uuid::fromString($requestId), $municipality) : null;
        $part = null === $locator ? null : $this->requests->findPart($locator->partId, $municipality);
        if (null === $part || MunicipalRequestStatus::Pending !== $part->status()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $this->applier->preview($part, $part->participation(), $duration, $this->clock->now());
    }

    /**
     * @param callable(PermitRequestMunicipality, EventParticipation, \DateTimeImmutable): PermitRequestDecision $apply
     */
    private function decide(User $agent, string $municipalityId, string $requestId, bool $lockZones, callable $apply): MunicipalDecisionResult
    {
        // Avant toute transaction : refus propres (commune inaccessible = uniforme, rôle manquant = 403 côté appelant).
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        if (!$membership->hasRole(MunicipalityRole::Instructor)) {
            throw new InstructorRoleRequired('Rôle INSTRUCTOR requis.');
        }
        $municipality = $membership->municipality()->id();
        $locator = Uuid::isValid($requestId) ? $this->requests->locatePart(Uuid::fromString($requestId), $municipality) : null;
        if (null === $locator) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $municipality, $locator, $lockZones, $apply): MunicipalDecisionResult {
            $event = $this->locker->sharedEvent($locator->eventId);
            $participation = $this->locker->participation($locator->participationId, $event);
            if ($lockZones) {
                // Une zone supprimée entre-temps n'est plus verrouillable : c'est un refus d'approbation métier, pas un « inaccessible ».
                try {
                    $this->locker->zones($locator->zoneIds, $participation);
                } catch (OperationNotAllowed) {
                    throw new DomainRuleViolation('Approbation impossible : une zone demandée n\'existe plus. Refusez la demande.');
                }
            }
            $part = $this->requests->lockPart($locator->partId, $municipality) ?? throw new OperationNotAllowed('Ressource inaccessible.');

            // Droits relus SOUS verrou (le membership a pu être modifié depuis le contrôle initial).
            $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
            if (!$membership->hasRole(MunicipalityRole::Instructor)) {
                throw new OperationNotAllowed('Rôle INSTRUCTOR requis.');
            }
            if (MunicipalRequestStatus::Pending !== $part->status() || null !== $part->decision()) {
                throw new OperationConflict(ConflictCause::StaleState);
            }

            $decision = $apply($part, $participation, $this->clock->now());

            return new MunicipalDecisionResult(
                $part->request()->id()->toRfc4122(),
                $part->request()->reference()->value,
                $decision->outcome(),
                $decision->decidedAt(),
                $decision->validFrom(),
                $decision->validUntil(),
            );
        }, [self::UNIQUE_DECISION]);
    }
}
