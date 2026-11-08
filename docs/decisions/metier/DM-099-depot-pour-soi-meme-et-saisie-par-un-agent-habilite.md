# DM-099 — Dépôt public pour soi-même ; saisie pour un tiers par un agent habilité (A3, A5)

- **Identifiant** : DM-099
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune. **Partiellement remplacée par [DM-108](DM-108-delivrance-directe-et-remplacement-de-dm-099.md)** (§2 et §5, délivrance directe par un agent, 8 novembre 2026) ; le texte d'origine est conservé ci-dessous.

## Contexte

Le CDC §7.2 prévoit la « création directe d'un dossier et d'un laissez-passer » pour un administré reçu au guichet ou au téléphone ; le SFD n'a ni écran ni règle pour ce parcours.

## Décision

1. Le **dépôt public** est exclusivement fait **pour soi-même**.
2. La **saisie pour un tiers** est faite par un **agent habilité** de sa commune, qui est le **créateur du dossier**. La création est **historisée** (auteur, commune de saisie, date, origine « agent ») et suit le **circuit normal** (décisions par commune, aucune délivrance automatique).
3. La saisie agent utilise les **mêmes champs, règles de validation et référentiels** que le dépôt public (« Nouvelle demande » depuis la liste ouvre cette saisie).
4. L'agent **atteste que le bénéficiaire a été informé de la politique de confidentialité** (case obligatoire). **Le libellé juridique définitif reste à valider.**
5. La **délivrance directe** d'un laissez-passer à la saisie est **reportée** à un lot ultérieur : le LOT 03 ne crée aucun laissez-passer.

## Conséquences fonctionnelles ou techniques

- Rôles habilités à saisir : à préciser dans la fiche de schéma ([lot-03-schema.md](../../lot-03-schema.md) §15, S6).
- Point non tranché : l'agent peut-il demander des zones d'autres communes participantes (prévu ainsi par le prototype, comme le formulaire public) ? Question 2 de LOT-03.md, à valider avant l'incrément 5.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md) ; [SFD-V1.1-proposition-lot03.md](../../revisions/SFD-V1.1-proposition-lot03.md)

## Écarts documentaires signalés

- CDC §7.2 : la partie « laissez-passer » est différée ; la partie « dossier sans dépôt public préalable » est retenue.
