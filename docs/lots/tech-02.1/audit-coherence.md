# Audit de cohérence — LOT TECH-02.1 (événements, participations, zones, validité)

> **Révision 5 — arbitrages A1 à A14, R1 à R9, P1 à P6, DT-020, DT-022, T1, DRAFT → CLOSED et types d'événements VALIDÉS** (révision 2 : A1–A9 ; 3 : R1–R9, A10–A14 ; 4 : P1–P6 ; 5 : voir R5 en fin de document). Aucun code, aucune entité, aucune migration : le développement n'est pas commencé.
> Les parties 1 à 10 reprennent **intégralement** le rapport d'audit d'origine (révision 1) comme trace historique. **Là où elles diffèrent de les addendums (fin du document), l'addendum et les décisions du [registre](../../decisions/README.md) prévalent** (notamment §3.3, §3.4, §5, §6 et §8). Schéma à jour : [`../../lot-02-schema.md`](../../lot-02-schema.md) ([modele-doctrine.md](modele-doctrine.md) = révision 1 historique). Plan : [plan-implementation.md](plan-implementation.md).
> Hiérarchie des sources : voir [`../../README.md`](../../README.md).

**Légende** : **[SPEC]** déjà spécifié · **[VALIDÉ]** décidé par vous dans ce message · **[RECO]** recommandation technique · **[ARBITRER]** décision attendue.

# 1. Sources lues

SFD V1.0 détaillé, CDC V1, STD v1.1, `LOT-UI-02-architecture.md`, plus `modele-donnees.md` (schéma V1.0 plus ancien) et `docs/` du dépôt. Le SFD fait autorité.

# 2. Diagnostic des écarts

## 2.1 Entre les spécifications

| # | Écart | Traitement |
|---|---|---|
| S1 | **Clôture globale** : le SFD §6.2 la confie à l'organisatrice une fois toutes les participations clôturées (CWS peut forcer). Le CDC §4.1 la réserve à CWS. | Suivre le SFD. Écart à acter dans le CDC. |
| S2 | **Statuts de commune** : le SFD en prévoit 5 (en attente, active, suspension planifiée, suspendue, résiliée). Le code en a 3 (`ACTIVE`, `SUSPENDED`, `INACTIVE`, votés au LOT 01). | Suffisant pour le LOT 02, qui ne teste que « ACTIVE ». À aligner avec le lot Abonnements. |
| S3 | **Bibliothèque de zones** : STD et `modele-donnees.md` prévoient une table `zone` communale et des « instantanés ». [VALIDÉ] : pas de bibliothèque en V1. | Supprimer `zone` et les instantanés. |
| S4 | **Géométrie** : `modele-donnees.md` prévoit des champs GeoJSON. Pas de cartographie en V1. | Aucun champ géométrique. |
| S5 | **Validité simplifiée** : `modele-donnees.md` porte `daily_start_time`, `daily_end_time` et `timezone` sur `event_zone`. Le SFD et le CDC exigent plusieurs créneaux. | Remplacé par l'entité `EventZoneValidity`. |
| S6 | **Heures qui se croisent** : SCR-018 dit « fin strictement après début ». [VALIDÉ] : créneaux nocturnes autorisés. | Compatible : seule l'égalité est interdite, et l'instant de fin résolu doit suivre l'instant de début. |
| S7 | **« Gestionnaire communal »** (SCR-016 à 018) n'est pas un rôle défini. [VALIDÉ] : administrateur communal. | Corriger le SFD. |
| S8 | **Fuseau** : le STD parle du « fuseau de l'événement » (donc variable). [VALIDÉ] : « heure de Paris ». Or les communes d'outre-mer ne sont pas à Paris. | Voir A5. |
| S9 | **Références croisées** : LOT-UI-02 cite « invariants 15 et 17 » du STD. Le STD v1.1 §4 n'en numérote que 10, et `modele-donnees.md` en liste 10 également. | Corriger la référence. |
| S10 | **Qui gère la participation** : le SFD §3 donne la « gestion des participations » à l'administrateur communal. LOT-UI-02 §3 la donne au gestionnaire événement. | Voir A7. |

## 2.2 Entre les spécifications et le code ou la documentation du dépôt

