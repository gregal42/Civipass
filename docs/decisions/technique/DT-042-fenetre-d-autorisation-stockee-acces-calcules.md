# DT-042 — Fenêtre d'autorisation stockée, accès effectifs calculés

- **Identifiant** : DT-042
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-109, DT-004, DT-020, DT-021.

## Décision

La décision stocke la période **globale** (`duration_mode`, `valid_from`, `valid_until` nullable), en UTC à la seconde (l'instant de décision est ramené aux secondes entières). Les accès par zone ne sont jamais stockés : `GrantWindowCalculator::effectivePeriods()` les calcule (fenêtre ∩ événement ∩ `ZoneValidityCalculator` ∩ participation). `UNTIL_CLOSURE` ne copie jamais la fin de l'événement. Calcul pur, sans dépendance à l'infrastructure. À l'approbation, `MunicipalDecisionApplier` calcule la fenêtre puis exige, pour **chaque** zone, une intersection non vide entre cette fenêtre et ses périodes effectives (sous les verrous de la transaction, avant toute mutation).

## Conséquences fonctionnelles ou techniques

- Le LOT 05 copiera les zones et plaques au moment de l'émission, pas le LOT 04.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
