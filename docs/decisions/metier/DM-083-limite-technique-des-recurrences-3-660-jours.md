# DM-083 — Limite technique des récurrences : 3 660 jours

- **Identifiant** : DM-083
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Garde ajoutée à l'étape 1 (`ValiditySlot::MAX_RECURRENCE_DAYS`).

## Décision

La limite technique de 3 660 jours entre la date de début et la date de fin d'une récurrence est validée. Elle protège le moteur contre les calculs excessifs.

## Conséquences fonctionnelles ou techniques

- Constante documentée et testée aux bornes (3 660 accepté, 3 661 refusé).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Lève la réserve de l'étape 1.