| # | Constat |
|---|---|
| C1 | **Aucun module `Event`** : ni entité, ni migration, ni workflow. `workflow.yaml` est vide, alors que `symfony/workflow` est installé. |
| C2 | **Pas d'entité d'abonnement.** Seul l'enum `SubscriptionStatus` existe. `EventCreationPolicy` reçoit le statut en paramètre : la règle « commune abonnée et active » n'est pas applicable de bout en bout. |
| C3 | **Pas de référentiel des types d'événement.** Le SFD (SCR-053) le gère par CWS (libellé, ordre, actif ; un type utilisé ne se supprime pas). |
| C4 | **`docs/data-model.md`** et `architecture.md` (« instantané par événement », zone « nom + description ») contredisent vos décisions sur la bibliothèque, les types et la validité. `development-roadmap.md` cite « zones et snapshots ». |
| C5 | **`docs/security.md`** accorde à CWS la « Supervision » sur la création d'événement, l'invitation, l'activation d'appareils. Cela contredit « aucun droit communal implicite ». |
| C6 | **Rôles** : `MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR` correspondent bien au SFD et au STD. |
| C7 | **Réutilisable tel quel** : UUID v7, dates UTC, `TransactionRunner`, `AuditTrail` (colonnes en `VARCHAR`, donc nouvelles actions sans migration), `MembershipAccessPolicy`, `MunicipalityContext`, builders de tests. Il faudra seulement ajouter les nouvelles tables à `TestDatabase`. |
| C8 | **Supervision CWS du LOT 01** (gérer les membres, le contact, lire l'audit d'une commune) : conservée, non étendue aux événements, zones, demandes, décisions et laissez-passer. Conforme à votre consigne. |

# 3. Modèle Doctrine proposé

Convention : UUID v7 en `BINARY(16)`, dates en UTC, `RESTRICT` partout, aucune suppression physique de données métier, énums PHP en `VARCHAR`, un module `src/Event/{Domain,Application,Infrastructure}`.

## 3.1 `Event` (table `event_event`)

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | uuid | non | |
| `organizerMunicipality` | ManyToOne `Municipality` | non | **Immuable** après création. [RECO] |
| `name` | string(200) | non | SCR-012 |
| `type` | ManyToOne `EventType` | non | voir A3 |
| `description` | text | oui | |
| `startsAt` | datetime UTC | non | saisi en heure de Paris |
| `endsAt` | datetime UTC | oui | `endsAt > startsAt` si renseignée |
| `status` | enum | non | `DRAFT`, `ACTIVE`, `SUSPENDED`, `CLOSED` |
| `suspensionReason` | text | oui | **obligatoire** à la suspension [SPEC] |
| `activatedAt`, `closedAt` | datetime | oui | |
| `createdBy` | ManyToOne `User` | non | |
| `createdAt`, `updatedAt` | datetime | non | |

## 3.2 `EventParticipation`

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | uuid | non | |
| `event` | ManyToOne | non | |
| `municipality` | ManyToOne | non | |
| `status` | enum | non | `INVITED`, `ACTIVE`, `SUSPENDED`, `CLOSED` |
| `invitedBy` | ManyToOne `User` | oui | `NULL` pour l'organisatrice |
| `invitationMessage` | text | oui | SCR-014 |
| `invitedAt`, `lastInvitationSentAt` | datetime | oui | renvoi d'invitation |
| `activatedAt` | datetime | oui | acceptation |
| `suspendedAt`, `closedAt` | datetime | oui | |
| `suspensionReason` | text | oui | obligatoire [SPEC] |
| `createdAt`, `updatedAt` | datetime | non | |

**Unicité `(event_id, municipality_id)`** [VALIDÉ]. L'historique des changements de statut passe par `audit_log` [SPEC] (nouveaux sujets `EVENT`, `EVENT_PARTICIPATION`, `EVENT_ZONE`).

