# DM-110 — Référentiel des motifs de refus

- **Identifiant** : DM-110
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-097 (motifs de demande), DM-098 (catégories), DT-039 (référentiels).

## Décision

1. Référentiel `RefusalReason` **distinct** de `RequestMotif` : code stable, libellé, ordre, actif, `needsDetail` ; global à la plateforme ; jamais supprimé (FK `RESTRICT`).
2. Motifs V1 : Bénéficiaire non éligible (`ineligible_beneficiary`) ; Motif de la demande non justifié ou non vérifiable (`reason_not_justified`) ; Justificatifs insuffisants ou illisibles (`documents_insufficient`) ; Zone ou secteur non concerné par le besoin (`zone_not_concerned`) ; Demande en doublon (`duplicate`) ; Informations incohérentes (`inconsistent_information`) ; Autre (`other`, `needsDetail`).
3. Un motif **actif** est obligatoire pour tout refus ; commentaire facultatif (500 caractères au plus), **obligatoire pour « Autre »**.
4. Code et libellé du motif sont copiés dans la décision : leur sens est préservé si le référentiel évolue.
5. Le commentaire libre n'entre jamais dans l'audit (seul le code du motif y figure, DT-037).

## Conséquences fonctionnelles ou techniques

- Initialisation idempotente (`RefusalReasonInitializer`) et insertion par migration, comme DT-039.
- Aucune notification au citoyen avant le LOT 07 : le commentaire reste interne à la commune.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
