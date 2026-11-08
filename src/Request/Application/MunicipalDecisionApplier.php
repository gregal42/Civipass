<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZoneValidityRepository;
use App\Event\Domain\Validity\EffectivePeriods;
use App\Event\Domain\Validity\EventPeriod;
use App\Event\Domain\Validity\InvalidValiditySlot;
use App\Event\Domain\Validity\ZoneValidityCalculator;
use App\Identity\Domain\User;
use App\Request\Application\Result\ApprovalPreview;
use App\Request\Application\Result\ZoneProblem;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DecisionPath;
use App\Request\Domain\GrantWindow;
use App\Request\Domain\GrantWindowCalculator;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestZone;
use App\Request\Domain\RefusalReason;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Moteur UNIQUE de décision d'une part communale (DM-106) : instruction classique aujourd'hui, délivrance directe au LOT 04.3
 * (même méthode, `DecisionPath` différent). Il ne gère NI transaction NI verrou NI droits de l'agent : l'appelant a déjà, dans
 * sa transaction, verrouillé l'événement, la participation, les zones (approbation) et la part, et vérifié que `$by` peut décider
 * pour cette commune. Ce service revalide l'état verrouillé, applique les règles, crée la décision, écrit l'audit puis prévient le
 * LOT 05 (ApprovedPartListener). Toutes les vérifications précèdent la première mutation : un refus ne laisse rien en attente.
 *
 * Approbation : participation et événement opérationnels ; CHAQUE zone demandée encore ouvrable (DM-109 : confirmée, active, non
 * supprimée, validité configurée) ET dotée d'au moins une période d'accès effective dans la fenêtre d'autorisation choisie ; sinon échec intégral, sans décision
 * partielle. Refus : opération distincte, sans condition sur les zones.
 */
