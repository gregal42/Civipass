<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Read\NotificationFacts;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RightState;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture, SANS verrou, de l'état courant d'un laissez-passer pour composer une notification (LOT 05.3.3, DM-117). Appelée par le distributeur
 * juste avant l'envoi : le message décrit les droits d'AUJOURD'HUI, jamais ceux d'un instantané ancien. Aucune écriture.
 */
final readonly class NotificationFactsReader
{
    /** Zones sans droit pour le bénéficiaire : elles n'apparaissent pas dans un message qui annonce des droits. */
    private const HIDDEN = [RightState::Revoked, RightState::ZoneNotAuthorized];

    public function __construct(
        private PermitRepository $permits,
        private PermitRightsEvaluator $evaluator,
        private SystemAccessLinks $links,
        private ?PermitRevisionRepository $revisions = null,
    ) {
    }

    /**
     * @param Uuid|null $authorizationId autorisation concernée par un message d'extension : `authorizationActive` dit si elle donne encore des droits
     */
    public function read(Uuid $permitId, \DateTimeImmutable $now, ?Uuid $authorizationId = null, ?Uuid $revisionId = null): ?NotificationFacts
    {
        $permit = $this->permits->find($permitId);
        if (null === $permit) {
            return null;
        }
        $communes = [];
        $listed = [];
        foreach ($this->evaluator->evaluate($permit, $now)->authorizations as $authorization) {
            $zones = [];
            foreach ($authorization->zones as $zone) {
                if (!\in_array($zone->state, self::HIDDEN, true)) {
                    $zones[] = $zone->label;
                }
            }
            if ([] !== $zones) {
                $communes[] = ['municipality' => $authorization->municipalityName, 'zones' => $zones];
                $listed[] = $authorization->authorizationId->toRfc4122();
            }
        }
        $request = $permit->request();
        $decided = null;
        $batchId = null;
        $batch = [];
        $revisions = null === $revisionId || null === $this->revisions ? [] : $this->revisions->forPermit($permitId);
        foreach ($revisions as $revision) {
            if ($revision->id()->equals($revisionId)) {
                $decided = $revision->afterState();
                $batchId = $revision->batchId();
            }
        }
        // Zones (LOT 05.5.3) : toutes les révisions du MÊME LOT (une opération) décrivent le message ; libellés de zones seulement, jamais un motif.
        foreach (null === $batchId ? [] : $revisions as $revision) {
            if ($revision->batchId()->equals($batchId)) {
                $batch[] = $revision;
            }
        }
        $target = null;
        foreach ($permit->authorizations() as $candidate) {
            if (null !== $authorizationId && $candidate->id()->equals($authorizationId)) {
                $target = $candidate;
            }
        }

        return new NotificationFacts(
            $this->links->isUsable($permitId, $now),
            $request->firstName(),
            $permit->reference()->value,
            $permit->event()->name(),
            $communes,
            $listed,
            null === $authorizationId || \in_array($authorizationId->toRfc4122(), $listed, true),
            $request->email(),
            $target?->isRevoked() ?? false,
            $target?->municipality()->name(),
            $target?->window()->from,
            $target?->window()->until,
            $permit->event()->timezone()->identifier(),
            null !== $decided && \is_string($decided['validFrom'] ?? null) ? new \DateTimeImmutable($decided['validFrom']) : null,
            null !== $decided && \is_string($decided['validUntil'] ?? null) ? new \DateTimeImmutable($decided['validUntil']) : null,
            null !== $decided && isset($decided['validFrom']),
            self::labels($batch, \App\Permit\Domain\RevisionType::ZoneAdded),
            self::labels($batch, \App\Permit\Domain\RevisionType::ZoneRemoved),
            self::labels($batch, \App\Permit\Domain\RevisionType::ZoneReinstated),
            self::zonesChanged($batch, $target),
        );
    }

    /**
     * Libellés (non personnels) des zones d'un type de révision dans un lot, par ordre alphabétique.
     *
     * @param list<\App\Permit\Domain\PermitRevision> $batch
     *
     * @return list<string>
     */
    private static function labels(array $batch, \App\Permit\Domain\RevisionType $type): array
    {
        $labels = [];
        foreach ($batch as $revision) {
            if ($revision->type() === $type) {
                $state = 'ZONE_REMOVED' === $type->value ? ($revision->beforeState() ?? []) : ($revision->afterState() ?? []);
                $label = $state['label'] ?? null;
                if (\is_string($label) && '' !== $label) {
                    $labels[] = $label;
                }
            }
        }
        sort($labels);

        return $labels;
    }

    /**
     * Les zones rattachées ont-elles CHANGÉ entre la fin de l'opération annoncée et l'envoi ? (comparaison des identifiants enregistrés par la révision et des zones
     * rattachées d'aujourd'hui) ; faux sans lot de zones.
     *
     * @param list<\App\Permit\Domain\PermitRevision> $batch
     */
    private static function zonesChanged(array $batch, ?\App\Permit\Domain\PermitAuthorization $target): bool
    {
        $recorded = null;
        foreach ($batch as $revision) {
            $attached = ($revision->afterState() ?? [])['attachedZones'] ?? null;
            if (\is_array($attached)) {
                $recorded = array_map('strval', $attached);
                break;
            }
        }
        if (null === $recorded || null === $target) {
            return false;
        }
        $now = array_map(static fn ($z): string => $z->zone()->id()->toRfc4122(), $target->activeZones());
        sort($recorded);
        sort($now);

        return $recorded !== $now;
    }
}
