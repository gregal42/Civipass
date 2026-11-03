# DT-003 — Collation binaire pour les identifiants normalisés

- **Identifiant** : DT-003
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D8 de `docs/lot-01-schema.md` : la collation par défaut (insensible à la casse et aux accents) est inadaptée à l'unicité.

## Décision

Les colonnes d'email et de code INSEE utilisent la collation `utf8mb4_bin`, avec normalisation préalable.

## Conséquences fonctionnelles ou techniques

- Recherche exigeant la valeur normalisée.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
