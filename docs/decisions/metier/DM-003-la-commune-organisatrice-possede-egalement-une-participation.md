# DM-003 — La commune organisatrice possède également une participation

- **Identifiant** : DM-003
- **Thème** : Événements intercommunaux
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

La clôture globale est conditionnée par la clôture de **toutes** les participations (SFD §6.2), y compris celle de l'organisatrice.

## Décision

L'organisatrice possède elle aussi une participation communale.

## Conséquences fonctionnelles ou techniques

- La participation de l'organisatrice est créée avec l'événement, dans la même transaction.
- Elle est `ACTIVE` dès la création de l'événement (arbitrage A4 validé, voir DM-053).

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§6.2)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Ancien document SF-05 : l'organisatrice « active sa participation » lors de l'activation de l'événement (écart N3).
