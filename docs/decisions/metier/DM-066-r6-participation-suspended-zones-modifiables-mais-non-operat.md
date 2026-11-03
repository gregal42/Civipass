# DM-066 — R6 — Participation SUSPENDED : zones modifiables mais non opérationnelles

- **Identifiant** : DM-066
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-056 (A6.2) ; DM-058 (A8).

## Décision

Une commune dont la participation est `SUSPENDED` peut continuer à créer et modifier ses zones, configurer leurs validités, confirmer ses zones, activer ou suspendre ses zones. Aucune zone ne devient opérationnelle tant que la participation reste suspendue.

## Conséquences fonctionnelles ou techniques

- Garde d'écriture sur les zones : participation `ACTIVE` ou `SUSPENDED` (jamais `INVITED` ni `CLOSED`).
- Garde d'exploitation : participation `ACTIVE` obligatoire.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point R6 levé. À rapprocher de la commune administrativement suspendue (DM-034, DM-070).
