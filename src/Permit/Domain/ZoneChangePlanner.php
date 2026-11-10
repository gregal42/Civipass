<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventStatus;
use App\Shared\Domain\DomainRuleViolation;
use Symfony\Component\Uid\Uuid;

/**
 * Règles d'ajout, de retrait, de réintégration et de correction de libellé des zones d'une autorisation (LOT 05.5.3, DM-119 §4.3, arbitrages du 10 novembre 2026).
 * Pure : l'état est fourni (lignes de l'autorisation, zones relues sous verrou), le plan ou un refus motivé en français sort ; elle sert à l'aperçu comme à l'écriture.
 *
 *  - Une autorisation révoquée refuse tout ; un événement clos refuse tout.
 *  - AJOUT et RÉINTÉGRATION (qui accroissent les droits) : interdits tant que l'événement n'est pas ACTIF (suspendu) ; la zone doit appartenir à la participation de la
 *    commune, être proposable (DM-104) et, pour un ajout, ne figurer nulle part dans l'autorisation (une zone retirée se réintègre).
 *  - RETRAIT (qui retire des droits) et CORRECTION de libellé : permis pendant une suspension.
 *  - Une zone SUSPENDUE reste rattachée : elle compte pour la règle de la dernière zone, sans donner de droit utilisable.
 *  - L'ÉTAT FINAL est contrôlé : au moins une zone rattachée non retirée, quel que soit le nombre d'opérations groupées. Aucun effet partiel : un refus annule tout.
 *  - La correction de libellé SYNCHRONISE avec le libellé courant de la même zone : jamais de texte libre.
 */
