# DM-034 — Commune SUSPENDED : ni création ni nouvelle participation, capacités opérationnelles suspendues

- **Identifiant** : DM-034
- **Thème** : Communes
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrage 4 de la validation du schéma LOT 01 (D16).

## Décision

Une commune SUSPENDED ne peut pas créer d'événement ni rejoindre un nouvel événement. Ses capacités opérationnelles sur les événements existants sont suspendues, sans suppression de données ni d'historique.

## Conséquences fonctionnelles ou techniques

- `MembershipAccessPolicy` : lecture seule pour une commune SUSPENDED ; écriture refusée.
- `EventCreationPolicy::canJoinNewEvent`.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§13)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
