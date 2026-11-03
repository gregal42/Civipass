# TECH-02.1 — Modèle Doctrine (révision 1, HISTORIQUE)

> **Remplacé par [`../../lot-02-schema.md`](../../lot-02-schema.md)** (révision tenant compte de R1–R9 et A10–A14 : fuseaux IANA, `publicReference`, `operationalInstructions`, `dstChoices`, audit `operator_id`). Ce fichier est conservé comme trace ; ne pas l'utiliser comme référence.

# (révision 1)

> **Statut : proposition d'architecture, non implémentée.** Aucune entité, aucune migration. À valider avant tout développement. Intègre les arbitrages A1 à A9 ([DM-051 à DM-059](../../decisions/README.md)).
> Les éléments marqués **[À CONFIRMER]** sont des choix techniques ou métier non tranchés (voir [`../../revisions/CONTRADICTIONS.md`](../../revisions/CONTRADICTIONS.md)).

Conventions du dépôt : UUID v7 `BINARY(16)` ; dates UTC (`datetime_immutable` surchargé) ; énums PHP en `VARCHAR` ; clés étrangères en `RESTRICT` ; aucune suppression physique de données métier ; module `src/Event/{Domain,Application,Infrastructure}` ; noms de tables préfixés par le module.

## 1. Vue d'ensemble

```
Municipality ─< EventParticipation >─ Event ─> organizerMunicipality (Municipality)
                      │                  └──> EventType
                      └─< EventZone ─< EventZoneValidity
```

Cinq entités. **Aucune entité supplémentaire pour les créneaux** : `EventZoneValidity` est déjà l'entité « créneau » ; une table par type (continu, ponctuel, récurrent) multiplierait les jointures pour trois formes qui partagent les mêmes colonnes. Alternative écartée : héritage Doctrine (STI/JTI), plus lourd qu'un discriminant et des fabriques de domaine.

## 2. `EventType` — table `event_type`

| Champ | Type Doctrine | Null | Contrainte |
|---|---|---|---|
| `id` | `uuid` | non | PK |
| `code` | `string(40)`, collation binaire | non | `UNIQUE` |
| `label` | `string(100)` | non | |
| `sortOrder` | `smallint` | non | défaut 0 |
| `active` | `boolean` | non | défaut vrai |

Un type utilisé ne se supprime pas (SFD SCR-053) : FK `RESTRICT`. Pas d'écran d'administration au LOT 02 (DM-053). **Liste initiale proposée, à valider avant initialisation (DT-023)** : `incendie`, `inondation`, `accident`, `evenement_sportif`, `manifestation`, `autre`.

## 3. `Event` — table `event_event`

