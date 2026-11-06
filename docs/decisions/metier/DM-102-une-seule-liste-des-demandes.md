# DM-102 — Une seule liste des demandes (A7)

- **Identifiant** : DM-102
- **Thème** : UX — LOT 03
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

UI-001 fournissait une liste des demandes ; UI-03 a évolué sans créer de seconde liste.

## Décision

Il n'existe **qu'une seule liste des demandes** : celle d'UI-001, évoluée. Ses fonctions sont conservées (recherche, filtres, tri, pagination, états chargement / vide / erreur) ; onglets À traiter (défaut), Traitées, Toutes ; catégorie, zones de la commune, badge doublon, mention des autres communes, « Nouvelle demande ».

## Conséquences fonctionnelles ou techniques

- Réutiliser les composants `request_list`, `request_card`, `data_table`, `tabs` déjà importés.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md)

## Écarts documentaires signalés

- Aucun écart.
