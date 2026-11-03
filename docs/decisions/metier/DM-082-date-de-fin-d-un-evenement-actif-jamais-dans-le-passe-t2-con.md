# DM-082 — Date de fin d'un événement actif : jamais dans le passé (T2 confirmée)

- **Identifiant** : DM-082
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Défaut T2 du schéma, confirmé.

## Décision

Une modification de `endsAt` sur un événement actif ne peut pas positionner la fin dans le passé. Pour terminer immédiatement un événement, utiliser la clôture.

## Conséquences fonctionnelles ou techniques

- `Event::reschedule()` refuse une fin postérieure à l'instant courant non respectée après activation (message : « clôturez l'événement »).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Point T2 levé.
