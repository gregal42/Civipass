# DM-035 — Autorisations d'une commune SUSPENDED ou INACTIVE inutilisables au contrôle

- **Identifiant** : DM-035
- **Thème** : Communes
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Décision D17 de `docs/lot-01-schema.md`.

## Décision

Une commune administrativement SUSPENDED ou INACTIVE ne permet pas l'utilisation de ses autorisations au contrôle. L'invalidité est temporaire pour SUSPENDED, sans révocation définitive ; une commune réactivée retrouve ses autorisations encore valides.

## Conséquences fonctionnelles ou techniques

- À implémenter avec le module Permit/Control (pas au LOT 01 ni au LOT 02).
- Le statut communal est évalué au moment du contrôle et jamais copié dans l'autorisation.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§13)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
