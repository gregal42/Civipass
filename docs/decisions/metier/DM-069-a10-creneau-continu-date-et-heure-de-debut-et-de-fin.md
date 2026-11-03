# DM-069 — A10 — Créneau continu : date et heure de début et de fin

- **Identifiant** : DM-069
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-014.

## Décision

Un créneau continu possède obligatoirement une date et une heure de début et une date et une heure de fin ; la fin est strictement postérieure au début ; le créneau couvre toute la période sans interruption. Le mode « Pendant toute la durée de l'événement » reste distinct.

## Conséquences fonctionnelles ou techniques

- Colonnes `startDate`, `startTime`, `endDate`, `endTime` obligatoires pour `CONTINUOUS`.
- Comparaison sur les instants résolus dans le fuseau de l'événement.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point A10 levé.
