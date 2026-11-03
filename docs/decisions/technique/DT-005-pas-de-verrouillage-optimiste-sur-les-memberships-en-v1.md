# DT-005 — Pas de verrouillage optimiste sur les memberships en V1

- **Identifiant** : DT-005
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D6 de `docs/lot-01-schema.md`.

## Décision

Aucune colonne de version sur `MunicipalityMembership` en V1 ; la concurrence est traitée par un verrou pessimiste sur la commune.

## Conséquences fonctionnelles ou techniques

- `MembershipManager` : transaction et verrou de la commune.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