## 3.3 `EventZone`

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | uuid | non | |
| `participation` | ManyToOne | non | **seul rattachement** : événement et commune se déduisent de la participation, donc aucune incohérence possible. [RECO] |
| `label` | string(150) | non | |
| `type` | enum | non | `WHOLE_MUNICIPALITY`, `DISTRICT`, `STREET`, `LOCALITY`, `CUSTOM` (valeurs du SFD) |
| `description` | text | oui | |
| `status` | enum | non | `ACTIVE`, `SUSPENDED` |
| `confirmedAt` | datetime | oui | `NULL` = « proposée, à confirmer ». Voir A1. |
| `validityMode` | enum | non | `UNCONFIGURED`, `EVENT_DURATION`, `SPECIFIC_PERIODS` (des modes de **configuration**, pas des statuts métier) |
| `deletedAt` | datetime | oui | suppression logique |
| `createdBy`, `createdAt`, `updatedAt` | | | |

## 3.4 `EventZoneValidity` (un créneau)

Un seul tableau de colonnes, un discriminant `kind`. **Aucune colonne en UTC** : ce sont des horaires locaux (voir §6).

| Champ | Continu | Ponctuel | Récurrent |
|---|---|---|---|
| `kind` | `CONTINUOUS` | `PUNCTUAL` | `RECURRING` |
| `startDate` (DATE) | oui | oui | oui |
| `startTime` (TIME) | oui | oui | oui |
| `endDate` (DATE) | oui | `NULL` | oui |
| `endTime` (TIME) | oui | oui | oui |
| `weekdays` (SMALLINT, bits Lun..Dim) | `NULL` | `NULL` | 1 à 127 |

Plus `zone` (ManyToOne), `createdAt`, `updatedAt`. Les trois modes sont construits par des méthodes de fabrique du domaine (`continuous()`, `punctual()`, `recurring()`) qui garantissent les champs de chaque type ; aucun constructeur public ne permet un créneau incohérent.

## 3.5 `EventType` (cinquième entité, voir A3)

`id`, `code` (unique), `label`, `sortOrder`, `active`. Gérée par CWS dans Sonata plus tard (SCR-053).

# 4. Relations, contraintes, index

```
Municipality ─< EventParticipation >─ Event ─> organizerMunicipality (Municipality)
                      │
                      └─< EventZone ─< EventZoneValidity
Event ─> EventType
```

