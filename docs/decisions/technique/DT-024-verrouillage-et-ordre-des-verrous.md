# DT-024 — Verrouillage et ordre des verrous

- **Identifiant** : DT-024
- **Thème** : Transactions
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

Cohérence des gardes d'activation et de clôture sous concurrence.

## Décision

Verrou pessimiste : ligne du compteur de référence (création), ligne d'événement (activer, suspendre, clôturer), ligne de participation (accepter, opérations sur zones et validités). Ordre : compteur → événement → participations → zones. Un seul `TransactionRunner` par cas d'usage.

## Conséquences fonctionnelles ou techniques

- Tests de concurrence.

## Documents concernés

- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
