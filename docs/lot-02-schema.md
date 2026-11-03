# LOT 02 (TECH-02.1) — Schéma de données et règles : proposition définitive

> **Révision 6 (étapes 2 et 3 : persistance, migration et validation MySQL, §15 et §16 ; étape 4 : politiques d'accès, §17) — proposition soumise à validation. Aucune entité, aucune migration, aucun code.** Ce document remplace [`lots/tech-02.1/modele-doctrine.md`](lots/tech-02.1/modele-doctrine.md) (conservé comme trace historique). Conformément à la règle 3 du `CLAUDE.md`, aucune migration ne sera écrite avant sa validation explicite.
> Décisions sources : [registre](decisions/README.md) (A1–A14, R1–R9, P1–P6 : DM-051 à DM-075 ; DT-020, DT-022). Points résiduels de détail T2 à T6 : [`revisions/CONTRADICTIONS.md`](revisions/CONTRADICTIONS.md).

## 1. Périmètre

Module `src/Event/{Domain,Application,Infrastructure}` : événements, types d'événement, participations, zones, validités temporelles, référence publique. **Hors périmètre** : demandes, instruction, laissez-passer, contrôle, UI (UI-02 non intégrée), module de gestion des abonnements (seul un port est prévu), **impersonation et clôture forcée CWS (reportées au lot Administration CWS, DM-074)**, MFA, administration des types. TECH-02.1 **prépare** seulement leurs mécanismes (audit, énumération de permissions, port de vérification).

Conventions du dépôt : UUID v7 `BINARY(16)` ; dates UTC (type `datetime_immutable` surchargé) ; énums PHP en `VARCHAR` ; FK en `RESTRICT` ; aucune suppression physique de données métier ; tables préfixées par le module ; audit dans la transaction ; `TransactionRunner`.

## 2. Vue d'ensemble

```
Municipality ─< EventParticipation >─ Event ─> organizerMunicipality (Municipality)
                      │                  ├──> EventType
                      │                  └──  public_reference (compteur global permanent)
                      └─< EventZone ─< EventZoneValidity
```

Cinq entités + une table de compteur technique. Pas d'entité supplémentaire pour les créneaux : `EventZoneValidity` est le créneau (un discriminant `kind` suffit ; trois tables ou un héritage Doctrine multiplieraient jointures et mappings pour des formes qui partagent leurs colonnes). Pas d'entité pour le choix d'occurrence horaire : colonne JSON (§6.4), alternative documentée.

## 3. Énumérations

| Enum | Valeurs | Source |
|---|---|---|
| `EventStatus` | `DRAFT`, `ACTIVE`, `SUSPENDED`, `CLOSED` | SFD §2 |
| `ParticipationStatus` | `INVITED`, `ACTIVE`, `SUSPENDED`, `CLOSED` | SFD §2 |
| `ZoneStatus` | `ACTIVE`, `SUSPENDED` | SFD §2 (aucun « À confirmer », DM-051) |
| `ZoneType` | `WHOLE_MUNICIPALITY`, `DISTRICT`, `STREET`, `LOCALITY`, `CUSTOM` | SFD SCR-017 |
| `ValidityMode` | `UNCONFIGURED`, `EVENT_DURATION`, `SPECIFIC_PERIODS` | DM-011 (mode de configuration, pas un statut métier) |
| `ValidityKind` | `CONTINUOUS`, `PUNCTUAL`, `RECURRING` | DM-014 |
| `EntitlementSource` (port d'usage commercial) | `SUBSCRIPTION`, `TRIAL`, `CWS_GRANT` | DM-076 |
| `CwsPermission` (préparée, non persistée) | `CWS_IMPERSONATE`, `CWS_FORCE_CLOSE_EVENT` | DM-074, DT-029 |

## 4. Entités

### 4.1 `EventType` — `event_type`
| Champ | Type | Null | Contrainte |
|---|---|---|---|
| `id` | `uuid` | non | PK |
| `code` | `string(40)`, `utf8mb4_bin` | non | `UNIQUE`, `[a-z0-9_]+` |
| `label` | `string(100)` | non | |
| `sortOrder` | `smallint` | non | défaut 0 |
| `active` | `boolean` | non | défaut vrai |

Un type utilisé ne se supprime pas (FK `RESTRICT`). Pas d'interface d'administration au LOT 02 (DM-053). Évolutif, non limité aux crises ; types = données de référence.
**Liste initiale validée** (DT-023), initialisation reproductible et idempotente (clé `code`) :

| `code` | `label` |
|---|---|
| `fire` | Incendie |
| `flood` | Inondation |
| `storm` | Tempête / événement climatique |
| `accident` | Accident / incident majeur |
| `security` | Sécurité publique |
| `sport` | Manifestation sportive |
| `cultural` | Manifestation culturelle |
| `works` | Travaux / intervention technique |
| `other` | Autre événement |

### 4.2 `Event` — `event_event`
(`event` est un mot-clé MySQL : préfixe de module conservé.)

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `publicReference` | `string(20)` | non | `EVT-YYYY-NNNNNN` (6 chiffres minimum, extensible), `UNIQUE`, immuable (DM-072) |
| `organizerMunicipality` | ManyToOne `Municipality` | non | immuable (DM-001, DM-071) |
| `type` | ManyToOne `EventType` | non | verrouillé après activation (DM-071) |
| `name` | `string(200)` | non | modifiable |
| `description` | `text` | oui | modifiable |
| `operationalInstructions` | `text` | oui | consignes et informations opérationnelles (DM-071, P3) ; modifiable jusqu'à la clôture |
| `timezone` | `string(40)` | non | **un seul** identifiant IANA par événement, proposé selon la commune organisatrice (DT-026), visible avant activation, verrouillé après (DM-062) |
| `startsAt` | `datetime_immutable` UTC | non | saisi dans le fuseau de l'événement ; **verrouillé après activation** (P3) |
| `endsAt` | `datetime_immutable` UTC | oui | `> startsAt` si renseignée ; **modifiable après activation** (P3) |
| `status` | `EventStatus` | non | |
| `suspensionReason` | `text` | oui | obligatoire à la suspension ; **dernière suspension connue** (DM-081) |
| `suspendedAt` | datetime UTC | oui | date de la dernière suspension connue (DM-081) ; migration `Version20261103072308` |
| `activatedAt`, `closedAt` | datetime UTC | oui | |
| `createdBy` | ManyToOne `User` | non | |
| `createdAt`, `updatedAt` | datetime UTC | non | |

Index : `UNIQUE(public_reference)`, `(organizer_municipality_id, status)`, `(status, starts_at)`.
**Après activation** (DM-071) : `organizerMunicipality`, `type`, `timezone`, `publicReference` et `startsAt` verrouillés ; modifiables : `name`, `description`, `operationalInstructions`, `endsAt`, invitations, zones, validités, participations. Les zones `EVENT_DURATION` suivent `startsAt`/`endsAt` ; les créneaux spécifiques ne sont jamais prolongés ; toute réduction de durée est effective immédiatement (calcul à la demande). Toute modification est auditée (avant/après).

### 4.3 `event_reference_counter` (table technique)
Une **seule ligne** : `id SMALLINT UNSIGNED PK` (valeur constante 1), `last_number BIGINT UNSIGNED NOT NULL DEFAULT 0`. Compteur **global permanent** (P5, DM-072) : jamais réinitialisé, indépendant de l'année. Ligne initiale (`last_number = 0`) insérée par la migration. Sert uniquement à générer `publicReference` (§8.4). Pas d'entité métier.

### 4.4 `EventParticipation` — `event_participation`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `event` | ManyToOne `Event` | non | |
| `municipality` | ManyToOne `Municipality` | non | |
| `status` | `ParticipationStatus` | non | |
| `invitedBy` | ManyToOne `User` | oui | `NULL` pour l'organisatrice |
| `invitationMessage` | `text` | oui | |
| `invitedAt`, `lastInvitationSentAt` | datetime UTC | oui | |
| `activatedAt`, `suspendedAt`, `closedAt` | datetime UTC | oui | |
| `suspensionReason` | `text` | oui | obligatoire à la suspension |
| `createdAt`, `updatedAt` | datetime UTC | non | |

`UNIQUE(event_id, municipality_id)` (DM-004). Index `(municipality_id, status)`.

### 4.5 `EventZone` — `event_zone`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `participation` | ManyToOne `EventParticipation` | non | seul rattachement |
| `label` | `string(150)` | non | doublon : avertissement, pas de blocage |
| `type` | `ZoneType` | non | |
| `description` | `text` | oui | |
| `status` | `ZoneStatus` | non | |
| `confirmedAt` | datetime UTC | oui | `NULL` = non confirmée (DM-051) |
| `validityMode` | `ValidityMode` | non | défaut `UNCONFIGURED` |
| `deletedAt` | datetime UTC | oui | suppression logique (DM-052) |
| `createdBy` | ManyToOne `User` | non | |
| `createdAt`, `updatedAt` | datetime UTC | non | |

Index `(participation_id, status, deleted_at)`.
Création : zone manuelle → `confirmedAt = now` (pour `WHOLE_MUNICIPALITY` manuelle, l'action exige la case « je confirme ce périmètre » dans la commande) ; « Commune entière » automatique → `confirmedAt = NULL`, `UNCONFIGURED`.

### 4.6 `EventZoneValidity` — `event_zone_validity`
Un créneau. Horaires **locaux** (DATE + TIME) dans le fuseau de l'événement ; aucune colonne UTC.

| Champ | Type | Continu | Ponctuel | Récurrent |
|---|---|---|---|---|
| `id` | `uuid` | | | |
| `zone` | ManyToOne `EventZone` | oui | oui | oui |
| `kind` | `ValidityKind` | `CONTINUOUS` | `PUNCTUAL` | `RECURRING` |
| `startDate` | `date_immutable` | début | la date unique | début de période |
| `startTime` | `time_immutable` | oui | oui | oui |
| `endDate` | `date_immutable` | oui | `NULL` | oui, obligatoire |
| `endTime` | `time_immutable` | oui | oui | oui |
| `weekdays` | `smallint` (bits Lun=1 … Dim=64) | `NULL` | `NULL` | 1 à 127 |
| `dstChoices` | `json` | `NULL` ou liste | `NULL` ou liste | `NULL` ou liste |
| `deletedAt` | datetime UTC | `NULL` si en vigueur | idem | idem | suppression logique (DM-075) ; ne se confond pas avec la suspension d'une zone |
| `createdAt`, `updatedAt` | datetime UTC | | | |

Index `(zone_id, deleted_at)`. Fabriques `continuous()`, `punctual()`, `recurring()` ; aucun constructeur public permettant un créneau incohérent. Règles des champs : DM-014 à DM-018, DM-069.

## 5. Relations, suppressions logiques, intégrité

- Toutes les FK `RESTRICT` ; **aucune suppression physique** d'événement, de participation, de zone, de créneau, de type utilisé.
- **Suppressions logiques** (cohérence vérifiée) :

| Objet | Colonne | Effet | Propagation | Restauration |
|---|---|---|---|---|
| Zone | `event_zone.deleted_at` | zone non opérationnelle, absente des listes ordinaires | **aucune** vers ses créneaux (la zone entière est exclue des calculs) | aucune implicite |
| Créneau | `event_zone_validity.deleted_at` | créneau exclu de tous les calculs d'accès | aucune vers la zone | aucune implicite |
| Suspension de zone | `event_zone.status = SUSPENDED` | zone sans droits, **réversible** | aucune | par réactivation explicite |

Une zone suspendue conserve ses créneaux non supprimés ; un créneau supprimé ne dépend pas du statut de la zone ; une zone supprimée n'est jamais « réactivée » par la restauration d'un créneau.
- **Reconfiguration** (DM-075) : dans une transaction, les créneaux non supprimés de la zone reçoivent `deleted_at`, les nouveaux sont créés, le mode de validité est mis à jour, l'audit (avec `batch_id`) enregistre l'ancien et le nouvel état. La modification d'un créneau unique suit le même schéma (remplacement) **[T4, à confirmer]**.
- Contraintes SQL : `UNIQUE(event_id, municipality_id)`, `UNIQUE(event_type.code)`, `UNIQUE(event_event.public_reference)`. Les autres invariants (§7) sont portés par le domaine et les tests. L'unicité d'une « Commune entière » non supprimée par participation est garantie par le verrou de participation et le contrôle domaine (une contrainte SQL sur colonne générée est possible, non retenue par défaut). `CHECK` simples (`weekdays` 1–127, `start_time <> end_time`) : à décider à la rédaction de la migration.
- **Index** : `event_event(public_reference)` unique, `(organizer_municipality_id, status)`, `(status, starts_at)` ; `event_participation(municipality_id, status)` ; `event_zone(participation_id, status, deleted_at)` ; `event_zone_validity(zone_id, deleted_at)` ; `audit_log(operator_id)`, `audit_log(batch_id)`.

## 6. Validité temporelle et fuseaux

### 6.1 Principes (DM-011 à DM-021, DM-062 à DM-064, DM-069)
Stockage local ; instants UTC dérivés par le `ZoneValidityCalculator` (intervalles semi-ouverts `[début, fin)`, union des créneaux, bornage à l'événement) ; `EVENT_DURATION` sans créneau ; `SPECIFIC_PERIODS` ≥ 1 créneau ; `UNCONFIGURED` = aucun droit ; chevauchements autorisés.

### 6.2 Heure inexistante (DM-055, DM-063)
Refus avec explication. Pour une récurrence : validation de toutes les dates de la série concernées par une transition (compte tenu des jours sélectionnés) ; message nommant la date et l'horaire. Aucun ajustement, aucune occurrence supprimée.

### 6.3 Heure ambiguë (DM-055, DM-064)
Choix explicite `first` / `second` pour chaque (date, champ `start`/`end`) ambigu.

### 6.4 Stockage de l'ambiguïté (**DT-020, validée**) et conséquences des modifications
`dstChoices` : `[{"date":"2026-10-25","field":"start","occurrence":"second"}]`.
- Le domaine calcule l'ensemble exact des couples ambigus du créneau ; `dstChoices` doit le contenir **entièrement**, sans entrée en trop ; sinon refus d'enregistrement.
- **Modification d'un créneau** (remplacement : ancien créneau supprimé logiquement, nouveau créé) : le nouveau créneau ne reprend que les choix encore valides (même date, même champ, heure inchangée) ; tout nouveau couple ambigu exige un choix explicite ; les couples disparus ne sont pas reportés ; si `startTime` ou `endTime` change, les choix correspondants sont invalidés et ressaisis. Aucun choix par défaut. Les créneaux supprimés conservent leur `dstChoices` pour l'historique.
- **Reconfiguration groupée** : chaque zone reçoit ses propres créneaux et donc ses propres choix ; l'opération est refusée en bloc si l'une des zones présente une heure inexistante, ou si un choix manque (l'interface demande le choix avant l'envoi).
- **Changement de règles horaires** (données tz) : une commande de revalidation (proposition) détecte les créneaux futurs devenus invalides ou ambigus.
- Alternative écartée : table fille (une entité en plus).

### 6.5 Outre-mer et référentiel territorial (DM-062, P2, DT-026)
Un seul fuseau IANA par événement, pour toutes ses zones et participations ; proposé automatiquement d'après la commune organisatrice, visible avant activation, verrouillé après.
**Référentiel territorial** : fichier de données versionné du dépôt (provenance et empreinte, sans interface d'administration), consommé par un `TerritoryTimezoneResolver` : 
- hexagone et Corse : `Europe/Paris` ;
- 971 `America/Guadeloupe`, 972 `America/Martinique`, 973 `America/Cayenne`, 974 `Indian/Reunion`, 975 `America/Miquelon`, 976 `Indian/Mayotte`, 977 `America/St_Barthelemy`, 978 `America/Marigot`, 986 `Pacific/Wallis`, 988 `Pacific/Noumea` ;
- 987 Polynésie française : `Pacific/Tahiti` par défaut, avec **exceptions par commune** pour les Marquises (`Pacific/Marquesas`) et les Gambier (`Pacific/Gambier`).
Le tableau départemental ci-dessus est un point de départ à valider ; **la liste des communes polynésiennes par archipel n'est pas fournie ici** : elle doit être établie à partir d'une source officielle (COG INSEE et liste officielle par archipel) puis validée avant intégration (aucun code commune n'est supposé).
Liste blanche `EventTimezone` : les fuseaux ci-dessus. Seuls `Europe/Paris` et `America/Miquelon` ont une heure d'été ; les règles R3/R4 sont génériques. Tests : une transition par fuseau à heure d'été, des cas sans transition (`Indian/Reunion`, `Pacific/Tahiti`, `Pacific/Marquesas` à −9:30, `Pacific/Gambier`), la résolution commune → fuseau pour chaque territoire.

## 7. Invariants métier (révisés : 21)

Les invariants 1 à 18 de la révision 1 sont conservés ; ceux dont le texte change sont marqués **(modifié)**, les nouveaux sont 19 à 21.

1. Un événement a une participation organisatrice `ACTIVE`, créée dans la même transaction (DM-053) ; elle n'est ni supprimable ni transférable.
2. Une participation par couple événement/commune.
3. Une zone n'a qu'une participation ; les opérations groupées refusent toute zone d'une autre participation (comptage demandé = trouvé, DM-028).
4. Écriture sur une zone : administrateur actif de la commune de la participation, commune `ACTIVE`, participation `ACTIVE` ou `SUSPENDED` (jamais `INVITED` ni `CLOSED`) (DM-007, DM-009, DM-057, DM-066).
5. **(modifié)** Informations générales de l'événement : organisatrice uniquement (DM-001). Après activation : organisatrice, type, fuseau, référence publique **et date de début** verrouillés ; nom, description, consignes, date de fin modifiables jusqu'à la clôture (DM-071).
6. **(modifié)** Une zone n'ouvre aucun droit si elle est : non confirmée, supprimée, suspendue, `UNCONFIGURED`, de participation non `ACTIVE`, d'un événement non `ACTIVE`, ou hors de l'union de ses créneaux **non supprimés** (DM-019, DM-051, DM-052, DM-056, DM-066, DM-075).
7. **(modifié)** `EVENT_DURATION` : aucun créneau non supprimé (les créneaux supprimés sont conservés pour l'historique). `SPECIFIC_PERIODS` : au moins un créneau non supprimé pour être opérationnelle.
8. Créneaux : continu = début et fin complets, fin strictement après début ; ponctuel = une date, début strictement avant fin (même jour) ; récurrent = dates de début et de fin obligatoires, `startDate ≤ endDate`, jours 1–127, heures différentes, minuit autorisé ; pour chaque date de série concernée : refus si heure inexistante, choix explicite si ambiguë.
9. Une seule « Commune entière » **non supprimée** par participation ; la proposition automatique est créée une fois et n'est pas recréée (DM-068).
10. Activation `DRAFT → ACTIVE` : au moins une zone confirmée, active, non supprimée, configurée (au moins un créneau non supprimé si `SPECIFIC_PERIODS`), de participation `ACTIVE` ; période future admise (DM-059).
11. Clôture normale : participations `ACTIVE`/`SUSPENDED` des autres communes déjà `CLOSED` ; `INVITED` et organisatrice → `CLOSED` avec l'événement, même transaction, audité (DM-056). **Un événement `DRAFT` peut être clôturé sans prérequis de participation active : participation organisatrice et invitations → `CLOSED` dans la même transaction, audit obligatoire (DM-077).** La clôture forcée est reportée (DM-065, DM-074).
12. Participation `CLOSED` et événement `CLOSED` définitifs et en lecture seule : aucune réactivation, y compris pour un événement clôturé depuis `DRAFT`.
13. Suspension (événement ou participation) : motif obligatoire.
14. Réactivation d'une participation : tant que l'événement n'est pas `CLOSED` ; ses zones ne sont opérationnelles que si l'événement est `ACTIVE`.
15. **(modifié)** Création d'événement : commune administrativement `ACTIVE` ET au moins un **droit d'utilisation valide** (abonnement actif, TRIAL valide ou accès commercial CWS, DM-076), contrôle serveur dans le service centralisé `EventCreationPolicy`. Aucun contrôle de droit d'utilisation sur les événements existants ; l'expiration ou la révocation d'un droit n'altère pas la gestion des événements existants (DM-073, DM-076). Un abonnement payant n'est pas obligatoire.
16. Opérations groupées : confirmation avant écrasement, configurations indépendantes, pas d'héritage (DM-025 à DM-027).
17. **(modifié)** Toute modification réduisant des droits effectifs (suppression ou suspension de zone, réduction de `endsAt`, suppression de créneau) prend effet immédiatement : droits calculés à la demande, jamais copiés ; les zones `EVENT_DURATION` suivent la durée ; les créneaux spécifiques ne sont jamais prolongés (DM-071).
18. Chaque écriture sensible écrit son audit dans la même transaction (§9).
19. **(nouveau)** Un créneau supprimé logiquement n'est jamais réactivé, jamais supprimé physiquement, jamais pris en compte dans un calcul ; sa suppression ne modifie pas le statut de la zone (DM-075).
20. **(nouveau)** Un événement a un seul fuseau IANA appartenant à la liste blanche ; toutes ses zones, créneaux et participations s'y rapportent ; il est modifiable avant activation, verrouillé ensuite (DM-062).
21. **(nouveau)** La référence publique est unique, immuable, issue du compteur global permanent ; son numéro augmente strictement et ne se réinitialise jamais (DM-072).

## 8. Permissions, transitions, transactions

### 8.1 Matrice des permissions (DM-057, DM-074)
Contextualisée par commune ; rôles cumulables.

| Action | Administrateur communal | Gestionnaire d'événement | Agent instructeur | CWS |
|---|---|---|---|---|
| Créer un événement (commune active + droit d'utilisation valide) | Oui | Oui | Non | Non (aucun droit implicite) |
| Modifier / activer / suspendre / clôturer un événement de sa commune | Oui | Oui | Non | Non |
| Inviter des communes | Oui | Oui | Non | Non |
| **Accepter** une invitation | Oui | **Non** (sauf rôle admin cumulé) | Non | Non |
| Suspendre / réactiver / clôturer **sa** participation | Oui | Oui | Non | Non |
| Gérer ses zones, validités, opérations groupées | Oui | **Non** (sauf rôle admin cumulé) | Non | Non |
| Lire les zones d'autres communes de l'événement | Lecture seule | Lecture seule | À définir au lot Demandes | Non |
| Impersonation | — | — | — | `CWS_IMPERSONATE` (lot Administration CWS) |
| Clôture forcée d'un événement | — | — | — | `CWS_FORCE_CLOSE_EVENT` (lot Administration CWS) |

`superAdmin` ne donne aucune des deux permissions CWS (non attribuées automatiquement).

### 8.2 Transitions
- **Événement** : `DRAFT → ACTIVE` (garde invariant 10) ; `ACTIVE ↔ SUSPENDED` (motif) ; `ACTIVE`/`SUSPENDED → CLOSED` (normal) ; **`DRAFT → CLOSED` autorisé** (DM-077). Aucun statut supplémentaire.
- **Participation** : `INVITED → ACTIVE` (administrateur) ; `ACTIVE ↔ SUSPENDED` ; `ACTIVE`/`SUSPENDED → CLOSED` ; `INVITED → CLOSED` uniquement par clôture de l'événement.
- **Zone** : `ACTIVE ↔ SUSPENDED` ; suppression logique ; pas de restauration implicite. **Créneau** : création ; suppression logique ; aucune transition de retour.
Implémentation prévue avec `symfony/workflow` (configuration vide aujourd'hui).

### 8.3 Transactions (`TransactionRunner`) et verrous
| Cas d'usage | Contenu | Verrous (DT-024) |
|---|---|---|
| Créer un événement | compteur de référence + événement + participation organisatrice + zone proposée + audit | ligne `event_reference_counter` |
| Inviter / renvoyer | participation `INVITED` + zone proposée + audit | événement |
| Accepter | participation → `ACTIVE` + audit | participation |
| Activer | garde + statut + audit | événement |
| Modifier un événement actif (nom, consignes, fin) | champs autorisés + audit avant/après | événement |
| Suspendre / réactiver (événement ou participation) | statut, motif, audit | événement / participation |
| Clôturer (normal, depuis `ACTIVE`/`SUSPENDED`) | vérifications ; `INVITED` et organisatrice → `CLOSED` ; événement → `CLOSED` ; audit | événement puis participations |
| Clôturer un événement `DRAFT` | participation organisatrice et invitations → `CLOSED` ; événement → `CLOSED` ; audit | événement puis participations |
| Clôture forcée | **reportée** (lot Administration CWS) | — |
| Zone : créer, confirmer, suspendre, supprimer | zone + audit | participation |
| Validité / opération groupée | créneaux existants → `deleted_at`, nouveaux créés, mode mis à jour, audit **par zone** avec `batch_id` | participation |
Ordre des verrous : compteur → événement → participations → zones. Un refus métier ne ferme pas l'EntityManager (DT-007). Chaque cas est une transaction unique : audit atomique avec l'écriture.

### 8.4 Référence publique (DT-025, DM-072)
Dans la transaction de création : `SELECT last_number … FOR UPDATE` sur la ligne unique, incrément, `EVT-{année UTC de création}-{numéro formaté sur au moins 6 chiffres}` ; au-delà de 999 999 le numéro s'allonge (`EVT-2031-1000000`). `UNIQUE(public_reference)` en base. Un échec annule l'incrément (même transaction). Le verrou sérialise toutes les créations d'événements (acceptable au volume attendu). La référence n'est jamais un contrôle d'accès ; son caractère séquentiel révèle le volume global d'événements (risque faible accepté par le principe validé). Fuseau de l'« année de création » : UTC, recommandation **[T6]**.

## 9. Audit (DT-022, validée)

`audit_log` : `id, occurred_at, action, subject_type, subject_id, municipality_id, actor_id, operator_id, batch_id, origin, previous_value, new_value`.
- `actor_id` : utilisateur métier dont les droits sont exercés ; `operator_id` : personne réellement connectée (CWS), `NULL` si identique à l'acteur ; `batch_id` : regroupe les écritures d'une opération groupée.
- Index `(operator_id)` et `(batch_id)`. `AuditOrigin` : `USER`, `CONSOLE`, `SYSTEM` + `SUPPORT` (code).
- Migration : deux colonnes nullables ajoutées par `ALTER TABLE` (sans réécriture), dans la **première migration du LOT 02** (dédiée, relue).
- Nouveaux `AuditSubjectType` : `EVENT`, `EVENT_PARTICIPATION`, `EVENT_ZONE`, `EVENT_ZONE_VALIDITY`, `EVENT_TYPE` ; nouvelles `AuditAction` (création, modification, activation, suspension, réactivation, clôture, invitation, acceptation, confirmation, suppression logique de zone, suppression logique de créneau, configuration de validité, opération groupée) : enums PHP en `VARCHAR`, sans migration. Valeurs sans donnée personnelle.
- Cas CWS futurs : clôture forcée → `actor_id = NULL`, `operator_id` = opérateur CWS, justification et participations clôturées dans `new_value` ; impersonation → `actor_id` = compte impersoné, `operator_id` = opérateur CWS.
- Préparé dans TECH-02.1 (DT-029) : énumération `CwsPermission`, port `CwsPermissionChecker` refusant par défaut. Avant la production du lot d'impersonation : MFA et contrôles de sécurité (absents aujourd'hui).

## 10. Droits d'utilisation et statut communal (DM-070, DM-073, DM-076, DT-027, DT-030)
**Règle** : une commune administrativement `ACTIVE` peut créer un événement si elle possède **au moins un droit d'utilisation valide** : (1) abonnement actif ; (2) TRIAL valide ; (3) **accès commercial accordé par CWS** (par commune, sans abonnement payant, expiration facultative, révocable, traçable). Un abonnement payant n'est pas obligatoire.
**Contrat** : `EventCreationPolicy` (service centralisé, serveur) interroge le port `MunicipalityEntitlementProvider`, qui renvoie des droits avec leur `EntitlementSource` (`SUBSCRIPTION`, `TRIAL`, `CWS_GRANT`) et leur validité (fin facultative). L'expiration ou la révocation n'interdit que les nouvelles créations ; la gestion des événements existants continue. La suspension administrative (D16/D17) reste indépendante ; le statut `SUSPENDED` ne porte pas de motif : tout `SUSPENDED` suit D16/D17 **[T5]**.
**Persistance minimale (DT-030, validée)** : table `municipality_commercial_access` (`id` UUID v7, `municipality_id` FK `RESTRICT`, `granted_at`, `expires_at` nullable, `revoked_at` nullable, `granted_by` FK `RESTRICT` (utilisateur CWS), `revoked_by` FK `RESTRICT` nullable *(ajout de traçabilité, à confirmer)*, `reason`, `created_at`), index `(municipality_id, granted_at)`, lue par `CommercialAccessEntitlementSource` ; attribution et révocation **administrées depuis Sonata (DM-078)**, lot Administration CWS, jamais par l'administration communale. Pas d'entité d'abonnement ni de facturation. Le port `MunicipalityEntitlementProvider` est alimenté par une chaîne de sources étiquetées (`app.municipality_entitlement_source`) : le module Abonnements ajoutera sa source sans modifier `EventCreationPolicy`. En test : double du port. **Ce n'est pas un contournement global** : chaque accès vise une commune, expire ou se révoque, et est audité.

## 11. Référentiel communal et outre-mer
`InseeCode` (`^(\d{2}|2A|2B)\d{3}$`) accepte les codes 97xxx et 98xxx ; téléphones E.164 avec régions ultramarines ; codes postaux sur 5 caractères. Aucune liste officielle des communes n'est chargée dans le dépôt : la couverture territoriale repose sur le **référentiel territorial versionné** (§6.5), dont le contenu polynésien reste à constituer depuis une source officielle. Tests : communes 97101, 97411, 97601, 98714, 98818 et résolution de fuseau. L'import du COG complet reste un choix produit non décidé.

## 12. Tests exigés
Domaine pur : union, minuit, DST des fuseaux listés, récurrences, choix d'occurrence et leur invalidation, créneaux supprimés exclus ; politiques : matrice §8.1 avec identifiants forgés et rôles identiques dans une autre commune ; transitions complètes ; concurrence (invitation simultanée, **génération de référence par créations concurrentes**, activation/clôture simultanées, reconfiguration simultanée de la même zone) ; création refusée sans droit d'utilisation, autorisée avec abonnement actif, TRIAL valide ou accès commercial CWS (avec ou sans expiration), refusée après expiration ou révocation ; droit expiré/révoqué sans effet sur un événement ouvert ; clôture d'un événement `DRAFT` (participations clôturées, audit, pas de réactivation) ; initialisation idempotente des neuf `EventType` ; verrouillage après activation (type, fuseau, début) et modification de la fin ; suivi de durée des zones `EVENT_DURATION` ; reconfiguration avec historique conservé ; audit atomique (`actor_id`, `operator_id`, `batch_id`) ; nombre de requêtes. Ajouter les nouvelles tables à `TestDatabase`. `make quality` doit passer.

## 13. Traçabilité des décisions

| Décision | Application |
|---|---|
| A1, A2 | §4.5, §5, invariants 6, 9 |
| A3 | §4.1 |
| A4 | invariant 1, §8.3 |
| A5.1 → R9 → P2 | §4.2, §6.5, invariant 20 |
| A5.2, A5.3, R3, R4, DT-020 | §6.2 à §6.4, invariant 8 |
| A6.1–A6.3 | invariants 11 à 14, §8.2, §8.3 |
| R5, P4 | §8.1, §9, DT-029 (développement reporté) |
| A7, R1, R2 | §8.1 |
| A8, R6, R8 | invariants 4, 9, §4.5 |
| A9, R7 | invariant 10 |
| A10 | §4.6, invariant 8 |
| A11, A13, P1, T1 | invariant 15, §10 |
| DRAFT → CLOSED | invariants 11, 12, §8.2, §8.3 |
| A12, P3 | §4.2, invariants 5, 17 |
| A14, P5 | §4.2, §4.3, §8.4, invariant 21 |
| P6 | §4.6, §5, invariants 6, 7, 19 |
| DT-022 | §9 |
| Simplicité (DM-060) | même modèle pour parcours simple et avancé (`EVENT_DURATION`) |

## 14. Points techniques résiduels

**Aucun point réellement bloquant pour l'étape 1.** Avant la rédaction de la migration (étape 3) : valider la table d'accès commercial (DT-030). Points de détail à confirmer (défaut proposé appliqué sauf avis contraire) :

| Id | Point | Défaut proposé |
|---|---|---|
| T2 | `endsAt` fixé dans le passé sur un événement actif | refuser (`endsAt` doit être postérieure à l'instant courant ; sinon clôturer) |
| T3 | Persistance des habilitations CWS | décidée dans le lot Administration CWS |
| T4 | Modification d'un créneau unique | remplacement (suppression logique + création) |
| T5 | `SUSPENDED` sans motif | tout `SUSPENDED` suit D16/D17 |
| T6 | Année de la référence : fuseau | UTC |

Non bloquant mais requis avant production : constitution du référentiel territorial polynésien depuis une source officielle (aujourd'hui **non fourni** : le résolveur ne fait aucune hypothèse sur les communes de Polynésie française) ; MFA (lot Administration CWS). Les écarts UI-02 sont dans [`revisions/ECARTS-UI-02.md`](revisions/ECARTS-UI-02.md) (non réévalués après ces arbitrages).

## 15. Étape 2 — état d'implémentation et écarts avec ce schéma

Implémenté (sans migration) : les cinq entités, `EventReferenceCounter`, `MunicipalityCommercialAccess`, les repositories, les contrats applicatifs, `CwsPermission`, les extensions d'audit côté contrats. Le DDL attendu (nouvelles tables uniquement ; aucune table existante modifiée) a été vérifié avec `doctrine:schema:update --dump-sql` en lecture seule.

| Écart | Raison | Suite |
|---|---|---|
| ~~`audit_log.operator_id` / `batch_id` non mappés~~ | Mapper des colonnes absentes de la base aurait cassé les écritures d'audit (DT-032) | **Levé à l'étape 3** (§16) |
| Compteur de références : incrément par `UPDATE` SQL, l'entité ne sert qu'au mapping | `PESSIMISTIC_WRITE` + `refresh` écrasait l'incrément non flushé (constaté par test) (DT-025) | — |
| Transitions portées par les enums, **Symfony Workflow non configuré** | Source unique, pas de duplication (DT-031) | Étape 5 : branchement éventuel |
| `municipality_commercial_access.revoked_by` ajouté | Traçabilité de la révocation | **Validé** (DM-079) |
| Type de zone immuable après création | Choix d'implémentation | **Validé** (DM-080) |
| `reactivate` (événement, participation) conserve motif et date de suspension : « dernière suspension connue », seul le statut fait autorité | Correction de l'étape 2 | **Validé** (DM-081) ; `Event.suspendedAt` ajouté |
| `Event::reschedule` après activation refuse une fin déjà passée | Défaut T2 | **Validé** (DM-082) |
| Garde technique : récurrence limitée à 3 660 jours | Protection contre une expansion démesurée (étape 1) | **Validé** (DM-083) |
| Aucune table purgée par `TestDatabase` avant la migration | Les tables n'existent pas encore ; purge conditionnelle prête | — |
| ~~22 tests d'intégration sautés~~ | Ils exigeaient les nouvelles tables | **Levé à l'étape 3** : exécutés sur MySQL, aucun test sauté |

## 16. Étape 3 — migration et validation MySQL

- **Migration** : `src/Migrations/Version20261103070731.php`, jouée **uniquement** sur `civipass_test` (MySQL 8.4.13) ; les bases de développement et de production ne sont pas migrées.
- **Contenu** : 7 tables (`event_type`, `event_event`, `event_participation`, `event_zone`, `event_zone_validity`, `event_reference_counter`, `municipality_commercial_access`), 12 clés étrangères `RESTRICT`, index et unicités du schéma ; seule modification d'une table existante : `audit_log` + `operator_id`, `batch_id` (nullables, `idx_audit_operator`, `idx_audit_batch`) ; ligne unique du compteur (`id = 1`, `last_number = 0`) ; neuf `EventType` (insertion idempotente par code).
- **Initialisation rejouable** : `app:event-types:initialize` (DT-023). Une nouvelle base requiert la migration ; la commande n'est utile que pour rejouer.
- **Audit** : `AuditLog` mappe `operator_id` et `batch_id` ; `DoctrineAuditTrail` les persiste ; le garde-fou de l'étape 2 est supprimé ; `actor_id` reste l'identité métier.
- **Compteur** : validé sur MySQL (séquence, verrou concurrent, quatre processus parallèles, rollback sans trou, année UTC, dépassement de 999 999).
- **Ajustement final** : migration complémentaire `Version20261103072308` (seule instruction : `ALTER TABLE event_event ADD suspended_at DATETIME DEFAULT NULL`), jouée sur `civipass_test` uniquement ; `Event.suspendedAt` aligne l'événement sur la participation (DM-081).
- **Reste ouvert** : référentiel territorial polynésien à fournir.

## 17. Étape 4 — politiques d'accès et isolation intercommunale (DT-034)

Code : `src/Event/Application` (`EventPermission`, `EventAccessPolicy`, `EventResourceResolver`, `AccessDecision`, `AccessDenialReason`). Aucune migration ; aucun contrôleur ni service transactionnel.

### 17.1 Matrice effective (DM-057), source unique : `EventPermission::roles()`

| Permission | Ressource | Administrateur | Gestionnaire | Instructeur | Portée (commune propriétaire) |
|---|---|---|---|---|---|
| Créer un événement | commune cible | Oui | Oui | Non | sa commune ; commune ACTIVE **et droit d'utilisation valide** |
| Consulter l'événement | événement | Oui | Oui | Oui | commune organisatrice ou participante (même invitée) |
| Modifier / activer / suspendre / réactiver / clôturer / inviter | événement | Oui | Oui | Non | **commune organisatrice** |
| Consulter une participation (détail) | participation | Oui | Oui | Oui | sa commune, ou la commune organisatrice |
| Consulter la liste des participations | événement | Oui | Oui | Oui | niveau selon la commune du contexte (§17.4) |
| **Accepter** une invitation | participation | Oui | **Non** | Non | commune destinataire |
| Suspendre / réactiver / clôturer **sa** participation | participation | Oui | Oui | Non | commune de la participation |
| Créer une zone | participation | Oui | **Non** | Non | commune de la participation, participation ACTIVE ou SUSPENDED |
| Consulter une zone | zone | Oui | Oui | Oui | sa commune ; lecture seule pour les communes participantes (non seulement invitées) |
| Modifier / supprimer logiquement une zone, configurer sa validité | zone | Oui | **Non** | Non | zone → participation → commune |
| Supprimer logiquement un créneau | créneau | Oui | **Non** | Non | créneau → zone → participation → commune |
| `CWS_IMPERSONATE`, `CWS_FORCE_CLOSE_EVENT` | — | — | — | — | refusées par défaut (`DenyAllCwsPermissionChecker`) |

`superAdmin` : aucun droit communal implicite (refus `NoAccess` sans membership).

### 17.2 États métier
Événement clôturé : tout est figé (consultation seule). Activation : brouillon ; suspension : actif ; réactivation : suspendu ; clôture : tout état non clôturé, brouillon compris (DM-077). Participation : acceptation (`INVITED`, événement ouvert), suspension (`ACTIVE`), réactivation (`SUSPENDED`, événement ouvert), clôture propre (`ACTIVE`/`SUSPENDED`, jamais l'organisatrice ni une invitation). Zones : édition si participation `ACTIVE` ou `SUSPENDED` (DM-066), jamais `INVITED` ni `CLOSED` ; zone ou créneau supprimé : refus. **Aucune restriction ajoutée pour un événement suspendu** (DM-056, DM-066). Commune `SUSPENDED` : lecture seule ; `INACTIVE` : aucun accès (D16). L'expiration ou la révocation d'un droit d'utilisation n'affecte jamais la gestion des événements existants (DM-073).

### 17.3 Ce que la politique ne fait pas (étape 5)
Prérequis transversaux des services : zones prêtes pour l'activation (`existsActivationReadyZone`), participations à clôturer avant l'événement, unicité des invitations, statut administratif de la commune invitée (`EventCreationPolicy::canJoinNewEvent`), verrous et audit.

### 17.4 Visibilité intercommunale et listes (DM-084, U1 à U4 tranchés)

- **U1** : DM-057 conservé (zones et créneaux : administrateur communal seul).
- **U2** : zones des autres communes en lecture seule pour une commune dont la participation est acceptée (ACTIVE, SUSPENDED, CLOSED) ; refusées à une commune invitée ou étrangère ; écriture : commune propriétaire seule.
- **U3 — niveaux sur les participations d'un événement** (`EventAccessPolicy::participantListVisibility`, pour la commune du contexte municipal) :

| Commune du contexte | Niveau | Contenu |
|---|---|---|
| Organisatrice | `Full` | toutes les participations, informations de gestion (`EventParticipationDetails`) |
| Participante acceptée (ACTIVE, SUSPENDED, CLOSED) | `Coordination` | l'organisatrice et les communes ACTIVE ou SUSPENDED : identifiant, nom, statut (`EventParticipationSummary`) |
| Simplement invitée | `OwnInvitationOnly` | sa propre invitation (`InvitationView`) |
| Étrangère, sans membership effectif | `None` | refus « Ressource inaccessible. » |

La projection de coordination n'a aucun champ de motif, d'audit, d'invitation, d'agent ou de donnée commerciale. Invitations en attente et participations clôturées ne sont pas exposées aux autres participantes.
- **U4** : tout rôle d'une commune concernée consulte (instructeur compris) ; aucune opération de gestion pour l'instructeur ; aucun droit global.
- **Listes** : `EventListReader` et `EventParticipantReader` exigent un contexte municipal vérifié (`MunicipalityContext` : utilisateur et membership actifs, commune ACTIVE ou SUSPENDED en lecture) puis exécutent des requêtes **bornées à la source** (`EventReadModel`, SQL à colonnes nommées, aucune entité chargée) : événements organisés par la commune ou auxquels elle participe (même invitée) ; participations selon le niveau. Aucun filtrage après coup.

### 17.5 Divergences et points à arbitrer
Voir `docs/revisions/CONTRADICTIONS.md` §G (U5).
