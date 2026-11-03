# DM-015 — Créneau ponctuel limité à une journée

- **Identifiant** : DM-015
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Précision apportée par le commanditaire sur le type « ponctuel » (questions Q1 de UI-02 : « accès ponctuel : un jour avec heures, ou un instant ? »).

## Décision

Un créneau ponctuel correspond à une date et à une heure de début et de fin ; il est limité à une journée.

## Conséquences fonctionnelles ou techniques

- Un créneau ponctuel porte une seule date : pas de date de fin.
- Début strictement antérieur à la fin, même date : un créneau ponctuel ne franchit pas minuit (le franchissement de minuit est réservé aux récurrences, DM-017). Précisé par le commanditaire lors de la consolidation du LOT 02.

## Documents concernés

- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§8 Q1)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Tranche la question Q1 de UI-02. L'audit TECH-02.1 contient, dans son corps d'origine, des formulations sur le passage de minuit qu'il convient de lire avec cet ajustement : voir l'addendum de l'audit.
