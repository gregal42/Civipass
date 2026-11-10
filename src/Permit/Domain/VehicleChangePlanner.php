<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventStatus;
use App\Request\Domain\LicensePlate;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Règles d'ajout, de retrait, de réactivation et de remplacement des plaques d'un laissez-passer (LOT 05.5.4, DM-119 §4.4, arbitrages du 10 novembre 2026). Pure :
 * l'état est fourni (lignes de plaques relues sous verrou), le plan ou un refus motivé en français sort ; elle sert à l'aperçu comme à l'écriture.
 *
 *  - Mode pied/vélo : toute opération est refusée (refus uniforme, comme une ressource inaccessible). Autorisation révoquée : tout refusé ; événement clos : tout refusé.
 *  - AJOUT, RÉACTIVATION et REMPLACEMENT (qui introduisent une plaque) : interdits tant que l'événement n'est pas ACTIF (suspendu) ; RETRAIT : permis, sauf dernière
 *    plaque. Une opération groupée qui contient une action interdite est refusée INTÉGRALEMENT.
 *  - Plaques normalisées et valides (`LicensePlate`) ; jamais deux fois la même plaque ACTIVE dans le laissez-passer ; une plaque déjà retirée se RÉACTIVE (la ligne est
 *    réutilisée : unicité `(permit, plaque)`), elle ne s'ajoute pas.
 *  - L'ÉTAT FINAL est contrôlé : de 1 à `$max` plaques actives, quel que soit le nombre d'opérations groupées (retirer la dernière plaque est interdit ; la REMPLACER est
 *    permis). Aucun effet partiel : un refus annule tout.
 *  - Le même numéro de plaque reste libre sur d'autres laissez-passer : aucune règle globale (donc aucune fuite d'existence).
 */