final readonly class MunicipalDecisionApplier
{
    public function __construct(
        private AuditTrail $audit,
        private EventZoneValidityRepository $validities,
        private ApprovedPartListener $approved,
        private GrantWindowCalculator $windows = new GrantWindowCalculator(),
        private ZoneValidityCalculator $zoneCalculator = new ZoneValidityCalculator(),
    ) {
    }

    /**
     * @throws OperationNotAllowed participation ou événement non opérationnel
     * @throws DomainRuleViolation zone inutilisable, durée invalide, part déjà décidée
     */
    public function approve(PermitRequestMunicipality $part, EventParticipation $participation, User $by, ApprovalDuration $duration, DecisionPath $path, \DateTimeImmutable $now): PermitRequestDecision
    {
        $now = self::wholeSeconds($now);
        $this->assertDecidable($part, $participation);
        $event = $participation->event();
        $window = $this->windows->window($duration, $now, $event->timezone(), $event->endsAt());
        $this->assertZonesUsable($part, $participation, $window);

        $decision = $part->approve($window, $by, $path, $now);
        $this->recordAudit(AuditAction::RequestApproved, $part, $decision, $by, [
            'durationMode' => $window->mode->value,
            'validFrom' => self::iso($window->from),
            'validUntil' => null === $window->until ? null : self::iso($window->until),
        ]);
        $this->approved->partApproved($decision);

        return $decision;
    }

    /**
     * @throws OperationNotAllowed participation ou événement non opérationnel
     * @throws DomainRuleViolation motif inactif, commentaire manquant ou trop long, part déjà décidée
     */
    public function refuse(PermitRequestMunicipality $part, EventParticipation $participation, User $by, RefusalReason $reason, ?string $comment, DecisionPath $path, \DateTimeImmutable $now): PermitRequestDecision
    {
        $now = self::wholeSeconds($now);
        $this->assertDecidable($part, $participation);

        $decision = $part->refuse($reason, $comment, $by, $path, $now);
        // Le commentaire libre n'entre JAMAIS dans l'audit (DT-037) : seul le code du motif.
        $this->recordAudit(AuditAction::RequestRefused, $part, $decision, $by, ['reasonCode' => $reason->code()]);

        return $decision;
    }

    private function assertDecidable(PermitRequestMunicipality $part, EventParticipation $participation): void
    {
        if (!$part->participation()->id()->equals($participation->id())) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if (!$participation->isOperational()) {
            throw new OperationNotAllowed('Décision impossible : la participation de la commune ou l\'événement n\'est pas opérationnel.');
        }
    }

    /**
     * Tout ou rien (DM-109 §6) : CHAQUE zone demandée doit être ouvrable ET disposer d'au moins une période d'accès effective
     * non vide DANS la fenêtre globale choisie (fenêtre ∩ événement ∩ périodes de la zone ; intervalles semi-ouverts, donc un
     * créneau qui commence à la fin de la fenêtre ou finit à son début est incompatible). Les créneaux de zones différentes
     * n'ont pas à se chevaucher. Zones relues sous verrou par l'appelant.
     */
    private function assertZonesUsable(PermitRequestMunicipality $part, EventParticipation $participation, GrantWindow $window): void
    {
        foreach ($this->zoneProblems($part, $participation, $window) as $problem) {
            throw new DomainRuleViolation(ZoneProblem::SUSPENDED === $problem->cause ? sprintf('Approbation impossible : la zone « %s » est suspendue ou n\'est plus utilisable. Refusez la demande ou attendez la réouverture de la zone.', $problem->label) : sprintf('Approbation impossible : la zone « %s » n\'a aucune période d\'accès dans la durée d\'autorisation choisie. Choisissez une durée plus longue ou refusez la demande.', $problem->label));
        }
    }

    /**
     * Zones qui empêchent l'approbation avec cette fenêtre (aucune si elle est possible). Lecture seule : mêmes règles que la décision.
     *
     * @return list<ZoneProblem>
     */
    private function zoneProblems(PermitRequestMunicipality $part, EventParticipation $participation, GrantWindow $window): array
    {
        $event = $participation->event();
        $period = new EventPeriod($event->startsAt(), $event->endsAt());
        $problems = [];
        foreach ($part->zones() as $requested) {
            if (!$requested->zone()->mayOpenRights()) {
                $problems[] = new ZoneProblem($requested->zoneLabel(), ZoneProblem::SUSPENDED);
            } elseif ($this->windows->effectivePeriods($window, $this->zonePeriods($requested, $period), $participation)->isEmpty()) {
                $problems[] = new ZoneProblem($requested->zoneLabel(), ZoneProblem::NO_PERIOD);
            }
        }

        return $problems;
    }

    /**
     * Prévisualise l'approbation SANS rien écrire ni verrouiller (récapitulatif avant confirmation) : fenêtre calculée et zones
     * bloquantes. N'est qu'informatif : la confirmation revalide tout sous verrou.
     *
     * @throws OperationNotAllowed participation ou événement non opérationnel
     * @throws DomainRuleViolation durée invalide
     */
    public function preview(PermitRequestMunicipality $part, EventParticipation $participation, ApprovalDuration $duration, \DateTimeImmutable $now): ApprovalPreview
    {
        $now = self::wholeSeconds($now);
        $this->assertDecidable($part, $participation);
        $event = $participation->event();
        $window = $this->windows->window($duration, $now, $event->timezone(), $event->endsAt());

        return new ApprovalPreview($window, $this->zoneProblems($part, $participation, $window));
    }

    private function zonePeriods(PermitRequestZone $requested, EventPeriod $period): EffectivePeriods
    {
        $zone = $requested->zone();
        $slots = array_map(static fn ($validity) => $validity->toSlot(), $this->validities->findActiveByZone($zone->id()));
        try {
            return $this->zoneCalculator->compute($zone->validityMode(), $period, $zone->participation()->event()->timezone(), $slots);
        } catch (InvalidValiditySlot) {
            return new EffectivePeriods([]); // donnée incohérente : aucun droit n'est accordé
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function recordAudit(AuditAction $action, PermitRequestMunicipality $part, PermitRequestDecision $decision, User $by, array $details): void
    {
        $request = $part->request();
        $this->audit->record(new AuditEvent(
            $decision->decidedAt(),
            $action,
            AuditSubjectType::PermitRequest,
            $request->id(),
            $part->municipality()->id(),
            AuditActor::user($by->id()),
            ['status' => 'PENDING'],
            [
                'status' => $decision->outcome()->value,
                'decision' => $decision->id()->toRfc4122(),
                'reference' => $request->reference()->value,
                'event' => $request->event()->id()->toRfc4122(),
                'path' => $decision->path()->value,
                'zones' => \count($part->zones()),
            ] + $details,
        ));
    }

    /** La base stocke des secondes entières : la fenêtre calculée doit être identique avant et après relecture. */
    private static function wholeSeconds(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->setTimestamp($instant->getTimestamp());
    }

    private static function iso(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
