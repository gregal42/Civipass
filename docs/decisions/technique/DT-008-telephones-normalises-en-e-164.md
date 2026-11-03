# DT-008 — Téléphones normalisés en E.164

- **Identifiant** : DT-008
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D11 et D13 de `docs/lot-01-schema.md`.

## Décision

Les numéros de téléphone sont validés et stockés au format E.164 (bibliothèque `giggsey/libphonenumber-for-php-lite`).

## Conséquences fonctionnelles ou techniques

- `PhoneNumber`, `LibPhoneNumberNormalizer`.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
