# TECH-02.1 — Plan d'implémentation (proposition, révision 3)

> **Statut : plan proposé. Aucun développement avant l'autorisation explicite du commanditaire.** Schéma : [`../../lot-02-schema.md`](../../lot-02-schema.md). Arbitrages A1–A14, R1–R9, P1–P6, DT-020 et DT-022 validés ; aucun point bloquant identifié ; points de détail T2 à T6 : [CONTRADICTIONS.md](../../revisions/CONTRADICTIONS.md) §G.

## Étape 0 — Prérequis (documentaire) — réalisée, en attente de validation
1. Valider les révisions [SFD V1.1](../../revisions/SFD-V1.1-proposition-lot02.md) et [STD V1.2](../../revisions/STD-V1.2-proposition-lot02.md).
2. Valider le schéma `docs/lot-02-schema.md` (règle 3 du `CLAUDE.md`), la table d'accès commercial (DT-030) et les points T2 à T6 (défauts proposés). Liste des `EventType` : validée.
3. Constituer, depuis une source officielle, le référentiel territorial (fuseau par département/collectivité, exceptions par commune pour la Polynésie française) ; requis avant production, pas avant la migration.
4. Les documents du dépôt contradictoires portent déjà un bandeau de révision (`data-model.md`, `architecture.md`, `development-roadmap.md`, `security.md`).

## Étape 1 — Domaine pur (sans base) — **autorisée, réalisée dans `src/Event/Domain/{Time,Validity}`** (tests : `tests/Unit/Event`)
Énumérations ; objets valeur (`EventTimezone`, `PublicReference`, créneaux avec fabriques `continuous()`, `punctual()`, `recurring()`) ; `ZoneValidityCalculator` (union, minuit, bornage à l'événement, exclusion des créneaux supprimés) ; service de résolution des transitions horaires (refus de l'heure inexistante, choix explicite de l'heure ambiguë, cohérence de `dstChoices`) ; `TerritoryTimezoneResolver`. Tests : DST pour chaque fuseau à heure d'été, fuseaux sans transition, récurrences.

## Étape 2 — Entités et mappings — **réalisée (sans migration)**, voir `lot-02-schema.md` §15
`EventType`, `Event`, `EventParticipation`, `EventZone`, `EventZoneValidity` (avec `deletedAt` et `dstChoices`), compteur de référence ; repositories ; ajout des nouvelles tables à `TestDatabase`.
Audit : `operator_id` et `batch_id` sur `audit_log` ; `AuditActor`/`AuditTrail` étendus ; nouveaux `AuditAction` et `AuditSubjectType` ; énumération `CwsPermission` et port `CwsPermissionChecker` refusant par défaut.

## Étape 3 — Migration unique — **réalisée sur `civipass_test`** (+ migration complémentaire `suspended_at`), voir `lot-02-schema.md` §16 (périmètre d'origine ci-dessous)
1. Créer **une** migration relue : tables `event_type`, `event_event`, `event_participation`, `event_zone`, `event_zone_validity`, `event_reference_counter`, `municipality_commercial_access` (DDL déjà vérifié en lecture seule) ; `INSERT` de la ligne unique du compteur (`id = 1`, `last_number = 0`) ; `ALTER TABLE audit_log ADD operator_id, batch_id` + index.
2. Mapper `operator_id` / `batch_id` dans `AuditLog`, supprimer le garde-fou de `DoctrineAuditTrail`, adapter `AuditExtensionTest`.
3. Initialisation idempotente des neuf `EventType` (DT-023).
4. Jouer la migration **uniquement** sur `civipass_test` (`make test-db`), jamais sur `civipass` sans accord ; exécuter les 22 tests d'intégration aujourd'hui sautés sur MySQL ; ajouter les tests de concurrence réels.
5. Aucun service applicatif (étape 5), aucun contrôleur, aucune UI.

## Étape 3 (détail initial) — Migration unique
Création des tables, initialisation de la ligne du compteur, `ALTER TABLE audit_log` ; **relue avant exécution**, jouée **uniquement** sur `civipass_test`, jamais sur `civipass` sans accord ; initialisation d'`EventType` seulement après validation de la liste.

## Étape 4 — Politiques d'accès — **réalisée et finalisée (U1 à U4), sans migration**, voir `lot-02-schema.md` §17
Matrice §8.1 du schéma, contextualisée par commune, commune `ACTIVE` en écriture ; `EventCreationPolicy` centralisé (statut administratif + port `MunicipalityEntitlementProvider` : abonnement, TRIAL, accès commercial CWS) ; aucun droit communal implicite pour CWS.

## Étape 5 — Services applicatifs (transactionnels, audités)
Création d'événement (référence globale, participation organisatrice `ACTIVE`, zone proposée, fuseau proposé), invitation, acceptation, activation (garde A9/R7), modification d'un événement actif (verrouillages, fin modifiable), suspension / réactivation, clôture **normale** (A6.3) y compris `DRAFT → CLOSED` (DM-077), cycle des zones (créer, confirmer, suspendre, supprimer logiquement), configuration de validité (remplacement avec historique), opérations groupées (`batch_id`). Verrous : compteur → événement → participation → zones.

## Étape 6 — Tests
Règles métier, transitions complètes, permissions, **isolation inter-communes avec identifiants forgés**, concurrence (invitations, références, activation/clôture, reconfiguration), DST et fuseaux ultramarins, historique des créneaux, droits d'utilisation (abonnement, TRIAL, accès commercial CWS avec/sans expiration ; expiré ou révoqué sans effet sur un événement ouvert), clôture d'un événement `DRAFT`, initialisation idempotente des neuf `EventType`, audit atomique, nombre de requêtes. `make quality` vert.

## Hors lot
UI (parcours simple et avancé, DM-060 ; écarts UI-02 à corriger avant intégration : [ECARTS-UI-02](../../revisions/ECARTS-UI-02.md)), administration des types, **impersonation et clôture forcée CWS (lot Administration CWS, DM-074)**, persistance des habilitations CWS, MFA, module Abonnements (seule la persistance minimale d'accès commercial CWS, DT-030, est proposée), demandes, laissez-passer, import du COG complet.

## Livraison attendue
Selon la règle 12 : fichiers modifiés, migrations, commandes, résultats, limites et risques.
