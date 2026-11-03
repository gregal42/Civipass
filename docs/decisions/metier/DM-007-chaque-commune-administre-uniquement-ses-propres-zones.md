# DM-007 — Chaque commune administre uniquement ses propres zones

- **Identifiant** : DM-007
- **Thème** : Zones
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §2.1 et SCR-016 : « interdiction d'altérer zone d'autrui ». UI-02 D3.

## Décision

Chaque commune administre uniquement ses propres zones. Dans un événement intercommunal, les zones des autres communes sont en lecture seule.

## Conséquences fonctionnelles ou techniques

- Toute écriture sur une zone exige l'appartenance au membership actif de la commune de sa participation.
- Tests négatifs avec identifiants forgés inter-communes.
- Précisé par DM-084 (U2) : lecture seule pour une commune dont la participation est acceptée (ACTIVE, SUSPENDED, CLOSED) ; jamais pour une commune simplement invitée ou étrangère.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§2.1, SCR-016)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§2, D3)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