- **Unicités** : `UNIQUE(event_id, municipality_id)` sur les participations ; `EventType.code`.
- **Clés étrangères** : toutes en `RESTRICT`, jamais `CASCADE`.
- **Index** :
  - `event` : `(organizer_municipality_id, status)`, `(status, starts_at)`.
  - `event_participation` : `(municipality_id, status)` (l'unicité couvre `event_id`).
  - `event_zone` : `(participation_id, status, deleted_at)`.
  - `event_zone_validity` : `(zone_id)`.
- **Pas de doublon de libellé interdit** : SCR-017 prévoit un avertissement, pas un blocage.
- **Une seule « Commune entière » non supprimée par participation** : à contrôler dans le domaine, avec un test. [RECO]
- **Historique** : zones et événements ne sont jamais supprimés physiquement. Les créneaux retirés sont supprimés mais leur état précédent est conservé dans `audit_log`. [RECO]

# 5. Invariants métier

1. La commune organisatrice a **obligatoirement** une participation, créée dans la même transaction que l'événement. Cette participation est `ACTIVE` d'emblée (A4). Elle ne peut ni être supprimée ni changer d'organisatrice.
2. Une commune a au plus une participation par événement (contrainte SQL + contrôle applicatif).
3. Une zone n'a **qu'un** rattachement : sa participation. Les opérations en lot refusent toute zone d'une autre participation (vérification par comptage : demandées = trouvées).
4. Seul un administrateur actif de la commune de la participation crée ou modifie ses zones [VALIDÉ] ; commune `ACTIVE` exigée en écriture (règle D16 du LOT 01). **Aucun droit pour CWS.**
5. Les communes participantes ne modifient pas les informations générales de l'événement [VALIDÉ].
6. « Commune entière » n'est jamais opérationnelle sans `confirmedAt` [VALIDÉ].
7. `EVENT_DURATION` : zéro créneau. `SPECIFIC_PERIODS` : au moins un créneau pour être opérationnelle. `UNCONFIGURED` : aucun droit effectif [VALIDÉ].
8. Créneau : heure de début différente de l'heure de fin ; pour un continu, l'instant de fin résolu strictement après l'instant de début ; récurrence avec `startDate <= endDate` et au moins un jour.
9. Transitions de l'événement et de la participation selon le SFD (A6 pour les cas non couverts). Suspension : motif obligatoire.
10. Une participation `CLOSED` est définitive. Un événement `CLOSED` est en lecture seule.
11. Les configurations appliquées en lot sont **indépendantes** : chaque zone reçoit ses propres créneaux, sans lien persistant.
12. Chaque écriture sensible écrit son audit dans la même transaction (acteur, ancienne et nouvelle valeur).

# 6. Validité temporelle : choix techniques recommandés

- **Stockage** : horaires **locaux** (DATE + TIME), jamais convertis. Si on stockait des instants UTC, un créneau « 22:00–06:00 » changerait d'heure locale au passage à l'heure d'été. Le type `datetime_immutable` surchargé par Civipass (UTC) n'est donc **pas** utilisé pour les créneaux. Les bornes d'événement, elles, restent des instants UTC.
- **Calcul** : un service pur `ZoneValidityCalculator` développe les créneaux en **intervalles UTC semi-ouverts `[début, fin)`**, les fusionne (union, chevauchements permis) puis les **borne** à `[startsAt, endsAt ou closedAt]`. Sans fin d'événement, la borne est ouverte. Aucune règle n'est encodée dans la base.
- **Récurrence** : chaque occurrence est rattachée à son **jour de début**. Les bornes de dates délimitent les jours de départ : un créneau 22:00–06:00 démarrant le dernier jour finit le lendemain matin. Quand l'heure de fin est antérieure à l'heure de début, la fin est le lendemain (passage de minuit).
- **Heure d'été et d'hiver (Europe/Paris)**, règles à valider (A5) :
  - heure **inexistante** (02:30 le jour du passage à l'heure d'été) : le premier instant valide après le saut (03:00) ;
  - heure **répétée** (02:30 le jour du retour à l'heure d'hiver) : début = première occurrence, fin = dernière occurrence, de sorte que le créneau couvre toute la plage voulue.
  Le comportement par défaut de PHP (décaler de la durée du saut) n'est pas retenu : il allongerait ou déformerait les créneaux. Une série de tests dédiés couvre les deux dates de transition.
- **Performance** : le calcul est à la demande, sans table d'occurrences. L'ordre de grandeur est d'au plus un intervalle par jour d'événement et par créneau.

# 7. Performance

- Listes d'événements et de participations en **projections DQL** avec `COUNT` groupé, jamais de collection chargée par entité.
- Zones d'une commune : une requête par participation ; créneaux chargés en **une** requête `IN (:zoneIds)` ; aucun chargement paresseux en boucle.
- Opérations en lot : une transaction, un verrou sur la ligne de participation, une requête de chargement des zones, une suppression `DELETE … WHERE zone IN`, insertions groupées, **un audit par zone** avec un identifiant de lot commun.
- Tests de non-régression du nombre de requêtes sur la liste des zones et sur les opérations en lot.

# 8. Arbitrages nécessaires

| # | Question | Recommandation |
|---|---|---|
| **A1** | **« Zone confirmée »** : les types et états du SFD ne contiennent pas « proposée/inactive avant confirmation ». | Champ `confirmedAt` séparé du statut. La zone créée par un administrateur est confirmée à la création ; seule la « Commune entière » auto-proposée attend sa confirmation. |
| **A2** | **Zone supprimée** : le SFD dit « suppression logique si utilisée ». | Toujours logique (`deletedAt`), jamais physique. |
| **A3** | **Type d'événement** : table de référence (SCR-053) ou enum ? | Entité `EventType` avec quelques types initiaux (incendie, inondation, évacuation, autre). À valider. |
| **A4** | **Participation de l'organisatrice** : état initial ? | `ACTIVE` dès la création de l'événement. |
| **A5** | **Fuseau et heure d'été** | Colonne `timezone` sur `Event`, limitée à `Europe/Paris` en V1 (évite une migration pour l'outre-mer) ; règles d'heure inexistante et répétée du §6 à valider. |
| **A6** | **Cas non couverts par le SFD** : une invitation jamais acceptée bloque indéfiniment la clôture normale ; quand une participation peut-elle être réactivée ; la clôture exige-t-elle l'organisatrice elle-même clôturée. | Une invitation non acceptée ne bloque pas la clôture et passe `CLOSED` avec l'événement ; réactivation possible tant que l'événement n'est pas `CLOSED`. |
| **A7** | **Matrice de rôles** : qui crée l'événement (SFD : gestionnaire, administrateur « selon son rôle »), qui accepte, suspend, clôture sa participation (SFD ou LOT-UI-02 ?). | Créer, activer, suspendre, clôturer l'événement, inviter : `EVENT_MANAGER` de l'organisatrice. Accepter une invitation : `MUNICIPALITY_ADMIN`. Suspendre, réactiver, clôturer sa participation : `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER`. Zones : `MUNICIPALITY_ADMIN` seul. Attention : l'activation exige une zone confirmée par un administrateur, donc deux rôles à cumuler pour l'organisatrice. |
| **A8** | **Quand la zone « Commune entière — [nom] » est-elle proposée** : à la création de la participation (y compris invitée) ou à l'acceptation ? Une commune invitée peut-elle créer des zones avant d'accepter ? | Création à l'ajout de la participation, mais zones modifiables seulement à partir de `ACTIVE`. Le SFD SCR-022 permet pourtant une demande vers une commune invitée. |
| **A9** | **Activation d'événement** : « au moins un périmètre valide » = une zone confirmée et active, ou aussi configurée dans le temps ? | Confirmée, active et **configurée** : une zone sans configuration n'ouvre aucun droit. |
| **A10** | **Champs du créneau continu** : date et heure de début et de fin (recommandé), ou dates seules ? | Date et heure des deux côtés. |
| **A11** | **Abonnement** : créer l'entité maintenant (hors des quatre entités demandées) ou garder un port temporaire ? | Port `SubscriptionStatusProvider`, entité dans son lot. |
| **A12** | **Modification d'un événement actif** (nom, dates) | Modifiable jusqu'à la clôture, tracée ; à confirmer. |
| **A13** | **Création d'événement par une commune suspendue ou non abonnée** (D9, D16 du LOT 01) | Refusée ; la participation gratuite d'une commune non abonnée reste possible. |
| **A14** | **Référence publique d'événement** (`public_reference` du schéma V1.0) | Reporter au lot Dépôt (URL propre à chaque commune et événement, donc plutôt sur la participation). |
| **A15** | **Impersonation CWS** (SFD Annexe B) | Hors périmètre de ce lot ; aucun droit communal implicite pour CWS. |

