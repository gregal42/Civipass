# DT-002 — Rôles communaux en JSON validés côté domaine, séparés des rôles Symfony

- **Identifiant** : DT-002
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrage de schéma du LOT 01.

## Décision

Les rôles communaux sont cumulables, stockés en JSON et validés par le domaine. Ils ne figurent jamais dans `User::getRoles()` (séparation entre rôles globaux Symfony et rôles communaux).

## Conséquences fonctionnelles ou techniques

- `MunicipalityMembership.roles`, `MunicipalityRole`.
- Évaluation par les politiques d'accès, jamais par rôles Symfony.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§5, §7)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
