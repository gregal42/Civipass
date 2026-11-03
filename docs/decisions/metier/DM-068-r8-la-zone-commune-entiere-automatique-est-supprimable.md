# DM-068 — R8 — La zone « Commune entière » automatique est supprimable

- **Identifiant** : DM-068
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-058 (proposition automatique).

## Décision

La zone « Commune entière » proposée automatiquement peut être supprimée (suppression logique, `deletedAt`, DM-052). Elle n'est pas recréée automatiquement. Une nouvelle « Commune entière » peut être créée manuellement ensuite.

## Conséquences fonctionnelles ou techniques

- Invariant « une seule Commune entière non supprimée par participation » (contrôle domaine).
- Le jeton d'automatisme (« déjà proposée ») est porté par l'événement de création de la participation, pas par un test d'absence de zone.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point R8 levé.
