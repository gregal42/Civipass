# DT-043 — Délivrance directe : raccordement dans le service de dépôt (LOT 04.3)

- **Identifiant** : DT-043
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-108, DT-024, `RequestSubmissionService`, `RequestIdempotencyKey`.

## Décision

La délivrance directe étend `RequestSubmissionService::persist()` : un paramètre optionnel d'approbation appelle `MunicipalDecisionApplier` après `PermitRequest::submit()` et avant l'écriture, dans la **même** transaction et avec les mêmes verrous ; tout échec annule dossier, part, décision et audit. Un marqueur `DIRECT` entre dans l'empreinte d'idempotence ; un rejeu renvoie le dossier d'origine sans seconde décision. **Implémentée au LOT 04.3** (`RequestSubmissionService::issueDirectly`). Complément : après l'acquisition des verrous de participation, l'empreinte d'idempotence est relue par une lecture VERROUILLANTE (`findByIdempotencyKey(…, locking: true)`) : l'instantané REPEATABLE READ ne voit pas une tentative concurrente validée pendant l'attente ; le rejeu renvoie alors son résultat (décision relue sous verrou partagé, `lockDecisions`) au lieu d'un conflit d'unicité. Deux tentatives vraiment simultanées restent départagées par `uniq_request_idempotency_event_key` (conflit contrôlé, réessayer).

## Conséquences fonctionnelles ou techniques

- Ne jamais approuver « après coup » un dossier déjà persisté `PENDING`. Preuve de test : un écouteur `onFlush` constate que le seul flush écrit la part déjà `APPROVED` avec sa décision.
- Prévisualisation : `DirectIssuancePreview` (lecture seule, mêmes droits et mêmes règles que le moteur, `MunicipalDecisionApplier::previewZones`) ; informative, la validation revalide tout sous verrou.
- Accès à la confirmation : `DirectIssuanceReader` (voir DM-108).

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
