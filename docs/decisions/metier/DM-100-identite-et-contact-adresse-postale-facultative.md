# DM-100 — Identité et contact : adresse postale toujours facultative (A4)

- **Identifiant** : DM-100
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-020 : « adresse postale selon formulaire » ; le modèle de données n'avait pas de champ adresse.

## Décision

Nom et prénom obligatoires ; **au moins un contact** (adresse e-mail ou téléphone) ; **adresse postale toujours facultative**, en dépôt public comme en saisie agent. Téléphone normalisé en E.164 (DT-008).

## Conséquences fonctionnelles ou techniques

- Champ adresse nullable dans le schéma du LOT 03.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md)

## Écarts documentaires signalés

- Aucun écart avec une décision validée ; précise SFD SCR-020.
