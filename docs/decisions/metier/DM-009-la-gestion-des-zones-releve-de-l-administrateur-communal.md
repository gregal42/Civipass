# DM-009 — La gestion des zones relève de l'administrateur communal

- **Identifiant** : DM-009
- **Thème** : Zones
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée ; reprend UI-02 D8
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-016 à SCR-018 désignent un « gestionnaire communal », rôle qui n'est pas défini (UI-02 E5/D8).

## Décision

L'administrateur communal est habilité à créer et modifier les zones de sa commune.

## Conséquences fonctionnelles ou techniques

- Permission « gérer les zones » = `MUNICIPALITY_ADMIN` de la commune concernée, commune active en écriture.
- Pas de rôle « gestionnaire communal ». Pas de droit implicite pour CWS (DM-005).
- Conséquence organisationnelle : l'activation d'un événement exige une zone confirmée (DM-059) ; l'administrateur communal cumule les droits du gestionnaire (DM-057).

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-016 à SCR-018, §3)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (D8, E5)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- SFD SCR-016 à SCR-018 : « Gestionnaire communal » (rôle non défini) ; ancien SF-09 : « Gestionnaire habilité de la commune ». Libellé du SFD à corriger après validation (écart S7).
