# DM-010 — « Commune entière » : confirmation explicite requise

- **Identifiant** : DM-010
- **Thème** : Zones
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-012, SCR-015, SCR-017 et CDC §5.1 : la zone « Commune entière — [nom] » est proposée mais jamais active sans confirmation.

## Décision

La zone « Commune entière » doit faire l'objet d'une confirmation explicite avant de devenir opérationnelle.

## Conséquences fonctionnelles ou techniques

- Une « Commune entière » non confirmée n'ouvre aucun droit et ne compte pas pour l'activation de l'événement.
- Représentation : champ `confirmedAt`, sans nouveau statut (arbitrage A1 validé, DM-051) ; la « Commune entière » automatique reste non confirmée (DM-058).

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-012, SCR-015, SCR-017, Écran 15)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.1)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- SFD §2 : états de zone « active/suspendue » ; SFD SCR-012 : zone « inactive avant confirmation » : l'état « inactive » n'existe pas dans la liste des états.
