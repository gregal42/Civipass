# DM-106 — Décision binaire par commune, sans approbation partielle

- **Identifiant** : DM-106
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

SFD §2, CDC §7.1, DM-101, DM-002 ; `lot-03-schema.md` mentionnait une décision « totale ou partielle ».

## Décision

1. Chaque part communale (`PermitRequestMunicipality`) a un statut `PENDING`, `APPROVED` ou `REFUSED`.
2. Transitions autorisées au LOT 04.1 : `PENDING → APPROVED` et `PENDING → REFUSED`, **terminales** : ni réouverture, ni modification, ni réexamen.
3. Une approbation autorise **toutes** les zones demandées à la commune ; un refus les concerne **toutes**. Aucune approbation partielle par zone en V1.
4. Les décisions des autres communes du dossier sont indépendantes et ne sont ni lues ni modifiées.
5. La décision est conservée dans une entité immuable `PermitRequestDecision`, unique par part (contrainte en base) ; le statut de la part en est l'état courant.

## Conséquences fonctionnelles ou techniques

- Prolongation, révocation et réexamen (LOT 05) s'ajouteront à part, sans réécrire la décision initiale.
- Deux décisions concurrentes sur une même part : une seule réussit, l'autre reçoit `OperationConflict(StaleState)`.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)
- [lot-03-schema.md](../../lot-03-schema.md) §3

## Écarts documentaires signalés

- `lot-03-schema.md` (« décision totale ou partielle ») : corrigé par annotation, sans effacer l'historique.
