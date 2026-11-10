<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Permit\Application\Result\RevisionScope;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Application\Result\ZoneChangeResult;
use App\Permit\Domain\LabelCorrectionReason;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\ZoneAddReason;
use App\Permit\Domain\ZoneCandidate;
use App\Permit\Domain\ZoneChange;
use App\Permit\Domain\ZoneChangeKind;
use App\Permit\Domain\ZoneChangePlanner;
use App\Permit\Domain\ZonePlan;
use App\Permit\Domain\ZoneReinstatementReason;
use App\Permit\Domain\ZoneRemovalReason;
use App\Permit\Domain\ZoneRowState;
use App\Request\Application\ProposableZones;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ajout, retrait, réintégration et correction de libellé des zones de l'autorisation d'UNE commune (LOT 05.5.3, DM-119 §4.3). Une commune ne touche que SES zones
 * (celles de sa participation) et SON autorisation ; la décision, la demande citoyenne, le QR et les autres communes sont inchangés ; une autorisation révoquée
 * refuse tout. Règles : `ZoneChangePlanner` (pure, mêmes pour l'aperçu et l'écriture).
 *
 * Opérations GROUPÉES : plusieurs zones dans une même opération, ATOMIQUE (un refus annule tout), validée sur l'ÉTAT FINAL (jamais une autorisation sans zone
 * rattachée), UNE révision immuable par zone modifiée (batch commun), UNE intention de notification par opération (retrait ou opération mixte : PRIORITAIRE,
 * `ZONES_REMOVED` ; ajout ou réintégration seuls : ordinaire, `ZONES_CHANGED`). La correction de libellé ne notifie personne.
 *
 * Verrous : événement (partagé) → participation → zones concernées (identifiant croissant) → laissez-passer → autorisation. Zone, appartenance et proposabilité
 * sont relues SOUS verrou : un identifiant de zone n'est jamais cru (zone étrangère, inexistante ou d'un autre événement : refus uniforme `OperationNotAllowed`).
 * L'audit contient les identifiants et les codes de motif, jamais le commentaire.
 */