# 9. Documents à mettre à jour (après validation)

- `docs/data-model.md` : retirer `Zone` et les instantanés, redéfinir `EventZone`, ajouter `EventZoneValidity` et `EventType`.
- `docs/architecture.md`, `development-roadmap.md` : retirer « snapshots ».
- `docs/security.md` : matrice des rôles pour zones et participations, ligne CWS (supervision réduite aux opérations déjà définies au LOT 01).
- Spécifications à corriger : CDC §4.1 (clôture), SFD SCR-016 à 018 (rôle), STD (dictionnaire des entités, abandon de `zone`, validité).

# 10. Plan d'implémentation de TECH-02.1 (après validation)

1. **Documents** : mise à jour des documents ci-dessus et `docs/lot-02-schema.md` (dictionnaire final), avec validation avant toute migration.
2. **Domaine pur** : enums, objets valeur, créneaux avec leurs fabriques, `ZoneValidityCalculator` et ses tests DST. Aucune base.
3. **Entités et repositories** Doctrine (`Event`, `EventParticipation`, `EventZone`, `EventZoneValidity`, `EventType` si A3), mappings du module.
4. **Migration unique**, relue avant exécution, jouée sur la base de test seulement.
5. **Services applicatifs** : création d'événement avec participation de l'organisatrice, invitation, acceptation, cycle de vie, zones (création, confirmation, suspension), validité, opérations en lot. Transactions, verrous, audit atomique, politiques et voters.
6. **Tests** : unitaires, intégration (concurrence, requêtes), isolation inter-communes avec identifiants forgés, absence de droit CWS, transitions complètes.

