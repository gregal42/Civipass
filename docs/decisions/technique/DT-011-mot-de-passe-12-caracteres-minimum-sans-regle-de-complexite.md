# DT-011 — Mot de passe : 12 caractères minimum sans règle de complexité

- **Identifiant** : DT-011
- **Thème** : Authentification
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D4 de `docs/lot-01-schema.md`.

## Décision

Politique de mot de passe : 12 caractères minimum, maximum 4096 octets, aucune règle de complexité.

## Conséquences fonctionnelles ou techniques

- `PlainPasswordPolicy`.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)
- [authentication.md](../../authentication.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
