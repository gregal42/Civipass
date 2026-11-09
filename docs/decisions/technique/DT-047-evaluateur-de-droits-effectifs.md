# DT-047 — Évaluateur de droits effectifs (PermitRightsEvaluator)

- **Identifiant** : DT-047
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-112, DM-035, DM-109, DT-042, DT-020 (calculateurs de validité).

## Décision

1. **Un seul moteur.** `ZoneEffectivePeriodsResolver` extrait **sans changement de comportement** le calcul privé de `MunicipalDecisionApplier::zonePeriods()` ; l'applier et `PermitRightsEvaluator` l'utilisent tous deux, avec `GrantWindowCalculator::effectivePeriods()`.
2. **Entrées** : l'instant évalué (UTC, horloge serveur) ; **sortie** structurée par autorisation puis par zone (états : `VALID`, `NOT_YET_VALID`, `EXPIRED`, `OUTSIDE_PERIOD`, `ZONE_SUSPENDED`, `PARTICIPATION_INACTIVE`, `COMMUNE_INACTIVE`, `EVENT_NOT_ACTIVE`, `EVENT_CLOSED`, `REVOKED`, `ZONE_NOT_AUTHORIZED`), avec le début et la fin de la période courante ou prochaine, et le fuseau de l'événement pour la présentation.
3. **Ordre de précédence des causes** : révoquée, commune inactive, événement clôturé puis non actif, participation inactive, zone suspendue, puis l'état temporel (pas encore valide, hors période, expiré).
4. **Différence assumée avec la décision** : la décision exige une intersection non vide à l'instant de la décision ; le contrôle évalue l'état **courant** (une zone suspendue ou une commune devenue inactive après la décision n'ouvre plus de droit). Le statut administratif de la commune, absent de `effectivePeriods`, est évalué ici (DM-035).
5. Aucun statut de validité n'est stocké ; aucune requête n'écrit.

## Conséquences fonctionnelles ou techniques

- Les droits d'une zone dépendent de la fenêtre courante de l'autorisation (prolongations au 05.5).

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