final readonly class ZoneChangePlanner
{
    /**
     * @param list<ZoneRowState>           $rows       zones actuelles de l'autorisation (actives et retirées)
     * @param list<Uuid>                   $adds
     * @param list<Uuid>                   $removes
     * @param list<Uuid>                   $reinstates
     * @param array<string, ZoneCandidate> $candidates zones visées par un ajout ou une réintégration, par identifiant RFC 4122
     *
     * @throws DomainRuleViolation refus motivé
     */
    public function change(array $rows, array $adds, array $removes, array $reinstates, array $candidates, bool $revoked, EventStatus $eventStatus): ZonePlan
    {
        self::assertOpen($revoked, $eventStatus);
        if ([] === $adds && [] === $removes && [] === $reinstates) {
            throw new DomainRuleViolation('Choisissez au moins une zone à ajouter, à retirer ou à réintégrer.');
        }
        if ([] !== [...$adds, ...$reinstates] && EventStatus::Active !== $eventStatus) {
            throw new DomainRuleViolation('L\'événement n\'est pas actif (suspendu) : aucune zone ne peut être ajoutée ni réintégrée tant qu\'il ne l\'est pas redevenu. Un retrait reste possible.');
        }
        $seen = [];
        foreach ([...$adds, ...$removes, ...$reinstates] as $id) {
            if (isset($seen[$id->toRfc4122()])) {
                throw new DomainRuleViolation('Une même zone ne peut figurer qu\'une fois dans une opération.');
            }
            $seen[$id->toRfc4122()] = true;
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->zoneId->toRfc4122()] = $row;
        }
        $changes = [];
        $attached = array_map(static fn (ZoneRowState $r): bool => !$r->removed, $byId);

        foreach ($removes as $id) {
            $row = $byId[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.');
            if ($row->removed) {
                throw new DomainRuleViolation(sprintf('La zone « %s » est déjà retirée.', $row->label));
            }
            $attached[$id->toRfc4122()] = false;
            $changes[] = new ZoneChange(ZoneChangeKind::Removed, $id, $row->label);
        }
        foreach ($reinstates as $id) {
            $row = $byId[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.');
            if (!$row->removed) {
                throw new DomainRuleViolation(sprintf('La zone « %s » n\'est pas retirée : il n\'y a rien à réintégrer.', $row->label));
            }
            $candidate = self::usable($candidates, $id);
            $attached[$id->toRfc4122()] = true;
            $changes[] = new ZoneChange(ZoneChangeKind::Reinstated, $id, $candidate->currentLabel, $row->label);
        }
        foreach ($adds as $id) {
            if (isset($byId[$id->toRfc4122()])) {
                throw new DomainRuleViolation($byId[$id->toRfc4122()]->removed ? sprintf('La zone « %s » a déjà été retirée : réintégrez-la plutôt que de l\'ajouter.', $byId[$id->toRfc4122()]->label) : sprintf('La zone « %s » figure déjà dans l\'autorisation.', $byId[$id->toRfc4122()]->label));
            }
            $candidate = self::usable($candidates, $id);
            $attached[$id->toRfc4122()] = true;
            $changes[] = new ZoneChange(ZoneChangeKind::Added, $id, $candidate->currentLabel);
        }
        $active = array_keys(array_filter($attached));
        if ([] === $active) {
            throw new DomainRuleViolation('Une autorisation doit garder au moins une zone : pour retirer toutes les zones, révoquez l\'autorisation.');
        }
        $order = [ZoneChangeKind::Removed->value => 0, ZoneChangeKind::Reinstated->value => 1, ZoneChangeKind::Added->value => 2, ZoneChangeKind::LabelCorrected->value => 3];
        usort($changes, static fn (ZoneChange $a, ZoneChange $b): int => [$order[$a->kind->value], $a->label, $a->zoneId->toRfc4122()] <=> [$order[$b->kind->value], $b->label, $b->zoneId->toRfc4122()]);
        sort($active);

        return new ZonePlan($changes, \count($active), $active);
    }

    /**
     * Correction de libellé par SYNCHRONISATION.
     *
     * @param list<ZoneRowState>           $rows
     * @param list<Uuid>                   $zoneIds
     * @param array<string, ZoneCandidate> $candidates zones de l'événement relues sous verrou
     *
     * @return list<ZoneChange> un changement par zone (`label` = nouveau libellé, `previousLabel` = ancien)
     *
     * @throws DomainRuleViolation refus motivé
     */
    public function relabel(array $rows, array $zoneIds, array $candidates, bool $revoked, EventStatus $eventStatus): array
    {
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : plus aucune zone ne peut être modifiée.');
        }
        if (EventStatus::Closed === $eventStatus) {
            throw new DomainRuleViolation('L\'événement est clos : les droits sont déjà terminés.');
        }
        if ([] === $zoneIds) {
            throw new DomainRuleViolation('Choisissez au moins une zone dont corriger le libellé.');
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->zoneId->toRfc4122()] = $row;
        }
        $changes = [];
        $seen = [];
        foreach ($zoneIds as $id) {
            if (isset($seen[$id->toRfc4122()])) {
                throw new DomainRuleViolation('Une même zone ne peut figurer qu\'une fois dans une opération.');
            }
            $seen[$id->toRfc4122()] = true;
            $row = $byId[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette zone ne figure pas dans l\'autorisation.');
            $candidate = $candidates[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette zone est introuvable.');
            if ($row->label === $candidate->currentLabel) {
                throw new DomainRuleViolation(sprintf('Le libellé de la zone « %s » est déjà à jour.', $row->label));
            }
            $changes[] = new ZoneChange(ZoneChangeKind::LabelCorrected, $id, $candidate->currentLabel, $row->label);
        }

        return $changes;
    }

    /**
     * @param array<string, ZoneCandidate> $candidates
     *
     * @throws DomainRuleViolation zone introuvable, d'une autre commune ou non proposable
     */
    private static function usable(array $candidates, Uuid $id): ZoneCandidate
    {
        $candidate = $candidates[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette zone est introuvable.');
        if (!$candidate->ownParticipation) {
            throw new DomainRuleViolation('Cette zone n\'appartient pas à votre commune.');
        }
        if (!$candidate->proposable) {
            throw new DomainRuleViolation(sprintf('La zone « %s » n\'est pas ouverte aux autorisations pour le moment (non confirmée, suspendue, supprimée ou sans créneau utilisable).', $candidate->currentLabel));
        }

        return $candidate;
    }

    private static function assertOpen(bool $revoked, EventStatus $eventStatus): void
    {
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : plus aucune zone ne peut être modifiée.');
        }
        if (EventStatus::Closed === $eventStatus) {
            throw new DomainRuleViolation('L\'événement est clos : les droits sont déjà terminés.');
        }
    }
}