final readonly class VehicleChangePlanner
{
    /**
     * @param list<VehicleRowState>                     $rows         plaques actuelles du laissez-passer (actives et retirées)
     * @param list<LicensePlate>                        $adds
     * @param list<Uuid>                                $removes
     * @param list<Uuid>                                $reinstates
     * @param list<array{from: Uuid, to: LicensePlate}> $replacements
     *
     * @throws OperationNotAllowed mode pied/vélo
     * @throws DomainRuleViolation refus motivé
     */
    public function change(array $rows, array $adds, array $removes, array $reinstates, array $replacements, TravelMode $travelMode, bool $revoked, EventStatus $eventStatus, int $max): VehiclePlan
    {
        if (TravelMode::Vehicle !== $travelMode) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : plus aucune plaque ne peut être modifiée.');
        }
        if (EventStatus::Closed === $eventStatus) {
            throw new DomainRuleViolation('L\'événement est clos : les droits sont déjà terminés.');
        }
        if ([] === $adds && [] === $removes && [] === $reinstates && [] === $replacements) {
            throw new DomainRuleViolation('Choisissez au moins une plaque à ajouter, à retirer, à réactiver ou à remplacer.');
        }
        if ((0 < \count($adds) || 0 < \count($reinstates) || 0 < \count($replacements)) && EventStatus::Active !== $eventStatus) {
            throw new DomainRuleViolation('L\'événement n\'est pas actif (suspendu) : aucune plaque ne peut être ajoutée, réactivée ni remplacée tant qu\'il ne l\'est pas redevenu. Un retrait reste possible, sauf celui de la dernière plaque.');
        }
        $byId = [];
        $byRegistration = [];
        foreach ($rows as $row) {
            $byId[$row->vehicleId->toRfc4122()] = $row;
            $byRegistration[$row->registration] = $row;
        }
        $seenRows = [];
        foreach ([...$removes, ...$reinstates, ...array_map(static fn (array $r): Uuid => $r['from'], $replacements)] as $id) {
            if (isset($seenRows[$id->toRfc4122()])) {
                throw new DomainRuleViolation('Une même plaque ne peut figurer qu\'une fois dans une opération.');
            }
            $seenRows[$id->toRfc4122()] = true;
        }
        $active = array_map(static fn (VehicleRowState $r): bool => !$r->removed, $byId);
        $changes = [];
        $next = [] === $rows ? 1 : max(array_map(static fn (VehicleRowState $r): int => $r->position, $rows)) + 1;
        $newPlates = [];

        $row = static fn (Uuid $id): VehicleRowState => $byId[$id->toRfc4122()] ?? throw new DomainRuleViolation('Cette plaque ne figure pas dans le laissez-passer.');
        $newPlate = static function (LicensePlate $plate) use (&$newPlates, $byRegistration): void {
            if (isset($newPlates[$plate->value])) {
                throw new DomainRuleViolation('Une même plaque ne peut figurer qu\'une fois dans une opération.');
            }
            $newPlates[$plate->value] = true;
            $existing = $byRegistration[$plate->value] ?? null;
            if (null !== $existing) {
                throw new DomainRuleViolation($existing->removed ? 'Cette plaque a déjà été retirée : réactivez-la plutôt que de l\'ajouter.' : 'Cette plaque figure déjà dans le laissez-passer.');
            }
        };

        foreach ($removes as $id) {
            $state = $row($id);
            if ($state->removed) {
                throw new DomainRuleViolation('Cette plaque est déjà retirée.');
            }
            $active[$id->toRfc4122()] = false;
            $changes[] = new VehicleChange(VehicleChangeKind::Removed, $id, $state->registration, $state->format, $state->position);
        }
        foreach ($replacements as $pair => $replacement) {
            $state = $row($replacement['from']);
            if ($state->removed) {
                throw new DomainRuleViolation('Cette plaque est déjà retirée : elle ne peut pas être remplacée.');
            }
            if ($state->registration === $replacement['to']->value) {
                throw new DomainRuleViolation('La nouvelle plaque est identique à celle qu\'elle remplace.');
            }
            $newPlate($replacement['to']);
            $active[$replacement['from']->toRfc4122()] = false;
            $changes[] = new VehicleChange(VehicleChangeKind::ReplacedOut, $replacement['from'], $state->registration, $state->format, $state->position, $pair);
            $changes[] = new VehicleChange(VehicleChangeKind::ReplacedIn, null, $replacement['to']->value, $replacement['to']->format, $next, $pair);
            $active['new:'.$replacement['to']->value] = true;
            ++$next;
        }
        foreach ($reinstates as $id) {
            $state = $row($id);
            if (!$state->removed) {
                throw new DomainRuleViolation('Cette plaque n\'est pas retirée : il n\'y a rien à réactiver.');
            }
            $active[$id->toRfc4122()] = true;
            $changes[] = new VehicleChange(VehicleChangeKind::Reinstated, $id, $state->registration, $state->format, $state->position);
        }
        foreach ($adds as $plate) {
            $newPlate($plate);
            $active['new:'.$plate->value] = true;
            $changes[] = new VehicleChange(VehicleChangeKind::Added, null, $plate->value, $plate->format, $next);
            ++$next;
        }

        $count = \count(array_filter($active));
        if (0 === $count) {
            throw new DomainRuleViolation('Un laissez-passer en mode véhicule doit garder au moins une plaque : remplacez-la par une autre plaque, ou révoquez l\'autorisation.');
        }
        if ($count > $max) {
            throw new DomainRuleViolation(sprintf('Un laissez-passer ne peut pas avoir plus de %d plaques actives.', $max));
        }
        $order = [VehicleChangeKind::Removed->value => 0, VehicleChangeKind::ReplacedOut->value => 1, VehicleChangeKind::ReplacedIn->value => 2, VehicleChangeKind::Reinstated->value => 3, VehicleChangeKind::Added->value => 4];
        usort($changes, static fn (VehicleChange $a, VehicleChange $b): int => [$order[$a->kind->value], $a->position] <=> [$order[$b->kind->value], $b->position]);

        return new VehiclePlan($changes, $count);
    }
}
