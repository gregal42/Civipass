# DM-016 — Récurrences : jours sélectionnés et période de validité

- **Identifiant** : DM-016
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

CDC §5.2 : récurrence journalière ou hebdomadaire. UI-02 Q2 : bornes de dates à confirmer.

## Décision

Une récurrence possède une date de début, une date de fin, des jours sélectionnés, une heure de début et une heure de fin.

## Conséquences fonctionnelles ou techniques

- Colonnes : date de début et date de fin **obligatoires**, jours (1 à 7), heure de début, heure de fin.
- Rattachement d'une occurrence à son jour de début : règle d'interprétation de l'agent, à valider avec le calcul de validité.

## Documents concernés

- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§8 Q2)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.2)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Tranche la question Q2 de UI-02 (bornes de dates).
