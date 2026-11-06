# DM-098 — Catégories de bénéficiaires : référentiel distinct du motif (A2)

- **Identifiant** : DM-098
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

Le roadmap LOT 03 prévoyait un « indicateur particulier/professionnel » ; le CDC §6.1 listait sept catégories communes ; ni l'un ni l'autre n'était repris par le SFD.

## Décision

Le demandeur ou l'agent choisit **une catégorie de bénéficiaire** dans un **référentiel distinct du motif**, persisté en base, administrable (libellé, ordre, actif) et évolutif. **Cinq catégories** à l'initialisation. La catégorie n'accorde **aucun droit automatique** (CDC §6.1) ; elle apparaît dans la liste et la fiche de la demande.

## Conséquences fonctionnelles ou techniques

- Persistance et initialisation : DT-039 ; liste initiale validée le 5 novembre 2026 ([lot-03-schema.md](../../lot-03-schema.md) §10), libellés au pluriel.
- Le dossier conserve le libellé de la catégorie utilisé.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md)

## Écarts documentaires signalés

- Roadmap LOT 03 (« simple indicateur particulier/professionnel ») et CDC §6.1 (sept catégories) : remplacés. Le modèle de données (`isProfessional`) est obsolète sur ce point.
