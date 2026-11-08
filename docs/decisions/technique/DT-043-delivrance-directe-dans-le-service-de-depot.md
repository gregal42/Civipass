# DT-043 — Délivrance directe : raccordement dans le service de dépôt (LOT 04.3)

- **Identifiant** : DT-043
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-108, DT-024, `RequestSubmissionService`, `RequestIdempotencyKey`.

## Décision

La délivrance directe étend `RequestSubmissionService::persist()` : un paramètre optionnel d'approbation appelle `MunicipalDecisionApplier` après `PermitRequest::submit()` et avant l'écriture, dans la **même** transaction et avec les mêmes verrous ; tout échec annule dossier, part, décision et audit. Un marqueur `DIRECT` entre dans l'empreinte d'idempotence ; un rejeu renvoie le dossier d'origine sans seconde décision. **Décision d'architecture seulement : non implémentée au 04.1.**

## Conséquences fonctionnelles ou techniques

- Ne jamais approuver « après coup » un dossier déjà persisté `PENDING`.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
