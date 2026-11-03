# DM-018 — Chevauchements autorisés, validité par union

- **Identifiant** : DM-018
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-018 : « Chevauchement … règle technique à confirmer » ; UI-02 Q3.

## Décision

Les chevauchements entre créneaux sont autorisés. La validité temporelle correspond à l'union des créneaux applicables.

## Conséquences fonctionnelles ou techniques

- Aucun contrôle ni message d'erreur de chevauchement.
- Calcul de la validité effective par union d'intervalles.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-018, Écran 18)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§8 Q3)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Tranche une règle que le SFD laissait « à confirmer ».
