# DM-104 — Zones proposables au dépôt : « ouverte aux demandes » définie

- **Identifiant** : DM-104
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-022 : « zones ouvertes à la demande », sans définition. Le prototype retenait « confirmée et active » et proposait aussi les zones « Validité à configurer ».

## Décision

Une zone est proposée au dépôt (public et agent) si, et seulement si, elle est : **confirmée**, **ACTIVE**, **non supprimée**, avec une **validité configurée**, et d'une **participation communale opérationnelle** (participation ACTIVE d'un événement ACTIVE). Une zone « Validité à configurer » n'est **pas sélectionnable**.

## Conséquences fonctionnelles ou techniques

- Source de vérité unique : `EventZone::mayOpenRights()` (DM-019, DM-051, DM-059, DM-066).
- Une commune sans zone proposable est absente du formulaire ; sans aucune zone proposable, le dépôt est indisponible avec un message clair.
- Une commune dont la participation est invitée n'a aucune zone proposable : elle ne peut pas être ciblée par un dépôt.
- Revalidation **côté serveur** à la soumission ; une zone devenue non proposable entre-temps entraîne un refus explicite, saisies conservées.
- Un dossier déposé conserve ses zones demandées même si elles sont ensuite suspendues, supprimées ou si la participation est suspendue/clôturée (dossier figé, affichage selon l'état).

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md) §6

## Écarts documentaires signalés

- SFD SCR-022 (« commune affectée non adhérente possible avant activation ») et REC-02 (« commune invitée tardivement instruit ses demandes déjà déposées ») : sans effet pour une commune invitée, qui n'a aucune zone proposable. Voir [CONTRADICTIONS.md](../../revisions/CONTRADICTIONS.md) §LOT 03.