final readonly class AuthorizationZoneService
{
    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private PermitRevisionGuard $guard,
        private PermitRevisionRepository $revisions,
        private EventZoneRepository $zones,
        private ProposableZones $proposable,
        private ClockInterface $clock,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
        private ZoneChangePlanner $planner = new ZoneChangePlanner(),
    ) {
    }

    /**
     * Aperçu SANS écriture ni verrou : les zones rattachées après l'opération « maintenant ».
     *
     * @throws OperationNotAllowed commune, demande, laissez-passer ou zone inaccessible
     * @throws PermitAccessDenied  rôle absent
     * @throws DomainRuleViolation refus motivé
     */
    public function preview(User $agent, string $municipalityId, string $requestId, ZoneChangeRequest $request): ZonePlan
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: false);
        [$adds, $removes, $reinstates] = self::ids($request);
        $zones = [];
        foreach ([...$adds, ...$reinstates] as $id) {
            $zones[$id->toRfc4122()] = $this->zones->find($id) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $this->planChange($scope, $adds, $removes, $reinstates, $zones, $scope->permit->event(), self::second($this->clock->now()));
    }

    /**
     * @throws OperationNotAllowed     commune, demande, laissez-passer ou zone inaccessible
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE (relue sous verrou)
     * @throws DomainRuleViolation     refus motivé, motif manquant ou inconnu, commentaire invalide
     * @throws OperationConflict       conflit technique : rien n'est écrit, réessayable
     */
    public function change(User $agent, string $municipalityId, string $requestId, ZoneChangeRequest $request): ZoneChangeResult
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        [$adds, $removes, $reinstates] = self::ids($request);
        $reasons = self::reasons($request, [] !== $adds, [] !== $removes, [] !== $reinstates);
        $comment = self::comment($request->comment, $reasons['needsComment']);

        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $adds, $removes, $reinstates, $reasons, $comment): ZoneChangeResult {
            $membership = $this->guard->lock($scope, $agent, $municipalityId, [...$adds, ...$removes, ...$reinstates]);
            $event = $this->locker->sharedEvent($scope->permit->event()->id());
            $now = self::second($this->clock->now()); // pris sous verrou (DT-038)
            $locked = [];
            foreach ([...$adds, ...$reinstates] as $id) {
                $locked[$id->toRfc4122()] = $this->locker->zone($id, $scope->authorization->participation());
            }
            $plan = $this->planChange($scope, $adds, $removes, $reinstates, $locked, $event, $now);
            $authorization = $scope->authorization;
            $batch = Uuid::v7();
            $before = array_map(static fn (ZoneRowState $r): string => $r->zoneId->toRfc4122(), array_values(array_filter(self::rows($authorization), static fn (ZoneRowState $r): bool => !$r->removed)));
            sort($before);
            $first = null;
            foreach ($plan->changes as $change) {
                $reason = match ($change->kind) {
                    ZoneChangeKind::Removed => $reasons['remove'],
                    ZoneChangeKind::Reinstated => $reasons['reinstate'],
                    default => $reasons['add'],
                };
                $revision = PermitRevision::zone($change->kind->revisionType(), $authorization, $agent, $reason, $comment, $now, $batch, self::state($change, false, $before), self::state($change, true, $plan->activeAfter));
                $this->revisions->add($revision);
                $first ??= $revision;
                match ($change->kind) {
                    ZoneChangeKind::Removed => $authorization->retireZone($change->zoneId, $now),
                    ZoneChangeKind::Reinstated => $authorization->reinstateZone($change->zoneId, $change->label),
                    ZoneChangeKind::Added => $authorization->addZone($locked[$change->zoneId->toRfc4122()], $change->label),
                    ZoneChangeKind::LabelCorrected => throw new \LogicException('Une correction de libellé ne passe pas par change().'),
                };
                $this->recordAudit($change, $revision, $scope, $membership->municipality()->id(), $agent, $reason, $now);
            }
            if (null === $first) {
                throw new \LogicException('Une opération sans changement a déjà été refusée.');
            }
            // UNE intention par opération (révision : la première du lot) ; retrait ou opération mixte : prioritaire.
            $this->notifications->record(new NotificationIntent(
                $plan->hasRemoval() ? NotificationKind::ZonesRemoved : NotificationKind::ZonesChanged,
                $scope->permit->id(),
                $authorization->id(),
                $now,
                '' !== trim((string) $scope->permit->request()->email()),
                $first->id(),
            ));

            return new ZoneChangeResult($plan);
        });
    }

    /**
     * Correction de libellé : SYNCHRONISATION avec le libellé courant de la même zone, après confirmation que le périmètre géographique est inchangé. Aucun texte libre,
     * aucune notification. Possible pendant une suspension de l'événement.
     *
     * @param list<string> $zoneIds
     *
     * @return list<ZoneChange>
     *
     * @throws DomainRuleViolation motif absent ou inconnu, confirmation absente, libellé déjà à jour, refus motivé
     */
    public function correctLabels(User $agent, string $municipalityId, string $requestId, array $zoneIds, string $reasonCode, bool $confirmed): array
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        $ids = array_map(static fn (string $id): Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : throw new OperationNotAllowed('Ressource inaccessible.'), $zoneIds);
        $reason = LabelCorrectionReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Choisissez le motif de la correction.');
        if (!$confirmed) {
            throw new DomainRuleViolation('Confirmez que le périmètre géographique est inchangé : seul le libellé est corrigé.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $ids, $reason): array {
            $membership = $this->guard->lock($scope, $agent, $municipalityId, $ids);
            $event = $this->locker->sharedEvent($scope->permit->event()->id());
            $now = self::second($this->clock->now());
            $candidates = [];
            foreach ($ids as $id) {
                $zone = $this->locker->zone($id, $scope->authorization->participation());
                $candidates[$id->toRfc4122()] = new ZoneCandidate($id, $zone->label(), true, true);
            }
            $changes = $this->planner->relabel(self::rows($scope->authorization), $ids, $candidates, $scope->authorization->isRevoked(), $event->status());
            $batch = Uuid::v7();
            foreach ($changes as $change) {
                $revision = PermitRevision::zone(
                    $change->kind->revisionType(),
                    $scope->authorization,
                    $agent,
                    $reason->value,
                    null,
                    $now,
                    $batch,
                    ['zone' => $change->zoneId->toRfc4122(), 'label' => $change->previousLabel],
                    ['zone' => $change->zoneId->toRfc4122(), 'label' => $change->label],
                );
                $this->revisions->add($revision);
                $scope->authorization->relabelZone($change->zoneId, $change->label);
                $this->recordAudit($change, $revision, $scope, $membership->municipality()->id(), $agent, $reason->value, $now);
            }

            return $changes;
        });
    }

    /**
     * @param list<Uuid>               $adds
     * @param list<Uuid>               $removes
     * @param list<Uuid>               $reinstates
     * @param array<string, EventZone> $zones      zones visées par un ajout ou une réintégration
     */
    private function planChange(RevisionScope $scope, array $adds, array $removes, array $reinstates, array $zones, Event $event, \DateTimeImmutable $now): ZonePlan
    {
        $authorization = $scope->authorization;
        $own = $authorization->participation()->id();
        $usable = $this->proposable->withUsablePeriods(array_values(array_filter($zones, static fn (EventZone $z): bool => $z->mayOpenRights())), $event, $now);
        $usableIds = array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $usable);
        $candidates = [];
        foreach ($zones as $key => $zone) {
            if (!$zone->participation()->id()->equals($own)) {
                throw new OperationNotAllowed('Ressource inaccessible.'); // zone d'une autre commune : même refus que pour une zone inexistante
            }
            $candidates[$key] = new ZoneCandidate($zone->id(), $zone->label(), true, \in_array($zone->id()->toRfc4122(), $usableIds, true));
        }

        return $this->planner->change(self::rows($authorization), $adds, $removes, $reinstates, $candidates, $authorization->isRevoked(), $event->status());
    }

    /** @return list<ZoneRowState> */
    private static function rows(\App\Permit\Domain\PermitAuthorization $authorization): array
    {
        return [
            ...array_map(static fn ($z): ZoneRowState => new ZoneRowState($z->zone()->id(), $z->zoneLabel(), false), $authorization->activeZones()),
            ...array_map(static fn ($z): ZoneRowState => new ZoneRowState($z->zone()->id(), $z->zoneLabel(), true), $authorization->removedZones()),
        ];
    }

    /**
     * @return array{list<Uuid>, list<Uuid>, list<Uuid>}
     *
     * @throws OperationNotAllowed identifiant mal formé (jamais cru)
     */
    private static function ids(ZoneChangeRequest $request): array
    {
        $parse = static fn (array $values): array => array_map(static fn (string $id): Uuid => Uuid::isValid($id) ? Uuid::fromString($id) : throw new OperationNotAllowed('Ressource inaccessible.'), array_values($values));

        return [$parse($request->adds), $parse($request->removes), $parse($request->reinstates)];
    }

    /**
     * Motifs : ajout facultatif, retrait et réintégration obligatoires quand ces changements sont présents.
     *
     * @return array{add: ?string, remove: ?string, reinstate: ?string, needsComment: bool}
     *
     * @throws DomainRuleViolation motif absent ou inconnu
     */
    private static function reasons(ZoneChangeRequest $request, bool $adds, bool $removes, bool $reinstates): array
    {
        $needs = false;
        $add = null;
        if ($adds && null !== $request->addReason && '' !== $request->addReason) {
            $reason = ZoneAddReason::tryFrom($request->addReason) ?? throw new DomainRuleViolation('Motif d\'ajout inconnu.');
            $add = $reason->value;
            $needs = $reason->needsComment();
        }
        $remove = null;
        if ($removes) {
            $reason = ZoneRemovalReason::tryFrom((string) $request->removeReason) ?? throw new DomainRuleViolation('Choisissez le motif du retrait.');
            $remove = $reason->value;
            $needs = $needs || $reason->needsComment();
        }
        $reinstate = null;
        if ($reinstates) {
            $reason = ZoneReinstatementReason::tryFrom((string) $request->reinstateReason) ?? throw new DomainRuleViolation('Choisissez le motif de la réintégration.');
            $reinstate = $reason->value;
            $needs = $needs || $reason->needsComment();
        }

        return ['add' => $add, 'remove' => $remove, 'reinstate' => $reinstate, 'needsComment' => $needs];
    }

    private static function comment(?string $comment, bool $required): ?string
    {
        $comment = null === $comment ? '' : trim($comment);
        if (mb_strlen($comment) > PermitRevision::COMMENT_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le commentaire ne peut pas dépasser %d caractères.', PermitRevision::COMMENT_MAX_LENGTH));
        }
        if ('' === $comment) {
            if ($required) {
                throw new DomainRuleViolation('Un commentaire est obligatoire pour « Autre motif ».');
            }

            return null;
        }

        return $comment;
    }

    /**
     * État structuré d'une révision de zone (aucune donnée personnelle).
     *
     * @param list<string> $attached
     *
     * @return array<string, scalar|list<scalar>|null>
     */
    private static function state(ZoneChange $change, bool $after, array $attached): array
    {
        $label = $after ? $change->label : ($change->previousLabel ?? $change->label);

        return [
            'zone' => $change->zoneId->toRfc4122(),
            'label' => ZoneChangeKind::Added === $change->kind && !$after ? null : $label,
            'attachedZones' => $attached,
        ];
    }

    private function recordAudit(ZoneChange $change, PermitRevision $revision, RevisionScope $scope, Uuid $municipality, User $agent, ?string $reason, \DateTimeImmutable $now): void
    {
        // Jamais le commentaire, jamais une donnée personnelle (DT-037) : identifiants et code de motif seulement.
        $this->audit->record(new AuditEvent(
            $now,
            match ($change->kind) {
                ZoneChangeKind::Added => AuditAction::PermitZoneAdded,
                ZoneChangeKind::Removed => AuditAction::PermitZoneRemoved,
                ZoneChangeKind::Reinstated => AuditAction::PermitZoneReinstated,
                ZoneChangeKind::LabelCorrected => AuditAction::PermitZoneLabelCorrected,
            },
            AuditSubjectType::Permit,
            $scope->permit->id(),
            $municipality,
            AuditActor::user($agent->id()),
            null,
            [
                'reference' => $scope->permit->reference()->value,
                'authorization' => $scope->authorization->id()->toRfc4122(),
                'revision' => $revision->id()->toRfc4122(),
                'batch' => $revision->batchId()->toRfc4122(),
                'zone' => $change->zoneId->toRfc4122(),
                'municipality' => $municipality->toRfc4122(),
                'reason' => $reason,
            ],
        ));
    }

    private static function second(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTimestamp($utc->getTimestamp());
    }
}
