# DT-009 — Base de test dédiée et provisionnement reproductible

- **Identifiant** : DT-009
- **Thème** : Tests
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D14 de `docs/lot-01-schema.md`.

## Décision

Les tests destructifs ne tournent que sur une base `*_test` dédiée, provisionnée par `make test-db` ; jamais de migration ni de test destructif sur la base de développement.

## Conséquences fonctionnelles ou techniques

- `TestDatabase` refuse toute base hors `_test`.

## Documents concernés

- [testing.md](../../testing.md)
- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
