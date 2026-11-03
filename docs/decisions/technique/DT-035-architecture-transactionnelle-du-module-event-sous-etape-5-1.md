# DT-035 — Architecture transactionnelle du module Event (sous-étape 5.1)

- **Identifiant** : DT-035
- **Thème** : Transactions
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Étape 5 du plan TECH-02.1 ; l'analyse a établi sur MySQL qu'un `refresh()` simple après un verrou, en REPEATABLE READ, peut lire un état périmé si une lecture simple l'a précédé dans la transaction.

## Décision

Cinq services de cas d'usage (création, cycle de vie de l'événement, participations, zones, opérations groupées) et deux collaborateurs internes (verrous ordonnés, audit). Gabarit : pré-autorisation hors transaction, verrous en première instruction avec rafraîchissement verrouillant, ré-autorisation et préconditions sous verrou, validation avant modification, audit dans la même transaction, résultats immuables. Verrous : événement (S pour participations et zones, X pour modification, activation, clôture, invitation) → participations X (identifiant croissant) → zones ; compteur en dernier, création seulement. Aucune transaction imbriquée, aucune nouvelle tentative automatique ; conflits techniques traduits en `OperationConflict`. Détail : `docs/lots/tech-02.1/step-5-transactional-services-plan.md`.

## Conséquences fonctionnelles ou techniques

- Correction proposée des méthodes `lock()` : `refresh($entity, LockMode::PESSIMISTIC_WRITE)` (sous-étape 5.2).
- Révise DT-024 : compteur en dernier (arbitrage D2).
- Arbitrages D1 à D8, U5 et U6 : voir le plan (§15).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
