<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\Result\RevisionScope;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Application\Result\VehicleChangeResult;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\PermitRevisionVehicle;
use App\Permit\Domain\PlateInCommentDetector;
use App\Permit\Domain\PermitVehicle;
use App\Permit\Domain\VehicleAddReason;
use App\Permit\Domain\VehicleChange;
use App\Permit\Domain\VehicleChangeKind;
use App\Permit\Domain\VehicleChangePlanner;
use App\Permit\Domain\VehiclePlan;
use App\Permit\Domain\VehicleReinstatementReason;
use App\Permit\Domain\VehicleRemovalReason;
use App\Permit\Domain\VehicleReplacementReason;
use App\Permit\Domain\VehicleRowState;
use App\Request\Domain\LicensePlate;
use App\Request\Domain\PermitRequest;
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
 * Ajout, retrait, réactivation et remplacement des plaques d'un laissez-passer (LOT 05.5.4, DM-119 §4.4). Les plaques sont COMMUNES au laissez-passer : une commune
 * qui détient une autorisation NON révoquée (participation ACTIVE, rôle INSTRUCTOR ou MUNICIPALITY_ADMIN) les modifie pour toutes les communes, après avoir
 * CONFIRMÉ cette portée globale. Aucun droit communal (zones, période) n'est touché ; la décision, la demande citoyenne et le QR sont inchangés ; le périmètre
 * est celui du garde (jamais un identifiant d'autorisation fourni par le navigateur). Règles : `VehicleChangePlanner` (pure, mêmes pour l'aperçu et l'écriture).
 *
 * Opérations GROUPÉES : atomiques, validées sur l'ÉTAT FINAL du laissez-passer lu sous verrou, UNE révision immuable par plaque (lot commun `batch_id`) ; un
 * remplacement = deux révisions (`VEHICLE_REPLACED_OUT` et `VEHICLE_REPLACED_IN`) liées par ce lot, jamais reconstituées par l'horodatage. UNE intention de
 * notification par opération (retrait ou remplacement : PRIORITAIRE ; ajout et réactivation seuls : ordinaire). Aucune plaque dans le message.
 *
 * Verrous : événement (partagé) → participation → laissez-passer → ses lignes de plaques → autorisation (aucune zone). L'audit contient identifiants et codes de
 * motif : jamais la plaque ni le commentaire ; une ligne NEUTRE est écrite pour chaque autre commune titulaire. La plaque n'est conservée que dans
 * `permit_revision_vehicle` (anonymisation : LOT 05.6, bloquante avant la production).
 */
final readonly class PermitVehicleService
{
    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private PermitRevisionGuard $guard,
        private PermitRevisionRepository $revisions,
        private PermitRepository $permits,
        private ClockInterface $clock,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
        private VehicleChangePlanner $planner = new VehicleChangePlanner(),
    ) {
    }

    /**
     * Aperçu SANS écriture ni verrou : les règles appliquées à l'état lu « maintenant ».
     *
     * @throws OperationNotAllowed commune, demande, laissez-passer, plaque ou mode inaccessible
     * @throws PermitAccessDenied  rôle absent
     * @throws DomainRuleViolation refus motivé
     */
    public function preview(User $agent, string $municipalityId, string $requestId, VehicleChangeRequest $request): VehiclePlan
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: false);
        $rows = self::rows($scope->permit->vehicles());
        $operation = self::operation($request, $rows);

        return $this->plan($scope, $rows, $operation, $scope->permit->event()->status());
    }

    /**
     * @throws OperationNotAllowed     commune, demande, laissez-passer, plaque ou mode inaccessible (refus uniforme)
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE (relue sous verrou)
     * @throws DomainRuleViolation     refus motivé, portée non confirmée, motif manquant ou inconnu, commentaire invalide
     * @throws OperationConflict       conflit technique : rien n'est écrit, réessayable
     */
    public function change(User $agent, string $municipalityId, string $requestId, VehicleChangeRequest $request): VehicleChangeResult
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        $reasons = self::reasons($request);
        $comment = self::comment($request->comment, $reasons['needsComment']);
        if (!$request->confirmedGlobalScope) {
            throw new DomainRuleViolation('Confirmez que cette modification s\'applique à toutes les communes qui ont une autorisation sur ce laissez-passer : les plaques sont communes.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $request, $reasons, $comment): VehicleChangeResult {
            $membership = $this->guard->lock($scope, $agent, $municipalityId);
            $event = $this->locker->sharedEvent($scope->permit->event()->id());
            $now = self::second($this->clock->now()); // pris sous verrou (DT-038)
            $locked = $this->permits->lockVehicles($scope->permit);
            $rows = self::rows($locked);
            $operation = self::operation($request, $rows);
            self::assertNoPlateInComment($comment, $rows, $operation);
            $plan = $this->plan($scope, $rows, $operation, $event->status());

            $byId = [];
            foreach ($locked as $vehicle) {
                $byId[$vehicle->id()->toRfc4122()] = $vehicle;
            }
            $before = array_values(array_filter($rows, static fn (VehicleRowState $r): bool => !$r->removed));
            $batch = Uuid::v7();
            $first = null;
            $acting = $membership->municipality();
            foreach ($plan->changes as $change) {
                $reason = match ($change->kind) {
                    VehicleChangeKind::Removed => $reasons['remove'],
                    VehicleChangeKind::ReplacedOut, VehicleChangeKind::ReplacedIn => $reasons['replace'],
                    VehicleChangeKind::Reinstated => $reasons['reinstate'],
                    VehicleChangeKind::Added => $reasons['add'],
                };
                $state = ['activeVehicles' => \count($before), 'pair' => $change->pair];
                $revision = PermitRevision::vehicle($change->kind->revisionType(), $scope->permit, $acting, $agent, $reason, $comment, $now, $batch, $state, ['activeVehicles' => $plan->activeAfter, 'pair' => $change->pair]);
                $this->revisions->add($revision);
                $first ??= $revision;
                $vehicle = $this->apply($scope->permit, $change, $byId, $now);
                $this->revisions->addVehicleLine(new PermitRevisionVehicle($revision, $vehicle, $change->registration, $change->format, $change->position, $change->kind->action()));
                $this->recordAudit($change, $revision, $scope->permit, $acting->id(), $agent, $vehicle, $reason, $now);
            }
            if (null === $first) {
                throw new \LogicException('Une opération sans changement a déjà été refusée.');
            }
            $this->recordNeutralAudits($scope->permit, $acting->id(), $first, $batch, $now);
            // UNE intention par opération (révision : la première du lot) ; retrait ou remplacement : prioritaire.
            $this->notifications->record(new NotificationIntent(
                $plan->hasRemoval() ? NotificationKind::VehiclesRemoved : NotificationKind::VehiclesChanged,
                $scope->permit->id(),
                null, // les plaques appartiennent au laissez-passer : aucune autorisation (couverture par laissez-passer)
                $now,
                '' !== trim((string) $scope->permit->request()->email()),
                $first->id(),
            ));

            return new VehicleChangeResult($plan);
        });
    }

    /**
     * @param list<VehicleRowState>                                                                                                                 $rows
     * @param array{adds: list<LicensePlate>, removes: list<Uuid>, reinstates: list<Uuid>, replacements: list<array{from: Uuid, to: LicensePlate}>} $operation
     */
    private function plan(RevisionScope $scope, array $rows, array $operation, \App\Event\Domain\EventStatus $eventStatus): VehiclePlan
    {
        return $this->planner->change(
            $rows,
            $operation['adds'],
            $operation['removes'],
            $operation['reinstates'],
            $operation['replacements'],
            $scope->permit->request()->travelMode(),
            $scope->authorization->isRevoked(),
            $eventStatus,
            PermitRequest::MAX_VEHICLES,
        );
    }

    /** @param array<string, PermitVehicle> $byId */
    private function apply(Permit $permit, VehicleChange $change, array $byId, \DateTimeImmutable $now): PermitVehicle
    {
        if (null === $change->vehicleId) {
            return $permit->addVehicle($change->registration, $change->format, $change->position);
        }
        $vehicle = $byId[$change->vehicleId->toRfc4122()] ?? throw new \LogicException('Plaque planifiée absente des lignes verrouillées.');
        if (VehicleChangeKind::Reinstated === $change->kind) {
            $vehicle->reinstate();
        } else {
            $vehicle->retire($now);
        }

        return $vehicle;
    }

    /**
     * @param list<PermitVehicle> $vehicles
     *
     * @return list<VehicleRowState>
     */
    private static function rows(array $vehicles): array
    {
        return array_map(static fn (PermitVehicle $v): VehicleRowState => new VehicleRowState($v->id(), $v->registration(), $v->format(), $v->position(), !$v->isActive()), $vehicles);
    }

    /**
     * Identifiants et plaques du formulaire : jamais crus. Un identifiant mal formé ou qui n'est pas une plaque DE CE laissez-passer : refus uniforme.
     *
     * @param list<VehicleRowState> $rows
     *
     * @return array{adds: list<LicensePlate>, removes: list<Uuid>, reinstates: list<Uuid>, replacements: list<array{from: Uuid, to: LicensePlate}>}
     *
     * @throws OperationNotAllowed identifiant forgé
     * @throws DomainRuleViolation plaque invalide
     */
    private static function operation(VehicleChangeRequest $request, array $rows): array
    {
        $known = [];
        foreach ($rows as $row) {
            $known[$row->vehicleId->toRfc4122()] = true;
        }
        $id = static function (string $value) use ($known): Uuid {
            if (!Uuid::isValid($value) || !isset($known[Uuid::fromString($value)->toRfc4122()])) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }

            return Uuid::fromString($value);
        };

        return [
            'adds' => array_map(static fn (string $raw): LicensePlate => LicensePlate::fromInput($raw), $request->adds),
            'removes' => array_map($id, $request->removes),
            'reinstates' => array_map($id, $request->reinstates),
            'replacements' => array_map(static fn (array $pair): array => ['from' => $id($pair['from']), 'to' => LicensePlate::fromInput($pair['to'])], $request->replacements),
        ];
    }

    /**
     * Motifs : ajout facultatif ; retrait, remplacement et réactivation obligatoires quand ces changements sont présents. « Autre » exige un commentaire.
     *
     * @return array{add: ?string, remove: ?string, replace: ?string, reinstate: ?string, needsComment: bool}
     *
     * @throws DomainRuleViolation motif absent ou inconnu
     */
    private static function reasons(VehicleChangeRequest $request): array
    {
        $needs = false;
        $add = null;
        if ([] !== $request->adds && null !== $request->addReason && '' !== $request->addReason) {
            $reason = VehicleAddReason::tryFrom($request->addReason) ?? throw new DomainRuleViolation('Motif d\'ajout inconnu.');
            $add = $reason->value;
            $needs = $reason->needsComment();
        }
        $remove = null;
        if ([] !== $request->removes) {
            $reason = VehicleRemovalReason::tryFrom((string) $request->removeReason) ?? throw new DomainRuleViolation('Choisissez le motif du retrait.');
            $remove = $reason->value;
            $needs = $needs || $reason->needsComment();
        }
        $replace = null;
        if ([] !== $request->replacements) {
            $reason = VehicleReplacementReason::tryFrom((string) $request->replaceReason) ?? throw new DomainRuleViolation('Choisissez le motif du remplacement.');
            $replace = $reason->value;
            $needs = $needs || $reason->needsComment();
        }
        $reinstate = null;
        if ([] !== $request->reinstates) {
            $reason = VehicleReinstatementReason::tryFrom((string) $request->reinstateReason) ?? throw new DomainRuleViolation('Choisissez le motif de la réactivation.');
            $reinstate = $reason->value;
            $needs = $needs || $reason->needsComment();
        }

        return ['add' => $add, 'remove' => $remove, 'replace' => $replace, 'reinstate' => $reinstate, 'needsComment' => $needs];
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
        // Plaque SIV ou FNI dans le texte (séparateurs et présentations variés) : jamais conservée dans un commentaire ; voir `PlateInCommentDetector` (décision D1 du 11 novembre 2026).
        if (PlateInCommentDetector::containsPlate($comment)) {
            throw new DomainRuleViolation('Le commentaire ne doit contenir aucune plaque d\'immatriculation : elle est déjà conservée à part.');
        }

        return $comment;
    }

    /**
     * Les plaques de l'opération et celles du laissez-passer ne figurent jamais dans le commentaire (quelle que soit sa forme : séparateurs et casse ignorés).
     *
     * @param list<VehicleRowState>                                                                                                                 $rows
     * @param array{adds: list<LicensePlate>, removes: list<Uuid>, reinstates: list<Uuid>, replacements: list<array{from: Uuid, to: LicensePlate}>} $operation
     */
    private static function assertNoPlateInComment(?string $comment, array $rows, array $operation): void
    {
        if (null === $comment) {
            return;
        }
        $compact = mb_strtoupper((string) preg_replace('/[\s\p{Z}\-\x{2010}-\x{2015}._\/]+/u', '', $comment));
        $plates = [
            ...array_map(static fn (VehicleRowState $r): string => $r->registration, $rows),
            ...array_map(static fn (LicensePlate $p): string => $p->value, $operation['adds']),
            ...array_map(static fn (array $r): string => $r['to']->value, $operation['replacements']),
        ];
        foreach ($plates as $plate) {
            if (str_contains($compact, $plate)) {
                throw new DomainRuleViolation('Le commentaire ne doit contenir aucune plaque d\'immatriculation : elle est déjà conservée à part.');
            }
        }
    }

    private function recordAudit(VehicleChange $change, PermitRevision $revision, Permit $permit, Uuid $municipality, User $agent, PermitVehicle $vehicle, ?string $reason, \DateTimeImmutable $now): void
    {
        // Jamais la plaque ni le commentaire (DT-037) : identifiants, position et code de motif seulement.
        $this->audit->record(new AuditEvent(
            $now,
            match ($change->kind) {
                VehicleChangeKind::Added => AuditAction::PermitVehicleAdded,
                VehicleChangeKind::Removed => AuditAction::PermitVehicleRemoved,
                VehicleChangeKind::Reinstated => AuditAction::PermitVehicleReinstated,
                VehicleChangeKind::ReplacedOut => AuditAction::PermitVehicleReplacedOut,
                VehicleChangeKind::ReplacedIn => AuditAction::PermitVehicleReplacedIn,
            },
            AuditSubjectType::Permit,
            $permit->id(),
            $municipality,
            AuditActor::user($agent->id()),
            null,
            [
                'reference' => $permit->reference()->value,
                'revision' => $revision->id()->toRfc4122(),
                'batch' => $revision->batchId()->toRfc4122(),
                'vehicle' => $vehicle->id()->toRfc4122(),
                'position' => $change->position,
                'municipality' => $municipality->toRfc4122(),
                'reason' => $reason,
            ],
        ));
    }

    /** Une ligne NEUTRE par autre commune titulaire, révoquée ou non (DM-119 §7) : ni agent, ni motif, ni plaque, ni détail. */
    private function recordNeutralAudits(Permit $permit, Uuid $acting, PermitRevision $first, Uuid $batch, \DateTimeImmutable $now): void
    {
        $others = [];
        foreach ($permit->authorizations() as $authorization) {
            $id = $authorization->municipality()->id();
            if (!$id->equals($acting)) {
                $others[$id->toRfc4122()] = $id;
            }
        }
        ksort($others);
        foreach ($others as $other) {
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::PermitVehiclesChangedByOtherMunicipality,
                AuditSubjectType::Permit,
                $permit->id(),
                $other,
                AuditActor::system(),
                null,
                ['reference' => $permit->reference()->value, 'revision' => $first->id()->toRfc4122(), 'batch' => $batch->toRfc4122(), 'municipality' => $acting->toRfc4122()],
            ));
        }
    }

    private static function second(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTimestamp($utc->getTimestamp());
    }
}
