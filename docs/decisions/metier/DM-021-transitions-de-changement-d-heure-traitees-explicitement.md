# DM-021 — Transitions de changement d'heure traitées explicitement

- **Identifiant** : DM-021
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

CDC §5.2 : « les cas de passage à l'heure d'été/hiver seront couverts par les tests ». SFD Annexe D : règle à confirmer.

## Décision

Les changements d'heure (heure d'été et d'hiver) sont gérés explicitement.

## Conséquences fonctionnelles ou techniques

- Tests dédiés sur les deux jours de transition annuels.
- Règles précisées : heure inexistante refusée, heure répétée signalée avec choix de l'utilisateur (DM-055).

## Documents concernés

- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.2)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (Annexe D)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Comportement précis : voir DM-055 (validé).
