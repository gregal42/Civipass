# DM-059 — A9 — Conditions d'activation d'un événement

- **Identifiant** : DM-059
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-015 (« au moins un périmètre valide »).

## Décision

Option A, précisée par R7. Un événement peut être activé lorsqu'il possède au moins une zone confirmée, active, non supprimée, avec une validité temporelle configurée (toute la durée de l'événement, ou une ou plusieurs périodes spécifiques) et appartenant à une participation `ACTIVE`. Une zone d'une participation suspendue ne satisfait pas la condition. Un créneau futur reste admissible ; une zone sans validité configurée ne satisfait pas la condition.

## Options examinées

- (a) confirmée, active, non supprimée et configurée — **retenue**

## Conséquences fonctionnelles ou techniques

- Garde `DRAFT → ACTIVE` : existence d'une zone (confirmedAt non nul, status ACTIVE, deletedAt nul, validityMode ≠ UNCONFIGURED, au moins un créneau si SPECIFIC_PERIODS) dont la participation est ACTIVE.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-015)

## Écarts documentaires signalés

- Point R7 levé.
