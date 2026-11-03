# DM-019 — Zone sans validité configurée : aucun droit effectif

- **Identifiant** : DM-019
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

UI-02 Q4 : « zone sans créneau : valable pendant tout l'événement, ou aucun droit possible ? ».

## Décision

Une zone sans configuration temporelle ne peut ouvrir aucun droit d'accès effectif.

## Conséquences fonctionnelles ou techniques

- Le calcul de droits effectifs refuse toute zone non configurée.
- Tranche la question Q4 de UI-02.

## Documents concernés

- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§8 Q4)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Le SFD ne dit rien de la zone sans créneau. Garde d'activation de l'événement : voir DM-059 (A9 validé).