**Hors lot** : contrôleurs, écrans, maquettes UI-02, Sonata, cartographie, abonnements, laissez-passer.

Merci de trancher les arbitrages, en priorité A1, A3, A4, A5, A6, A7, A8 et A9, qui conditionnent le modèle. Je n'écris aucun code avant votre retour.
---

# Addendum — révision 2 : arbitrages validés

## R2.1 Statut des arbitrages

| Arbitrage | Statut | Décision validée | Fiche |
|---|---|---|---|
| A1 | **Validé** (option A) | `confirmedAt` distinct du statut ; zone manuelle confirmée à la création, « Commune entière » manuelle confirmée explicitement, automatique non confirmée ; aucun statut « À confirmer » | [DM-051](../../decisions/README.md) |
| A2 | **Validé** (option A) | Suppression toujours logique (`deletedAt`) | DM-052 |
| A3 | **Validé** (option A) | Entité `EventType`, évolutive, sans interface d'administration au LOT 02 ; **liste initiale à valider** | DM-053 |
| A4 | **Validé** (option A) | Événement `DRAFT`, participation organisatrice `ACTIVE` automatique | DM-053 |
| A5.1 | **Validé** (A) | `timezone` sur l'événement, `Europe/Paris` | DM-054 |
| A5.2 | **Validé** (C) | Heure inexistante refusée avec explication | DM-055 |
| A5.3 | **Validé** (C) | Heure répétée signalée, choix de l'utilisateur, représentation non ambiguë | DM-055 |
| A6.1 | **Validé** (A) | `INVITED` ne bloque pas la clôture ; passe `CLOSED` | DM-056 |
| A6.2 | **Validé** (A) | Réactivation tant que l'événement n'est pas clôturé | DM-056 |
| A6.3 | **Validé** (B) | Participation organisatrice clôturée automatiquement avec l'événement, autres participations clôturées au préalable | DM-056 |
| A7 | **Validé** (A modifiée) | L'administrateur cumule les droits du gestionnaire ; impersonation CWS (exigences anticipées) | DM-057, DM-061 |
| A8 | **Validé** (A) | « Commune entière » proposée à la création de la participation, non confirmée ; `INVITED` sans écriture sur les zones | DM-058 |
| A9 | **Validé** (A) | Activation : zone confirmée, active, non supprimée, configurée (période future acceptée) | DM-059 |

Principe UX validé : configuration simple par défaut, avancée à la demande ([DM-060](../../decisions/README.md)).
Ajustements validés : ponctuel = une date, début strictement avant fin ; récurrence = dates de début et de fin obligatoires.

## R2.2 Écarts entre la révision 1 (§3 à §8) et la révision 2

| Passage de la révision 1 | Devenu |
|---|---|
| §3.3 `EventZone.confirmedAt` « `NULL` = proposée » ; zone manuelle | Précisé : manuelle confirmée à la création ; « Commune entière » manuelle : confirmation explicite dans le formulaire |
| §3.4 `EventZoneValidity` sans choix d'occurrence | Ajout proposé de `dstChoices` (DT-020, **à valider**) |
| §5 invariant 4 (« administrateur seul » sur les zones) | Maintenu pour les **zones** ; l'administrateur a en plus tous les droits du gestionnaire |
| §6 heure inexistante = premier instant valide ; heure répétée = début première, fin dernière | **Remplacé** : refus (inexistante) ; choix explicite de l'utilisateur (répétée) |
| §8 A5, A6, A7, A9 « à valider » | Validés (voir R2.1) |
| §8 A15 impersonation « hors périmètre » | Exigences anticipées (DM-061) ; développement dans un lot dédié |
| §3.1 `Event.timezone` absent | Ajout de `timezone` (DM-054) |

## R2.3 Écarts résolus et restants

Voir [`../../revisions/CONTRADICTIONS.md`](../../revisions/CONTRADICTIONS.md) : sections A (résolues), B (documentaires restantes), C (points techniquement ambigus R1 à R10, A10 à A14, DT-020, DT-022).

