# DT-004 — Dates en UTC avec type Doctrine surchargé

- **Identifiant** : DT-004
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

PHP tourne en Europe/Paris et MySQL `DATETIME` n'a pas de fuseau.

## Décision

Toutes les dates sont stockées et relues en UTC (type `datetime_immutable` surchargé, `UtcDateTimeImmutableType`).

## Conséquences fonctionnelles ou techniques

- Les créneaux horaires locaux du LOT 02 ne doivent pas utiliser ce type (recommandation de l'audit TECH-02.1).

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§3)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
