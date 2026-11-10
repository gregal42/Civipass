<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Permit\Application\Read\PermitRightsOverview;
use App\Permit\Application\Read\RevisionLine;
use App\Permit\Application\Read\QrPanel;
use App\Permit\Application\Read\VehicleRowView;
use App\Permit\Application\Read\VehiclesPanel;
use App\Permit\Application\Read\ZoneOption;
use App\Permit\Application\Read\ZoneRowView;
use App\Event\Domain\EventStatus;
use App\Permit\Domain\PeriodReason;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\RevisionType;
use App\Permit\Domain\ShorteningReason;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RevocationReason;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Lecture de l'écran « Droits du laissez-passer » (LOT 05.5.1). Même périmètre que le garde de révision (rôle INSTRUCTOR ou MUNICIPALITY_ADMIN,
 * autorisation de SA commune), en consultation. Historique : le détail d'une révision (motif, commentaire, auteur) n'est lu QUE pour la commune
 * qui l'a faite ; pour les autres, une ligne neutre (type, date, commune), jamais davantage (DM-101 §3, DM-119 §7).
 */
final readonly class PermitRightsOverviewReader
{
    public function __construct(
        private PermitRevisionGuard $guard,
        private PermitRightsEvaluator $evaluator,
        private PermitRevisionRepository $revisions,
        private ClockInterface $clock,
        private ?\App\Request\Application\ProposableZones $proposable = null,
    ) {
    }

    /**
     * @throws OperationNotAllowed commune, demande ou laissez-passer inaccessible
     * @throws PermitAccessDenied  rôle absent
     */
    public function overview(User $agent, string $municipalityId, string $requestId): PermitRightsOverview
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: false);
        $now = $this->clock->now();
        $permit = $scope->permit;
        $own = null;
        foreach ($this->evaluator->evaluate($permit, $now, forVerification: true)->authorizations as $rights) {
            if ($rights->authorizationId->equals($scope->authorization->id())) {
                $own = $rights;
            }
        }
        $own ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $status = $scope->authorization->participation()->status();
        $reason = match (true) {
            $scope->authorization->isRevoked() => 'Cette autorisation est révoquée : la révocation est définitive.',
            ParticipationStatus::Active !== $status => sprintf('La participation de %s à cet événement n’est pas active : l’autorisation reste consultable, mais ne peut plus être révoquée.', $scope->authorization->municipality()->name()),
            default => '',
        };
        $request = $permit->request();
        [$extendMode, $extendBlocked, $canShorten, $shortenBlocked] = $this->periodActions($scope, $reason, $now);
        $zones = $this->zoneActions($scope, $own, $reason, $now);

        return new PermitRightsOverview(
            $permit->reference()->value,
            $request->reference()->value,
            $permit->event()->name(),
            $permit->event()->timezone()->identifier(),
            $request->firstName(),
            $request->lastName(),
            $scope->authorization->municipality()->name(),
            $own,
            '' === $reason,
            $reason,
            $this->history($permit, $scope->authorization->municipality()->id()),
            $now,
            $extendMode,
            $extendBlocked,
            $canShorten,
            $shortenBlocked,
            ...$zones,
            vehicles: $this->vehiclesPanel($scope, $reason, $now),
            qr: $this->qrPanel($scope, $reason, $now),
        );
    }

    /**
     * Actions de période possibles (indicatif : tout est relu sous verrou à l'écriture). Prolonger et reprendre exigent un événement ACTIF ; raccourcir
     * reste possible pendant une suspension (il retire des droits) mais pas sur un événement clos ni sur une période déjà terminée.
     *
     * @return array{?string, string, bool, string}
     */
    private function periodActions(Result\RevisionScope $scope, string $blocked, \DateTimeImmutable $now): array
    {
        $window = $scope->authorization->window();
        $status = $scope->permit->event()->status();
        $ended = null !== $window->until && $window->until <= $now;
        $expired = null !== $window->until && $window->until < $now;
        $extendBlocked = match (true) {
            '' !== $blocked => $blocked,
            EventStatus::Active !== $status => 'L’événement n’est pas actif : les droits ne peuvent être ni prolongés ni repris.',
            null === $window->until => 'Cette autorisation est déjà valable jusqu’à la clôture de l’événement : prolongez la fin de l’événement.',
            default => '',
        };
        $shortenBlocked = match (true) {
            '' !== $blocked => $blocked,
            EventStatus::Closed === $status => 'L’événement est clos : les droits sont déjà terminés.',
            $ended => 'Cette période est déjà terminée : il n’y a rien à raccourcir.',
            default => '',
        };

        return ['' === $extendBlocked ? ($expired ? 'resume' : 'extend') : null, $extendBlocked, '' === $shortenBlocked, $shortenBlocked];
    }

    /**
     * Zones de l'autorisation et actions possibles (indicatif ; tout est relu sous verrou à l'écriture). Ajout et réintégration exigent un événement ACTIF ; retrait et
     * correction de libellé restent possibles pendant une suspension ; le retrait exige au moins deux zones rattachées (jamais la dernière).
     *
     * @return array{zoneRows: list<ZoneRowView>, addableZones: list<ZoneOption>, reinstatableZones: list<ZoneOption>, canAddZones: bool, addZonesBlockedReason: string, canChangeZones: bool, changeZonesBlockedReason: string, canCorrectLabels: bool, correctLabelsBlockedReason: string}
     */
    private function zoneActions(Result\RevisionScope $scope, \App\Permit\Domain\AuthorizationRights $rights, string $blocked, \DateTimeImmutable $now): array
    {
        $authorization = $scope->authorization;
        $states = [];
        foreach ($rights->zones as $zone) {
            $states[$zone->zoneId->toRfc4122()] = $zone;
        }
        $rows = [];
        foreach ([...$authorization->activeZones(), ...$authorization->removedZones()] as $row) {
            $id = $row->zone()->id()->toRfc4122();
            $rows[] = new ZoneRowView($id, $row->zoneLabel(), $row->zone()->label(), ($states[$id] ?? null)?->state, $row->removedAt());
        }
        $status = $scope->permit->event()->status();
        $attached = $authorization->attachedZoneCount();
        $proposable = [];
        if (null !== $this->proposable && '' === $blocked) {
            foreach ($this->proposable->forEvent($scope->permit->event(), $now) as $commune) {
                if ($commune->participation->id()->equals($authorization->participation()->id())) {
                    foreach ($commune->zones as $zone) {
                        $proposable[$zone->id()->toRfc4122()] = $zone->label();
                    }
                }
            }
        }
        $addable = [];
        $reinstatable = [];
        $known = [];
        foreach ($rows as $row) {
            $known[$row->id] = true;
            if ($row->isRemoved() && isset($proposable[$row->id])) {
                $reinstatable[] = new ZoneOption($row->id, $proposable[$row->id]);
            }
        }
        foreach ($proposable as $id => $label) {
            if (!isset($known[$id])) {
                $addable[] = new ZoneOption($id, $label);
            }
        }
        $addBlocked = match (true) {
            '' !== $blocked => $blocked,
            EventStatus::Active !== $status => 'L’événement n’est pas actif : aucune zone ne peut être ajoutée ni réintégrée.',
            default => '',
        };
        $changeBlocked = match (true) {
            '' !== $blocked => $blocked,
            EventStatus::Closed === $status => 'L’événement est clos : les droits sont déjà terminés.',
            default => '',
        };
        $labelsBlocked = match (true) {
            '' !== $blocked => $blocked,
            EventStatus::Closed === $status => 'L’événement est clos : les droits sont déjà terminés.',
            [] === array_filter($rows, static fn (ZoneRowView $r): bool => $r->isLabelOutdated()) => 'Tous les libellés de zones sont à jour.',
            default => '',
        };

        return [
            'zoneRows' => $rows,
            'addableZones' => $addable,
            'reinstatableZones' => $reinstatable,
            'canAddZones' => '' === $addBlocked && ([] !== $addable || [] !== $reinstatable),
            'addZonesBlockedReason' => '' !== $addBlocked ? $addBlocked : (([] === $addable && [] === $reinstatable) ? 'Aucune zone proposable à ajouter ou à réintégrer.' : ''),
            'canChangeZones' => '' === $changeBlocked,
            'changeZonesBlockedReason' => $changeBlocked,
            'canCorrectLabels' => '' === $labelsBlocked,
            'correctLabelsBlockedReason' => $labelsBlocked,
        ];
    }

    /** @return list<RevisionLine> */
    private function history(\App\Permit\Domain\Permit $permit, \Symfony\Component\Uid\Uuid $ownCommune): array
    {
        $plates = $this->revisions->vehicleLines($permit->id());

        return array_map(fn (PermitRevision $r): RevisionLine => $this->line($r, $ownCommune, $plates[$r->id()->toRfc4122()] ?? null), $this->revisions->forPermit($permit->id()));
    }

    /**
     * Remplacement du QR code (LOT 05.5.5, indicatif ; tout est relu sous verrou à l'écriture) : MUNICIPALITY_ADMIN seul, autorisation non révoquée, participation active,
     * événement non clos (suspendu ou période expirée : permis).
     */
    private function qrPanel(Result\RevisionScope $scope, string $blocked, \DateTimeImmutable $now): QrPanel
    {
        $permit = $scope->permit;
        $own = $scope->authorization->municipality()->id();
        $others = [];
        foreach ($permit->authorizations() as $authorization) {
            if (!$authorization->isRevoked() && !$authorization->municipality()->id()->equals($own)) {
                $others[] = $authorization->municipality()->name();
            }
        }
        sort($others);
        $replacements = array_values(array_filter($this->revisions->forPermit($permit->id()), static fn (PermitRevision $r): bool => RevisionType::TokenReplaced === $r->type()));
        $last = [] === $replacements ? null : $replacements[0]->occurredAt();
        $reason = match (true) {
            !$scope->membership->hasRole(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin) => 'Le remplacement du QR code est réservé aux administrateurs de la commune.',
            '' !== $blocked => $blocked,
            EventStatus::Closed === $permit->event()->status() => 'L’événement est clos : le QR code ne peut plus être remplacé.',
            default => '',
        };

        return new QrPanel('' === $reason, $reason, $permit->reference()->value, array_values(array_unique($others)), \App\Permit\Domain\TokenReplacementRules::isClose($last, $now), \count($replacements));
    }

    /**
     * Plaques et actions possibles (indicatif ; tout est relu sous verrou à l'écriture). Ajout, réactivation et remplacement exigent un événement ACTIF ; le retrait reste
     * possible pendant une suspension (sauf la dernière plaque, contrôlée à l'écriture sur l'état final). Mode pied/vélo : aucune opération.
     */
    private function vehiclesPanel(Result\RevisionScope $scope, string $blocked, \DateTimeImmutable $now): VehiclesPanel
    {
        $permit = $scope->permit;
        $vehicleMode = \App\Request\Domain\TravelMode::Vehicle === $permit->request()->travelMode();
        $rows = array_map(static fn (\App\Permit\Domain\PermitVehicle $v): VehicleRowView => new VehicleRowView($v->id()->toRfc4122(), PlateFormatter::format($v), $v->removedAt()), $permit->vehicles());
        usort($rows, static fn (VehicleRowView $a, VehicleRowView $b): int => $a->isRemoved() <=> $b->isRemoved());
        $active = \count(array_filter($rows, static fn (VehicleRowView $r): bool => !$r->isRemoved()));
        // Une commune dont l'autorisation est révoquée ne voit plus les plaques communes (elle ne peut plus ni les modifier ni suivre leurs changements).
        $others = [];
        foreach ($permit->authorizations() as $authorization) {
            if (!$authorization->isRevoked() && !$authorization->municipality()->id()->equals($scope->authorization->municipality()->id())) {
                $others[] = $authorization->municipality()->name();
            }
        }
        sort($others);
        $status = $permit->event()->status();
        $changeBlocked = match (true) {
            !$vehicleMode => 'Ce laissez-passer est valable à pied ou à vélo : il n’a pas de plaque.',
            '' !== $blocked => $blocked,
            EventStatus::Closed === $status => 'L’événement est clos : les droits sont déjà terminés.',
            default => '',
        };
        $addBlocked = match (true) {
            '' !== $changeBlocked => $changeBlocked,
            EventStatus::Active !== $status => 'L’événement n’est pas actif (suspendu) : aucune plaque ne peut être ajoutée, réactivée ni remplacée. Un retrait reste possible, sauf celui de la dernière plaque.',
            default => '',
        };

        return new VehiclesPanel($vehicleMode, $vehicleMode && !$scope->authorization->isRevoked() ? $rows : [], $active, \App\Request\Domain\PermitRequest::MAX_VEHICLES, '' === $changeBlocked, $changeBlocked, '' === $addBlocked, $addBlocked, array_values(array_unique($others)));
    }

    private function line(PermitRevision $revision, \Symfony\Component\Uid\Uuid $ownCommune, ?\App\Permit\Domain\PermitRevisionVehicle $plate = null): RevisionLine
    {
        if (!$revision->municipality()->id()->equals($ownCommune)) {
            // Ligne neutre : le motif, le commentaire et l'auteur d'une autre commune ne sont jamais lus.
            return new RevisionLine(false, $revision->type()->neutralLabel(), $revision->occurredAt(), $revision->municipality()->name(), null, null, null);
        }
        $code = $revision->reasonCode();
        $reason = null === $code ? null : match ($revision->type()) {
            RevisionType::PeriodExtended, RevisionType::PeriodResumed => PeriodReason::tryFrom($code)?->label(),
            RevisionType::ZoneAdded => \App\Permit\Domain\ZoneAddReason::tryFrom($code)?->label(),
            RevisionType::ZoneRemoved => \App\Permit\Domain\ZoneRemovalReason::tryFrom($code)?->label(),
            RevisionType::ZoneReinstated => \App\Permit\Domain\ZoneReinstatementReason::tryFrom($code)?->label(),
            RevisionType::ZoneLabelCorrected => \App\Permit\Domain\LabelCorrectionReason::tryFrom($code)?->label(),
            RevisionType::PeriodShortened => ShorteningReason::tryFrom($code)?->label(),
            RevisionType::VehicleAdded => \App\Permit\Domain\VehicleAddReason::tryFrom($code)?->label(),
            RevisionType::VehicleRemoved => \App\Permit\Domain\VehicleRemovalReason::tryFrom($code)?->label(),
            RevisionType::VehicleReplacedOut, RevisionType::VehicleReplacedIn => \App\Permit\Domain\VehicleReplacementReason::tryFrom($code)?->label(),
            RevisionType::VehicleReinstated => \App\Permit\Domain\VehicleReinstatementReason::tryFrom($code)?->label(),
            RevisionType::TokenReplaced => \App\Permit\Domain\TokenReplacementReason::tryFrom($code)?->label(),
            default => RevocationReason::tryFrom($code)?->label(),
        } ?? $code;
        $actor = $revision->actor();

        return new RevisionLine(true, $revision->type()->label(), $revision->occurredAt(), $revision->municipality()->name(), $reason, $revision->reasonText(), $actor->firstName().' '.$actor->lastName(), self::period($revision), self::zone($revision), self::vehicle($revision, $plate), self::token($revision));
    }

    /**
     * Changement de période d'une révision de période (instants UTC ; l'écran les écrit dans le fuseau de l'événement).
     *
     * @return array<string, mixed>|null
     */
    private static function period(PermitRevision $revision): ?array
    {
        if (!\in_array($revision->type(), [RevisionType::PeriodExtended, RevisionType::PeriodResumed, RevisionType::PeriodShortened], true)) {
            return null;
        }
        $before = $revision->beforeState() ?? [];
        $after = $revision->afterState() ?? [];
        $at = static fn (mixed $v): ?\DateTimeImmutable => \is_string($v) ? new \DateTimeImmutable($v) : null;

        return [
            'beforeFrom' => $at($before['validFrom'] ?? null),
            'beforeUntil' => $at($before['validUntil'] ?? null),
            'afterFrom' => $at($after['validFrom'] ?? null),
            'afterUntil' => $at($after['validUntil'] ?? null),
            'immediate' => true === ($after['immediate'] ?? false),
            'requestedUntil' => $at($after['requestedUntil'] ?? null),
        ];
    }

    /**
     * Remplacement du QR d'une révision de SA commune : numéro d'ordre et indicateurs, jamais un jeton.
     *
     * @return array<string, mixed>|null
     */
    private static function token(PermitRevision $revision): ?array
    {
        if (RevisionType::TokenReplaced !== $revision->type()) {
            return null;
        }
        $after = $revision->afterState() ?? [];

        return ['sequence' => $after['sequence'] ?? null, 'close' => true === ($after['close'] ?? false), 'unusual' => true === ($after['unusual'] ?? false)];
    }

    /**
     * Plaque concernée par une révision de plaque de SA commune (la plaque vient de `permit_revision_vehicle` ; nulle une fois anonymisée).
     *
     * @return array<string, mixed>|null
     */
    private static function vehicle(PermitRevision $revision, ?\App\Permit\Domain\PermitRevisionVehicle $line): ?array
    {
        if (!$revision->type()->isVehicle()) {
            return null;
        }
        $registration = $line?->registration();

        return [
            'plate' => null === $registration ? null : PlateFormatter::formatRegistration($registration, $line->format()),
            'pair' => ($revision->afterState() ?? [])['pair'] ?? null,
        ];
    }

    /**
     * Zone concernée par une révision de zone (libellés non personnels).
     *
     * @return array<string, mixed>|null
     */
    private static function zone(PermitRevision $revision): ?array
    {
        if (!\in_array($revision->type(), [RevisionType::ZoneAdded, RevisionType::ZoneRemoved, RevisionType::ZoneReinstated, RevisionType::ZoneLabelCorrected], true)) {
            return null;
        }
        $before = $revision->beforeState() ?? [];
        $after = $revision->afterState() ?? [];

        return ['label' => \is_string($after['label'] ?? null) ? $after['label'] : ($before['label'] ?? null), 'previous' => \is_string($before['label'] ?? null) ? $before['label'] : null];
    }
}
