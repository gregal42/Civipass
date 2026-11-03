# DM-011 — Configuration explicite de la validité d'une zone

- **Identifiant** : DM-011
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-018 et CDC §5.2 : validité configurable par zone.

## Décision

Une zone possède une configuration temporelle explicite. Trois états de configuration sont représentés, sans créer de nouveaux statuts métier : non configurée ; valable pendant toute la durée de l'événement ; valable sur des périodes spécifiques.

## Conséquences fonctionnelles ou techniques

- Un champ de mode de configuration (non configurée / durée de l'événement / périodes spécifiques) distinct du statut de la zone.
- Aucun nouveau statut métier.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-018, Écran 18)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.2)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
