# DT-045 — Correction d'identité : verrou de la demande, version et audit par commune

- **Identifiant** : DT-045
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « LOT 04 — Incrément 04.4 » et arbitrages validés, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-111, DT-024 (ordre des verrous), DT-035 (transactions), DT-037 (audit sans donnée personnelle), DT-038 (horodatage sous verrou).

## Décision

1. **Service** `RequestIdentityCorrectionService` (`page()`, `correct()`), commande `CorrectIdentityCommand`. L'entité porte la règle : `PermitRequest::correctIdentity()` normalise, retourne les **noms** des champs réellement modifiés et n'incrémente `identityVersion` que s'il y a changement.
2. **Droits relus sous verrou** : adhésion effective active (écriture), rôle INSTRUCTOR ou MUNICIPALITY_ADMIN (`IdentityCorrectionDenied` → 403), part dans la commune de l'URL (`locatePart`, sinon refus uniforme 404). Commune et demande ne sont jamais crues.
3. **Verrou unique.** `PermitRequestRepository::lockRequest()` : `PESSIMISTIC_WRITE` sur la **seule** ligne de la demande, avec relecture (`HINT_REFRESH`). Aucun autre verrou n'est pris : la décision (DT-024 : événement → participation → zones → part) ne lit ni n'écrit l'identité, aucun cycle n'est possible et une décision concurrente n'attend pas la correction (testé).
4. **Concurrence optimiste.** `identity_version` (entier, défaut 0). Comparée sous verrou à la version du formulaire ; différente : `OperationConflict(StaleState)`. Le contrôleur répond 409, conserve la saisie, affiche les valeurs enregistrées, reprend la version courante et exige une nouvelle attestation.
5. **Audit** : une `AuditEvent` `REQUEST_IDENTITY_CORRECTED` **par part** (donc par commune), `municipalityId` = la commune concernée, acteur = l'agent, `previousValue` nul, `newValue` = `fields`, `reference`, `correctedByMunicipality`, `ownMunicipality`, `identityVersion`, `municipalities`. Même transaction que la correction ; instant lu sous verrou ; un échec d'audit annule tout (testé). Aucune valeur personnelle.
6. **Interface** : route unique `GET|POST /communes/{m}/demandes/{id}/identite`, jeton CSRF propre à la demande, `Cache-Control: no-store, private`, saisie jamais dans l'URL, succès en PRG avec message, bornes de lecture au-dessus des longueurs métier (un dépassement est signalé, jamais tronqué). Les champs hors périmètre du POST sont ignorés.

7. **Point d'entrée (04.5)** : `RequestIdentityCorrectionService::find()` (mêmes droits, `PermitRequestRepository::findPartByReference()`, référence normalisée `^[A-Z0-9-]{1,20}$`), route `app_municipal_identity_corrections` (GET, `no-store`), entrée de navigation dans `ShellContextFactory`. N'utilise pas `MunicipalRequestReader`.

## Conséquences fonctionnelles ou techniques

- Migration non destructive : `ALTER TABLE request_permit_request ADD identity_version INT DEFAULT 0 NOT NULL`.
- Tests : `RequestIdentityCorrectionTest`, `RequestIdentityConcurrencyTest` (processus réels), `RequestIdentityFlowTest`.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)
- [DM-111](../metier/DM-111-correction-d-identite-du-beneficiaire.md)

## Écarts documentaires signalés

Aucun.
