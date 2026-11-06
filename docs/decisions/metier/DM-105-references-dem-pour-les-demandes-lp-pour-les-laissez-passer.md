# DM-105 — Références : `DEM-AAAA-NNNN` pour les demandes, `LP-` pour les laissez-passer délivrés

- **Identifiant** : DM-105
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-026 indiquait « Référence LP-AAAA-… » à la confirmation d'une demande ; UI-001 utilisait `DEM-AAAA-NNNN`.

## Décision

Les demandes portent la référence **`DEM-AAAA-NNNN`**. Le préfixe **`LP-` est réservé aux laissez-passer délivrés** : il n'est jamais affiché ni recherchable avant la première approbation, et aucun lien numérique implicite n'existe entre les deux références.

## Conséquences fonctionnelles ou techniques

- S1 (validé le 5 novembre 2026) : compteur global à Civipass, distinct par année civile, repartant à 1 chaque année, quatre chiffres minimum extensibles ; année dans le fuseau de l'événement ([lot-03-schema.md](../../lot-03-schema.md) §4.3).

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md)

## Écarts documentaires signalés

- SFD SCR-026 (`LP-AAAA-…` pour le dossier) : remplacé. [ECARTS-UI-02](../../revisions/ECARTS-UI-02.md) E13 mentionnait déjà `DEM-`/`LP-`.
