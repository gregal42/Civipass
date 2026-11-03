# DT-001 — Identifiants UUID v7 générés côté application, stockés en BINARY(16)

- **Identifiant** : DT-001
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

STD §0 et §12 laissaient le type de clé primaire « à arbitrer » (BIGINT ou ULID).

## Décision

Les identifiants sont des UUID v7 générés par l'application et stockés en `BINARY(16)` (type Doctrine `uuid`, paquet `symfony/uid`).

## Conséquences fonctionnelles ou techniques

- Toutes les entités héritent de cette convention.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§2)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§12)

## Écarts documentaires signalés

- STD §12 : « Type PK » ouvert : tranché par cette décision.