## R2.4 Ce qui reste ouvert pour ce lot

A10 (champs du créneau continu), A11 (abonnement), A12 (modification d'un événement actif), A13 (création par commune suspendue ou non abonnée), A14 (`public_reference`), liste initiale d'`EventType`, structure de stockage du choix d'occurrence horaire (DT-020), opérateur réel dans l'audit (DT-022), points R1 à R9.

## R2.5 Documents associés

[Schéma LOT 02](../../lot-02-schema.md) · [Plan d'implémentation](plan-implementation.md) · [Révision SFD](../../revisions/SFD-V1.1-proposition-lot02.md) · [Révision STD](../../revisions/STD-V1.2-proposition-lot02.md) · [Matrice SF/RM](../../references/SFD/ANALYSE-matrice-sfrm.md).

---

# Addendum — révision 3 : R1 à R9 et A10 à A14 validés

## R3.1 Décisions

| Id | Décision validée | Fiche | Effet sur la révision 2 |
|---|---|---|---|
| R1 | Gestionnaire d'événement : créer, modifier, activer, suspendre, clôturer les événements de sa commune | DM-057 | Lève R1 |
| R2 | Seul l'administrateur communal accepte une invitation | DM-057 | Lève R2 |
| R3 | Récurrence avec heure inexistante : refusée, date identifiée, correction demandée | DM-063 | Lève R3 |
| R4 | Récurrence avec heure ambiguë : choix explicite conservé par date ; stockage JSON à valider | DM-064, DT-020 | Lève R4 (stockage ouvert) |
| R5 | Clôture forcée exceptionnelle depuis Sonata, super-admin CWS habilité, justification, audit | DM-065, DT-028 | Lève R5 |
| R6 | Participation `SUSPENDED` : zones modifiables, jamais opérationnelles | DM-066 | Lève R6 |
| R7 | Activation : zone d'une participation `ACTIVE` | DM-059 | Lève R7 |
| R8 | « Commune entière » automatique supprimable, non recréée | DM-068 | Lève R8 |
| R9 | Outre-mer dès la V1 ; fuseau IANA ; remplace A5.1 | DM-062, DT-026 | **Remplace DM-054** |
| A10 | Créneau continu : date et heure de début et de fin | DM-069 | Lève A10 |
| A11 / A13 | Abonnement actif + commune active pour créer ; non abonnée peut participer | DM-070, DT-027 | Lève A11, A13 (voir P1) |
| A12 | Événement actif modifiable ; organisatrice, type, fuseau, référence verrouillés | DM-071 | Lève A12 (voir P3) |
| A14 | Référence publique `EVT-YYYY-NNNNNN` | DM-072, DT-025 | Lève A14 |

## R3.2 Écarts entre les révisions précédentes et la révision 3
- §3.1 (`Event`) : ajout de `publicReference`, `operationalInstructions` ; `timezone` n'est plus limité à `Europe/Paris`.
- §3.4 (`EventZoneValidity`) : `dstChoices` (JSON) pour les trois types ; plus de `startFold`/`endFold`.
- §5 invariant 4 : l'écriture sur une zone est permise pour une participation `ACTIVE` **ou `SUSPENDED`**.
- §8 A5.2/A5.3 : règles génériques pour tout fuseau IANA de la liste blanche.
- Révision 2, point A5.1 : **remplacé** par R9.

## R3.3 Contradictions résolues et restantes
Voir [CONTRADICTIONS.md](../../revisions/CONTRADICTIONS.md) : S2, S9, N4, N5, REC, CDC §4.1 traités ; **restent à valider** : P1 (dont A13 vs D16/D17), P3, DT-020, DT-022 (bloquants avant migration), P2, P4, P5, P6.

## R3.4 Livraison UI-02
Non intégrée. 47 écarts avec les règles validées : [ECARTS-UI-02.md](../../revisions/ECARTS-UI-02.md) (10 écarts bloquants dont statut « À confirmer », activation, participation organisatrice `draft`, fuseau en dur, heures corrigées silencieusement).

---

# Addendum — révision 4 : P1 à P6, DT-020, DT-022 validés

| Id | Décision validée | Fiche | Effet |
|---|---|---|---|
| P1 | Création : abonnement actif ou TRIAL valide ; expiration = plus de création seulement ; événements ouverts et capacités de gestion préservés ; suspension administrative de sécurité distincte ; service centralisé ; module Abonnements hors lot | DM-070, DM-073 | Résout A13 vs D16/D17 |
| P2 | Fuseau proposé selon la commune organisatrice ; un fuseau IANA par événement ; visible avant, verrouillé après activation ; outre-mer dont Polynésie ; référentiel territorial | DM-062, DT-026 | Complète R9 |
| P3 | `operationalInstructions` ; début verrouillé, fin modifiable ; `EVENT_DURATION` suit la durée ; réductions immédiates ; audit | DM-071 | Complète A12 |
| P4 | `superAdmin` conservé ; `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT` ; développement reporté (lot Administration CWS) | DM-074, DT-029 | **Retire la clôture forcée du LOT 02** (R5) |
| P5 | Compteur global permanent, sans remise à zéro annuelle, 6 chiffres minimum extensibles | DM-072, DT-025 | Remplace le compteur annuel de la révision 3 |
| P6 | Créneaux supprimés logiquement (`deletedAt`), reconfiguration avec historique | DM-075 | **Remplace la suppression physique** de la révision 1 (§4) |
| DT-020 | `dstChoices` JSON sur `EventZoneValidity` | DT-020 | Validée |
| DT-022 | `actor_id`, `operator_id`, `batch_id` dès la première migration | DT-022 | Validée |

## R4.1 Écarts avec les révisions précédentes
- §4 de la révision 1 (« les créneaux retirés sont supprimés ») : **remplacé** par la suppression logique (P6).
- Révision 3 : compteur annuel (DT-025) → compteur global permanent ; liste blanche de fuseaux → référentiel territorial ; `EventClosureService` forcé → reporté.
- Invariants : 18 → 21 (modifiés : 5, 6, 7, 9, 10, 15, 17 ; nouveaux : 19 à 21).

## R4.2 Vérifications effectuées
Cohérence des suppressions logiques zone / créneau / suspension, index, contraintes d'unicité et transactions : voir [`../../lot-02-schema.md`](../../lot-02-schema.md) §5, §7, §8. **Aucun point bloquant** ; points de détail T1 à T6 dans [CONTRADICTIONS.md](../../revisions/CONTRADICTIONS.md).

---

# Addendum — révision 5 : T1, DRAFT → CLOSED, types d'événements

| Sujet | Décision validée | Fiche | Effet sur les révisions précédentes |
|---|---|---|---|
| T1 | Création : commune `ACTIVE` + au moins un droit d'utilisation valide (abonnement actif, TRIAL valide, **accès commercial CWS** par commune, expiration facultative, révocable, traçable) ; abonnement payant non obligatoire ; port `MunicipalityEntitlementProvider` ; implémentation provisoire sans module Abonnements | DM-076, DT-027, DT-030 | **Complète** P1/DM-070 (la règle « abonnement actif ou TRIAL valide » était incomplète) |
| DRAFT → CLOSED | Autorisée ; définitive ; participations clôturées dans la même transaction ; audit ; aucun nouveau statut | DM-077 | Lève le point « transition non définie » |
| Types | Neuf types : fire, flood, storm, accident, security, sport, cultural, works, other ; initialisation idempotente | DM-053, DT-023 | **Remplace** la liste provisoire (incendie, inondation, accident, événement sportif, manifestation, autre) |

## R5.1 Vérifications
Aucune réintroduction de la bibliothèque de zones, des instantanés, du compteur annuel, de la suppression physique des créneaux ni de l'obligation d'un abonnement payant : voir [`../../lot-02-schema.md`](../../lot-02-schema.md) (révision 3), §4.1, §7, §8.2, §10. Documents harmonisés : `architecture.md`, `development-roadmap.md`, `security.md`, propositions SFD V1.1 / STD V1.2, plan. Les documents historiques (originaux, `lot-01-schema.md`, révisions antérieures de l'audit) ne sont pas modifiés.
**Aucun point bloquant** pour l'étape 1 ; DT-030 (table d'accès commercial) à valider avant l'étape 3.
