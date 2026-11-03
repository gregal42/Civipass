# STD — proposition de mise à jour V1.2 (LOT 02)

> **Statut : proposition non validée.** Complète [STD v1.1](../references/STD/STD-V1.1.docx) (non modifié). Détail : [lot-02-schema.md](../lot-02-schema.md) (révision 3 de cette proposition : R1–R9, A10–A14, P1–P6).

## 1. Modifications du dictionnaire des entités

| Élément du STD v1.1 / `modele-donnees.md` | Proposition |
|---|---|
| Table `zone` communale et instantanés `event_zone.zone_id` / `source` | **Supprimées** (DM-008) |
| Champs GeoJSON (`zone`, `event_zone`) | **Supprimés** (DM-029) |
| `event_zone.daily_start_time`, `daily_end_time`, `timezone` | **Remplacés** par `event_zone_validity` (+ `validityMode`) |
| `event_zone` | Rattachée à `event_participation` ; ajout `confirmedAt`, `deletedAt`, `validityMode` |
| `event` | Ajout `timezone` (IANA, liste blanche), `type_id` → `event_type`, `public_reference` (`EVT-YYYY-NNNNNN`), `operational_instructions` (P3 validé) ; `starts_at` verrouillé après activation |
| `event_zone_validity` | Ajout `deleted_at` (suppression logique, DM-075) et `dst_choices` JSON (DT-020 validée) ; index `(zone_id, deleted_at)` |
| `event_reference_counter` | **Nouvelle** table technique à ligne unique : compteur global permanent (DM-072, DT-025) |
| `audit_log` | Ajout `operator_id`, `batch_id` (DT-022, **validée**), dans la première migration du LOT 02 |
| `event_type` | **Nouvelle** (code, libellé, ordre, actif) ; neuf types initiaux (DT-023) |
| `municipality_commercial_access` | **Proposée** (DT-030) : accès commercial CWS par commune |
| `public_reference` | **Retenu** : `EVT-YYYY-NNNNNN`, unique, immuable (DM-072) |

## 2. Relations, contraintes, index, énums

Voir [lot-02-schema.md](../lot-02-schema.md) §4 à §7 : `UNIQUE(event_id, municipality_id)`, `UNIQUE(event_type.code)`, FK `RESTRICT`, index par module. Énums : `EventStatus`, `ParticipationStatus`, `ZoneStatus`, `ZoneType`, `ValidityMode`, `ValidityKind`.

## 3. Invariants (§4 du STD)

Remplacer la liste de 10 invariants par les 21 du schéma LOT 02 (§7) ; corriger la référence de LOT-UI-02 aux « invariants 15 et 17 ».

## 4. Validité temporelle

Horaires locaux (DATE/TIME) ; instants UTC dérivés ; fuseau IANA de l'événement (outre-mer pris en charge, DM-062) ; heure inexistante refusée, heure répétée signalée avec choix (DM-055) ; choix stockés en JSON `dstChoices` (DT-020, validée) ; aucun usage du comportement implicite de PHP (DT-021).

## 5. Sécurité

Autorisation serveur + filtrage de périmètre par commune sur chaque cas d'usage ; politiques selon DM-057 ; identifiants client (`eventId`, `zoneId`, `participationId`) toujours vérifiés par relation ; aucun droit communal implicite pour CWS ; impersonation réservée à un lot dédié (MFA, bandeau, audit de l'opérateur réel).

## 6. Audit et transactions

Sujets et actions listés au modèle (§10) ; audit dans la transaction ; `TransactionRunner` ; verrous (DT-024, proposé) ; opérateur réel (DT-022, proposé).

## 6 bis. Habilitations CWS et abonnement
Permissions `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT` distinctes de `superAdmin` (préparées : énumération, port refusant par défaut, audit ; persistance dans le lot Administration CWS). Création d'événement contrôlée par `EventCreationPolicy` à partir du port `MunicipalityEntitlementProvider` (droits : abonnement, TRIAL, accès commercial CWS, chacun avec sa source et sa validité ; DM-076). Persistance minimale proposée : `municipality_commercial_access` (DT-030, à valider avant migration).

## 7. Documents du dépôt à aligner (après validation)

`docs/data-model.md`, `docs/architecture.md` (« instantané »), `docs/development-roadmap.md` (« zones et snapshots »), `docs/security.md` (matrice CWS, rôles).
