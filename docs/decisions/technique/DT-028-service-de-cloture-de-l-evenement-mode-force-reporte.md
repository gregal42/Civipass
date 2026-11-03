# DT-028 — Service de clôture de l'événement : mode forcé reporté

- **Identifiant** : DT-028
- **Thème** : Transactions
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-056, DM-065.

## Décision

`EventClosureService` ne livre que le mode normal (A6.3) dans TECH-02.1. Le mode forcé (R5) est reporté au lot Administration CWS ; la conception laisse la place à un mode forcé (aucun changement de schéma requis : audit `operator_id`/`batch_id`).

## Conséquences fonctionnelles ou techniques

- Pas d'action Sonata ni de permission `CWS_FORCE_CLOSE_EVENT` persistée dans TECH-02.1.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