(Nom conservé de l'audit : `event` est un mot-clé MySQL ; le préfixe module évite tout risque de quoting.)

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `organizerMunicipality` | ManyToOne `Municipality` | non | immuable après création [recommandation] |
| `type` | ManyToOne `EventType` | non | |
| `name` | `string(200)` | non | |
| `description` | `text` | oui | |
| `timezone` | `string(40)` | non | `Europe/Paris` seule valeur en V1 (DM-054) |
| `startsAt` | `datetime_immutable` UTC | non | saisi en heure de Paris |
| `endsAt` | `datetime_immutable` UTC | oui | `> startsAt` si renseigné |
| `status` | enum `EventStatus` | non | `DRAFT`, `ACTIVE`, `SUSPENDED`, `CLOSED` |
| `suspensionReason` | `text` | oui | obligatoire à la suspension |
| `activatedAt`, `closedAt` | datetime UTC | oui | |
| `createdBy` | ManyToOne `User` | non | |
| `createdAt`, `updatedAt` | datetime UTC | non | |

Index : `(organizer_municipality_id, status)`, `(status, starts_at)`.

## 4. `EventParticipation` — table `event_participation`

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `event` | ManyToOne `Event` | non | |
| `municipality` | ManyToOne `Municipality` | non | |
| `status` | enum `ParticipationStatus` | non | `INVITED`, `ACTIVE`, `SUSPENDED`, `CLOSED` |
| `invitedBy` | ManyToOne `User` | oui | `NULL` pour l'organisatrice |
| `invitationMessage` | `text` | oui | |
| `invitedAt`, `lastInvitationSentAt` | datetime UTC | oui | |
| `activatedAt`, `suspendedAt`, `closedAt` | datetime UTC | oui | |
| `suspensionReason` | `text` | oui | obligatoire à la suspension |
| `createdAt`, `updatedAt` | datetime UTC | non | |

`UNIQUE(event_id, municipality_id)` (DM-004). Index `(municipality_id, status)`. L'organisatrice a une participation `ACTIVE` créée avec l'événement (DM-053).

## 5. `EventZone` — table `event_zone`

| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `participation` | ManyToOne `EventParticipation` | non | **seul rattachement** ; événement et commune s'en déduisent |
| `label` | `string(150)` | non | doublon de libellé : avertissement, pas de blocage (SCR-017) |
| `type` | enum `ZoneType` | non | `WHOLE_MUNICIPALITY`, `DISTRICT`, `STREET`, `LOCALITY`, `CUSTOM` |
| `description` | `text` | oui | |
| `status` | enum `ZoneStatus` | non | `ACTIVE`, `SUSPENDED` (valeurs du SFD) |
| `confirmedAt` | datetime UTC | oui | `NULL` = non confirmée (DM-051) |
| `validityMode` | enum `ValidityMode` | non | `UNCONFIGURED`, `EVENT_DURATION`, `SPECIFIC_PERIODS` ; mode de **configuration**, pas un statut métier |
| `deletedAt` | datetime UTC | oui | suppression logique (DM-052) |
| `createdBy` | ManyToOne `User` | non | |
| `createdAt`, `updatedAt` | datetime UTC | non | |

Index `(participation_id, status, deleted_at)`.
Défauts à la création : zone manuelle → `confirmedAt = now`, `validityMode = UNCONFIGURED` ; zone « Commune entière » automatique → `confirmedAt = NULL`, `UNCONFIGURED`.

## 6. `EventZoneValidity` — table `event_zone_validity`

Un créneau. **Horaires locaux** (DATE + TIME), jamais en UTC (DT-004 : le type `datetime_immutable` surchargé n'est pas utilisé ici).

| Champ | Type | Continu | Ponctuel | Récurrent |
|---|---|---|---|---|
| `id` | `uuid` | | | |
| `zone` | ManyToOne `EventZone` | oui | oui | oui |
| `kind` | enum `ValidityKind` | `CONTINUOUS` | `PUNCTUAL` | `RECURRING` |
| `startDate` | `date_immutable` | oui | oui (la date unique) | oui (début de période) |
| `startTime` | `time_immutable` | oui | oui | oui |
| `endDate` | `date_immutable` | oui | `NULL` | oui (fin de période, **obligatoire**) |
| `endTime` | `time_immutable` | oui | oui | oui |
| `weekdays` | `smallint` (bits Lun..Dim) | `NULL` | `NULL` | 1 à 127 |
| `dstChoices` | `json` | oui | oui | oui | **Proposition DT-020, à valider** : occurrence retenue (`first`/`second`) par date de transition ambiguë ; `NULL` si aucune ambiguïté |
| `createdAt`, `updatedAt` | datetime UTC | | | |

Index `(zone_id)`. Fabriques de domaine `continuous()`, `punctual()`, `recurring()` ; aucun constructeur public permettant un créneau incohérent.
**Champs du créneau continu : [À CONFIRMER] (point A10)** : le modèle suppose date + heure aux deux extrémités.

## 7. Suppression et FK

Toutes les FK en `RESTRICT`. Aucune suppression physique d'événement, participation, zone, type utilisé. Les créneaux retirés lors d'une reconfiguration sont supprimés physiquement ; leur état précédent est conservé dans `audit_log`. **[À CONFIRMER]** : cette suppression des créneaux est cohérente avec DM-052 (qui vise les zones) et la règle 8 du `CLAUDE.md` (décision, autorisation, historique), mais elle n'a pas été validée explicitement.

## 8. Invariants métier

1. L'événement a une participation organisatrice `ACTIVE`, créée dans la même transaction (DM-053) ; non supprimable ; l'organisatrice est immuable.
2. Une participation par couple événement/commune (SQL + domaine).
3. Une zone n'a qu'une participation ; les opérations groupées refusent toute zone d'une autre participation (comptage demandé = trouvé, DM-028).
4. Seul un administrateur actif de la commune de la participation écrit sur ses zones, commune `ACTIVE` (DM-007, DM-009, DM-057). Aucun droit communal implicite pour CWS (DM-005).
5. Les participantes ne modifient pas les informations générales de l'événement (DM-001).
6. Une zone sans `confirmedAt`, supprimée, suspendue ou `UNCONFIGURED` n'ouvre aucun droit (DM-019, DM-051, DM-052).
7. `EVENT_DURATION` : zéro créneau. `SPECIFIC_PERIODS` : au moins un créneau pour être opérationnelle.
8. Créneau : `startTime ≠ endTime` ; ponctuel : une date, début strictement avant fin, même jour ; continu : instant de fin résolu strictement après l'instant de début ; récurrence : `startDate ≤ endDate`, jours 1–127, dates obligatoires.
9. Un horaire local inexistant est refusé ; un horaire ambigu exige un choix enregistré (DM-055).
10. Une participation `INVITED` : zones non modifiables (DM-058).
11. Droits opérationnels d'une zone : événement `ACTIVE` ET participation `ACTIVE` ET zone confirmée, active, non supprimée, configurée, dans un intervalle de validité (union des créneaux, DM-018).
12. Activation `DRAFT → ACTIVE` : au moins une zone confirmée, active, non supprimée, configurée, appartenant à une participation `ACTIVE` ; une période future suffit (DM-059).
13. Clôture : autres participations `ACTIVE`/`SUSPENDED` déjà `CLOSED` ; les `INVITED` et l'organisatrice passent `CLOSED` avec l'événement (DM-056). Participation `CLOSED` et événement `CLOSED` définitifs.
14. Opérations groupées : configurations indépendantes, sans héritage ; confirmation avant écrasement (DM-025 à DM-027).
15. Suspension (événement ou participation) : motif obligatoire.
16. Chaque écriture sensible écrit son audit dans la même transaction ; l'acteur peut être accompagné d'un opérateur réel (DT-022, Proposée).

## 9. Transactions (`TransactionRunner`)

| Cas d'usage | Portée de la transaction | Verrous (DT-024, Proposée) |
|---|---|---|
| Créer un événement | `Event` + participation organisatrice + zone « Commune entière » proposée + audit | — |
| Inviter / renvoyer | participation INVITED + zone proposée + audit | événement |
| Accepter | participation → ACTIVE + audit | participation |
| Activer l'événement | garde + statut + audit | événement |
| Suspendre / réactiver | statut + motif + audit | événement ou participation |
| Clôturer l'événement | autres participations vérifiées ; INVITED et organisatrice → CLOSED ; événement → CLOSED ; audit | événement, puis participations |
| Créer / confirmer / suspendre / supprimer une zone | zone + audit | participation |
| Configurer la validité / opération groupée | remplacement des créneaux, mode, audit **par zone** avec identifiant de lot | participation |

Une règle métier refusée ne ferme pas l'EntityManager (DT-007).

## 10. Audit

Nouveaux sujets : `EVENT`, `EVENT_PARTICIPATION`, `EVENT_ZONE`, `EVENT_ZONE_VALIDITY`, `EVENT_TYPE`. Actions : création, modification, activation, suspension, réactivation, clôture, invitation, acceptation, confirmation, suppression logique, configuration de validité, opération groupée. `audit_log` reçoit sans migration de colonnes les nouvelles actions (colonnes `VARCHAR`). **Opérateur réel** (impersonation, DM-061) : colonne `operator_id` nullable à ajouter sur `audit_log` **si l'on veut éviter une migration ultérieure** (DT-022, **[À CONFIRMER]**).

## 11. Performance

Projections DQL avec `COUNT` groupé pour les listes ; créneaux chargés par `IN (:zoneIds)` ; pas de chargement paresseux en boucle ; calcul de validité à la demande par `ZoneValidityCalculator` sans table d'occurrences ; tests de non-régression du nombre de requêtes (listes de zones, opérations groupées). Ajouter les nouvelles tables à `TestDatabase`.

## 12. Calcul de validité (service de domaine pur)

`ZoneValidityCalculator` : développe les créneaux en intervalles UTC semi-ouverts `[début, fin)`, les fusionne (union), les borne à `[startsAt, endsAt]`. Chaque occurrence d'une récurrence est rattachée à son jour de début ; fin antérieure au début horaire = lendemain. Résolution des transitions par un service dédié (DT-021) qui refuse l'inexistant et exige un choix pour l'ambigu ; PHP ne décide jamais implicitement.
