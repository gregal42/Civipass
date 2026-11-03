# TECH-02.1 — Étape 5 : services transactionnels, verrouillage et audit métier — plan (sous-étape 5.1)

> **Statut : plan validé le 5.2 (architecture générale, D1 à D8, U5, U6) ; sous-étape 5.2 réalisée (voir §16).** Texte d'origine conservé ci-dessous, complété par le §16. Dernier commit de référence : `a2213fb`. Rien n'est implémenté, aucun composant existant n'est modifié, aucune migration ni écriture sur la base de développement. Les points marqués **[ARBITRAGE]** attendent une décision (§15). Décisions applicables : DM-007, DM-056, DM-057, DM-058, DM-062, DM-070 à DM-084, DT-024, DT-025, DT-034 ([registre](../../decisions/README.md)).

## 1. Analyse des composants existants

Signatures relevées dans le code (commit `a2213fb`).

| Composant | Contrat réel | Rôle pour l'étape 5 | Écart |
|---|---|---|---|
| `TransactionRunner::run(callable): mixed` (`DoctrineTransactionRunner`) | `beginTransaction` → opération → **`flush` automatique** → `commit`. Imbrication refusée (`LogicException`). `DomainRuleViolation` : rollback ; l'`EntityManager` est **fermé** si un flush a eu lieu ou si des modifications sont en attente, sinon conservé. Toute autre exception : rollback + `EntityManager` fermé. | Enveloppe de chaque service | Aucun changement. Règles d'usage §9. |
| `AuditTrail::record(AuditEvent)` | `persist` d'un `AuditLog`, **sans flush** : l'écriture part dans le flush du runner (même transaction) | Audit atomique avec la modification | Aucun |
| `AuditEvent` | `occurredAt, action, subjectType, subjectId, municipalityId, actor, previousValue, newValue, batchId` | Trace d'une écriture | Aucun |
| `AuditActor` | `user(id)`, `console()`, `system()`, `support(operatorId, ?actingAs)` (`operatorId` : opérateur CWS réel) | Distinction `actor_id` / `operator_id` | Aucun |
| `Psr\Clock\ClockInterface` | Déjà injecté dans `MembershipManager` (`symfony/clock` installé) | Heure unique de l'opération (`$now`) | Aucun |
| `EventAccessPolicy` | `forNewEvent(user, municipality, at)`, `forEvent/forParticipation/forZone/forSlot(user, permission, ressource)` → `AccessDecision` ; `participantListVisibility(user, viewer, event)` | Décision de permission et d'état | Aucun ; **doit être rappelée après verrouillage** (§6) |
| `EventResourceResolver` | `event/participation/zone/slot(user, idNonFiable, permission)` → entité ou `OperationNotAllowed('Ressource inaccessible.')` | Pré-autorisation **avant** la transaction | Aucun |
| `EventPermission` | 19 permissions, matrice DM-057 | Source unique des rôles | Aucun |
| `EventCreationPolicy::canCreateEvent(municipality, at)` | Statut `ACTIVE` + au moins un droit valide (`MunicipalityEntitlementProvider`) ; `canJoinNewEvent(municipality)` : statut `ACTIVE` | Création ; **invitation : `canJoinNewEvent` de la commune invitée** | Aucun |
| `MunicipalityContext::requireMembership(user, idNonFiable, write)` | Membership effectif ou `OperationNotAllowed('Commune inaccessible.')` | Contexte municipal de la **création** (seule opération sans ressource préexistante) | Aucun |
| `EventRepository` | `add, find, findByPublicReference, findAccessibleTo, listForMunicipality, lock(id)` | Chargement et verrou d'événement | `lock` = `find(PESSIMISTIC_WRITE)` + `refresh()` **sans mode de verrou** : voir F1. Pas de verrou partagé. |
| `EventParticipationRepository` | `add, find, findForMunicipality, findByEventAndMunicipality, findByEvent, lock(id)` | Participations | Même défaut ; pas de « verrouiller toutes les participations d'un événement » (F3) |
| `EventZoneRepository` | `add, find, findForMunicipality, findActiveByParticipation, findByIdsInParticipation, existsActivationReadyZone` | Zones, garde d'activation, sélection groupée | Aucun verrou de zone (choix : verrou de participation, §7) |
| `EventZoneValidityRepository` | `add, find, findActiveByZone(s), findHistoryByZone` | Créneaux | Aucun |
| `EventTypeRepository` | `find, findByCode, findActive` | Type à la création | Aucun |
| `MunicipalityRepository` | `find, findByInseeCode, lock` | Commune invitée | Aucun |
| `PublicReferenceGenerator::next(now)` (`DoctrinePublicReferenceGenerator`) | `UPDATE … last_number + 1` puis lecture, **transaction exigée** ; SQL direct (pas de flush ORM) | Référence globale | Position du verrou : §8 |
| Entités du domaine | `Event` (`activate/suspend/reactivate/close/reschedule/rename/describe/changeOperationalInstructions/changeType/changeTimezone`), `EventParticipation` (`forOrganizer/invite/accept/resendInvitation/suspend/reactivate/close/closeWithEvent`), `EventZone` (`create/proposeWholeMunicipality/update/confirm/suspend/reactivate/changeValidityMode/markDeleted`), `EventZoneValidity` (`fromSlot/toSlot/markDeleted`) | **Les invariants sont déjà dans le domaine** : les services orchestrent, ne réimplémentent rien | Aucun |
| Moteur temporel (étape 1) | `SlotInspector`, `ZoneValidityCalculator`, `TerritoryTimezoneResolver`, `EventTimezone` | Validation des créneaux dans le fuseau de l'événement ; fuseau proposé | Aucun |

### Constats de l'analyse

- **F1 — défaut latent des méthodes `lock()` (démontré sur MySQL).** `lock()` fait `find(PESSIMISTIC_WRITE)` puis `refresh()` **sans verrou**, c'est-à-dire une lecture simple. En `REPEATABLE READ` (isolation par défaut du serveur, vérifiée : `REPEATABLE-READ`), une lecture simple lit un instantané établi à la **première lecture non verrouillante de la transaction**. Expérience sur `civipass_test` : transaction A lit une table (instantané), une autre transaction valide `last_number = 41`, puis A lit la ligne : simple = **0** (périmé), `FOR UPDATE` = **41**, simple **après** le verrou = **0** (périmé). Donc `refresh()` après un verrou peut **écraser un état frais par un état périmé** si une lecture simple a précédé le verrou dans la même transaction. Le pattern de `MembershipManager` (verrou en premier) y échappe ; les politiques et gardes de l'étape 5 lisent avant de verrouiller. **Correction proposée en 5.2** : `refresh($entity, LockMode::PESSIMISTIC_WRITE)` (supporté : `EntityManagerInterface::refresh(object, LockMode|int|null)`), plus une règle d'ordre (§6). Concerne aussi `MunicipalityRepository::lock` (même motif, non utilisé par le module Event).
- **F2 — pas de verrou partagé** : nécessaire pour que les opérations sur les zones n'excluent pas l'activation sans se sérialiser entre communes (§7).
- **F3 — pas de verrou des participations d'un événement** (clôture) ni de tri stable.
- **F4 — pas de service du module Event** : tout est à créer, mais sans nouvelle abstraction métier (les invariants sont dans les entités).
- **F5 — fermeture de l'`EntityManager`** : un refus métier **après** un flush ou des modifications en attente le ferme ; les services doivent donc *valider avant de modifier* et ne jamais flusher eux-mêmes.
- **F6 — DT-024** place le compteur en tête de l'ordre des verrous ; l'analyse recommande de le prendre en dernier (§8).
- **F7 — le résolveur lit hors transaction** : parfait pour la pré-autorisation (aucun instantané transactionnel conservé), à condition de **ne pas** s'en contenter (§6).

## 2. Architecture applicative proposée

Cinq services de cas d'usage, deux collaborateurs internes, des commandes et des résultats immuables. Aucun framework, aucune couche supplémentaire.

```
src/Event/Application/
  Service/
    EventCreationService        create
    EventLifecycleService       update, activate, suspend, reactivate, close
    EventParticipationService   invite, resendInvitation, accept, suspend, reactivate, close
    EventZoneService            create, update, confirm, suspend, reactivate, delete, configureValidity
    EventZoneBulkService        apply                                   (5.5)
  Support/
    EventLocker                 verrous ordonnés (modes S/X, rafraîchissement verrouillant)
    EventAuditRecorder          instantanés d'audit sans donnée sensible + AuditTrail
  Command/…                     entrées immuables (DTO), exclues de l'injection de dépendances
  Result/…                      sorties immuables (identifiants, statuts), jamais d'entité
```

Les neuf noms indicatifs sont **regroupés** par cohérence de verrouillage : une opération sur un événement et ses participations partage les mêmes verrous, donc le même service. `ActivateEventService`, `SuspendEventService`, `ReactivateEventService`, `CloseEventService` deviennent des méthodes de `EventLifecycleService` ; `InviteMunicipalityService` et `AcceptEventInvitationService` des méthodes de `EventParticipationService` ; `ManageEventZoneService` et `ReplaceZoneValidityService` de `EventZoneService`.

**Gabarit unique de chaque opération** (aucun service n'en appelle un autre) :

```
1. pré-autorisation   : EventResourceResolver (hors transaction, refus uniforme)       — évite qu'un tiers pose des verrous
2. TransactionRunner::run(function () {
3.   verrous          : EventLocker, PREMIÈRE instruction de la transaction, ordre §8
4.   ré-autorisation  : EventAccessPolicy sur les entités verrouillées et rafraîchies   — états et rôles à jour
5.   préconditions    : règles sensibles à la concurrence (unicité, garde d'activation, prérequis de clôture)
6.   validation       : tout refus métier AVANT la première modification (F5)
7.   modification     : méthodes du domaine
8.   audit            : EventAuditRecorder (même transaction)
9.   return Result    : identifiants et statuts
10. })               : flush + commit par le runner
```

Dépendances par service : `TransactionRunner`, `ClockInterface`, `EventResourceResolver`, `EventAccessPolicy`, `EventLocker`, `EventAuditRecorder`, les dépôts nécessaires (et `PublicReferenceGenerator`, `EventCreationPolicy`, `MunicipalityContext`, `TerritoryTimezoneResolver`, `SlotInspector` pour les cas qui les utilisent). Un contrôleur futur ne fera que : authentifier, construire la commande, appeler le service, traduire résultat ou exception.

## 3. Contrats des services

Convention d'entrée : `User $actor` + identifiants **non fiables** (`string`) + commande. Sorties : résultats immuables. Erreurs :
- `OperationNotAllowed` — refus d'accès ou d'état (message uniforme « Ressource inaccessible. » / « Commune inaccessible. » quand aucune information ne doit fuiter) ;
- `DomainRuleViolation` — validation (nom vide, dates, motif manquant, créneau invalide…) ;
- `InvalidValiditySlot` — créneau invalide, avec la liste des problèmes (heure inexistante, choix manquant…) ;
- `OperationConflict` (nouvelle, Application) — conflit technique traduit : verrou expiré, interblocage, unicité violée en concurrence ; message « Un autre traitement est en cours, réessayez. » **[ARBITRAGE D2]**.

| Service::méthode | Entrées | Sortie | Erreurs métier notables |
|---|---|---|---|
| `EventCreationService::create` | `actor`, `municipalityId`, `CreateEventCommand(typeCode, name, startsAt, ?endsAt, ?timezone, ?description, ?operationalInstructions)` | `CreatedEvent(eventId, publicReference, participationId, proposedZoneId, timezone)` | commune inaccessible/non écrivable, rôle, aucun droit d'utilisation, type inconnu ou inactif, fuseau non résolu et non fourni, dates |
| `EventLifecycleService::update` | `actor`, `eventId`, `UpdateEventCommand` (champs modifiables DM-071) | `EventSnapshotResult` | clôturé ; champs verrouillés après activation ; fin dans le passé (DM-082) |
| `::activate` | `actor`, `eventId` | `EventStatusResult(status, activatedAt)` | état ≠ `DRAFT` ; **aucune zone activable** (DM-059) |
| `::suspend` | `actor`, `eventId`, `reason` | idem | état ≠ `ACTIVE` ; motif obligatoire |
| `::reactivate` | `actor`, `eventId` | idem | état ≠ `SUSPENDED` |
| `::close` | `actor`, `eventId` | `EventClosureResult(closedParticipations[])` | déjà clôturé ; ~~participations `ACTIVE`/`SUSPENDED` d'autres communes non clôturées (A6.3)~~ **remplacé par DM-092 : toutes les participations ouvertes sont clôturées automatiquement** |
| `EventParticipationService::invite` | `actor`, `eventId`, `targetMunicipalityRef`, `?message` | `InvitationResult(participationId, proposedZoneId)` | événement clôturé ; cible = organisatrice ; cible introuvable, non `ACTIVE` ; doublon **[ARBITRAGE D4]** |
| `::resendInvitation` | `actor`, `participationId` | `InvitationResult` | pas `INVITED` ; événement clôturé |
| `::accept` | `actor`, `participationId` | `ParticipationStatusResult` | pas `INVITED` ; événement clôturé ; rôle administrateur seul (R2) |
| `::suspend` / `::reactivate` / `::close` | `actor`, `participationId` (+ `reason` pour suspendre) | `ParticipationStatusResult` | transitions §5 ; organisatrice : clôture avec l'événement ; événement clôturé |
| `EventZoneService::create` | `actor`, `participationId`, `CreateZoneCommand(label, type, ?description, wholeMunicipalityConfirmed)` | `ZoneResult(zoneId, confirmedAt, validityMode)` | participation non éditable ; « Commune entière » sans confirmation explicite ; doublon de « Commune entière » non supprimée (invariant 9) |
| `::update/confirm/suspend/reactivate/delete` | `actor`, `zoneId` (+ champs) | `ZoneResult` | zone supprimée ; participation non éditable ; type immuable (DM-080) |
| `::configureValidity` | `actor`, `zoneId`, `ZoneValidityCommand(mode, slots[])` | `ZoneValidityResult(mode, createdSlotIds[], deletedSlotIds[])` | créneau invalide dans le fuseau (`InvalidValiditySlot`) ; `SPECIFIC_PERIODS` sans créneau ; `EVENT_DURATION` avec créneaux ; limite 3 660 jours |
| `EventZoneBulkService::apply` (5.5) | `actor`, `participationId`, `BulkZoneCommand(zoneIds[], operation, confirmOverwrite)` | `BulkResult(batchId, zoneIds[])` | une zone étrangère ou supprimée ⇒ refus **en bloc** (DM-028) ; écrasement sans confirmation (DM-025) |

## 4. Matrice des permissions par opération

Chaque ligne s'appuie sur la matrice existante (`EventPermission`) ; **aucune permission nouvelle**.

| Opération | Permission (ressource) | Conditions d'état complémentaires (déjà portées par la politique) |
|---|---|---|
| Créer un événement | `CreateEvent` (commune cible) | commune `ACTIVE` + droit d'utilisation valide |
| Modifier | `UpdateEvent` (événement) | non clôturé |
| Activer | `ActivateEvent` | `DRAFT` ; garde de zones (service) |
| Suspendre / réactiver | `SuspendEvent` / `ReactivateEvent` | `ACTIVE` / `SUSPENDED` |
| Clôturer | `CloseEvent` | non clôturé ; prérequis de clôture (service) |
| Inviter / renvoyer | `InviteMunicipality` (événement) | non clôturé ; renvoi : participation `INVITED` |
| Accepter | `AcceptInvitation` (participation) | `INVITED`, événement ouvert, **administrateur seul** |
| Suspendre / réactiver / clôturer sa participation | `SuspendParticipation` / `ReactivateParticipation` / `CloseParticipation` | `ACTIVE` / `SUSPENDED` (+ événement ouvert) / `ACTIVE` ou `SUSPENDED`, jamais l'organisatrice ni une invitation |
| Créer une zone | `CreateZone` (participation) | participation `ACTIVE` ou `SUSPENDED`, événement ouvert |
| Modifier, confirmer, suspendre, réactiver une zone | `ModifyZone` (zone) | zone non supprimée ; participation éditable |
| Supprimer une zone | `DeleteZone` | idem |
| Configurer la validité (remplacer les créneaux) | `ConfigureZoneValidity` (zone) | idem ; les créneaux appartiennent à la zone chargée : la chaîne créneau → zone → participation → commune est ainsi garantie |
| Opération groupée | `ModifyZone` / `ConfigureZoneValidity` sur **chaque** zone | toutes les zones de **une** participation |

Rappels : l'administrateur communal gère zones et créneaux seul ; le gestionnaire gère événement, invitations et sa participation ; l'instructeur ne gère rien ; `superAdmin` sans membership est refusé ; `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT` ne sont pas utilisées.

## 5. Frontières transactionnelles

Légende : **L** verrous (S = partagé, X = exclusif), **C** contrôles dans la transaction, **E** écritures, **A** audit (§10), **R** rollback.

**Création d'événement** — atomicité : *jamais d'événement sans participation organisatrice, de référence sans événement, de participation sans événement, ni d'écriture sans audit.*
- Lectures (hors transaction) : membership, commune, type, fuseau proposé.
- **L** : aucun verrou de ressource existante ; la ligne du compteur (par `UPDATE`) en **dernier**, juste avant les insertions.
- **C** : rôle, commune `ACTIVE`, droit d'utilisation (`EventCreationPolicy`), type actif, fuseau résolu ou fourni (liste blanche), dates.
- **E** : référence → `Event` (`DRAFT`) → `EventParticipation::forOrganizer` (`ACTIVE`) → zone « Commune entière — [nom] » proposée non confirmée (DM-058 : validé, donc obligatoire ; `createdBy` = l'acteur).
- **A** : `EVENT_CREATED`, `PARTICIPATION_INVITED`… voir §10. **R** : tout est annulé ensemble, y compris l'incrément du compteur.

**Invitation** — **L** : événement X. **C** : événement non clôturé ; commune cible existante, `ACTIVE` (`canJoinNewEvent`) ; pas la commune organisatrice ; **aucune participation existante** (relue sous verrou) ; contrainte `UNIQUE(event_id, municipality_id)` en filet. **E** : `EventParticipation::invite` + zone proposée. **A** : invitation + création de zone. **R** : doublon concurrent → `OperationConflict`/refus.

**Acceptation** — **L** : événement S → participation X. **C** : `INVITED`, événement ouvert, rôle. **E** : `accept`. **A** : `PARTICIPATION_ACCEPTED`.

**Activation** — **L** : événement X. **C** : `DRAFT` ; `existsActivationReadyZone` (lu **après** le verrou) ; politique. **E** : `activate`. **A** : `EVENT_ACTIVATED`.

**Suspension / réactivation d'événement** — **L** : événement X. **C** : état, motif. **E** : `suspend` (motif, date) / `reactivate` (conserve motif et date, DM-081). **A** : `EVENT_SUSPENDED` / `EVENT_REACTIVATED`.

**Modification d'événement** — **L** : événement X. **C** : non clôturé ; champs verrouillés (DM-071) ; fin non passée (DM-082). **E** : `rename/describe/changeOperationalInstructions/reschedule/changeType/changeTimezone`. **A** : `EVENT_UPDATED` (champs modifiés).

**Clôture d'événement** — **L** : événement X, puis **toutes** les participations X dans l'ordre croissant de l'identifiant. **C** : ~~depuis `ACTIVE`/`SUSPENDED` : participations des autres communes déjà `CLOSED` (A6.3)~~ → **DM-092** : aucun prérequis de clôture préalable, quel que soit l'état. **E** : `Event::close`, puis `closeWithEvent` sur les invitations, l'organisatrice et les éventuelles participations restantes. **A** : `EVENT_CLOSED` + une ligne `PARTICIPATION_CLOSED` par participation, **même `batch_id`**. **R** : tout ou rien.

**Participation (suspendre / réactiver / clôturer)** — **L** : événement S → participation X. **C** : transitions, événement non clôturé (réactivation), organisatrice. **E** : méthodes d'entité. **A** : `PARTICIPATION_SUSPENDED/REACTIVATED/CLOSED`.

**Zones** — **L** : événement S → participation X. **C** : zone non supprimée ; participation éditable ; unicité de « Commune entière » non supprimée (sous le verrou de participation). **E** : méthodes d'entité. **A** : `ZONE_CREATED/UPDATED/CONFIRMED/SUSPENDED/REACTIVATED/DELETED`.

**Configuration de la validité (remplacement des créneaux)** — **L** : événement S → participation X. **C** : `SlotInspector::assertValid` pour chaque créneau dans le fuseau **de l'événement** ; cohérence mode/créneaux ; `dstChoices` complets. **E** : créneaux actifs → `markDeleted` ; nouveaux créneaux → `fromSlot` ; `changeValidityMode`. **A** : `ZONE_VALIDITY_MODE_CHANGED`, `ZONE_VALIDITY_SLOT_DELETED`, `ZONE_VALIDITY_SLOT_CREATED`, même `batch_id`.

**Opération groupée** — mêmes verrous que ci-dessus ; zones chargées **dans l'ordre croissant de l'identifiant** ; un `batch_id` pour tout le lot ; **une ligne d'audit par zone**.

## 6. Stratégie transactionnelle

1. **Pré-autorisation hors transaction** (`EventResourceResolver`) : un utilisateur sans droit ne verrouille jamais rien.
2. **Verrous en première instruction** de la transaction, avec **rafraîchissement verrouillant** (correction F1).
3. **Ré-autorisation et validations sous verrou** : la politique est rappelée sur les entités verrouillées ; états, unicités, garde d'activation et prérequis de clôture sont lus **après** les verrous. Une vérification sensible à la concurrence n'est jamais faite seulement avant l'ouverture de la transaction.
4. **Valider avant de modifier** (F5) ; aucun `flush()` manuel (le générateur de référence utilise du SQL direct, donc sans flush ORM).
5. **Aucune transaction imbriquée** : un service n'appelle jamais un autre service ; les enchaînements (création + zone proposée, clôture + participations) sont des méthodes privées du même service, dans la même transaction.
6. **Heure** : un seul `$now = $clock->now()` par opération, passé aux entités et à l'audit.
7. **Isolation** : on conserve `REPEATABLE READ` ; les règles 2 et 3 en neutralisent le risque. Passer en `READ COMMITTED` serait plus simple mais change le comportement de tout le runner : **[ARBITRAGE D3]**.

## 7. Verrouillage

Ressources verrouillées et modes (aucun verrou sur les communes, ni sur l'ensemble des événements) :

| Opération | Événement | Participation(s) | Zones/créneaux | Compteur |
|---|---|---|---|---|
| Créer | — | — | — | `UPDATE` (dernier) |
| Inviter, renvoyer | **X** | — (insertion) | — | — |
| Modifier, activer, suspendre, réactiver | **X** | — | — | — |
| Clôturer | **X** | **X**, toutes, `id ASC` | — | — |
| Accepter, suspendre/réactiver/clôturer sa participation | **S** | **X** | — | — |
| Zones, créneaux, opérations groupées | **S** | **X** | lignes modifiées (implicite), `id ASC` | — |

Justification : l'activation et la clôture (X sur l'événement) **excluent** toute opération de zone en cours (S) et inversement, sans que deux communes qui modifient leurs zones se bloquent entre elles (S compatibles ; seules leurs participations — distinctes — sont en X). Un verrou X sur la **participation** sérialise les modifications d'une même commune (zones, créneaux, remplacements, suspension). Nécessite deux méthodes de dépôt à créer en 5.2 : verrou **partagé** de l'événement et verrou de **toutes les participations** d'un événement.

## 8. Ordre des verrous

**Événement → participations (identifiant croissant) → zones (identifiant croissant). Le compteur n'est pris qu'en création, en dernier.**
Toute transaction prend ses verrous dans cet ordre : pas de cycle possible entre événement, participation et zones. Le compteur n'est associé à aucun autre verrou (la création ne verrouille aucune ressource existante), donc le prendre tard **réduit** la durée de sérialisation des créations sans risque d'interblocage ; cela révise DT-024 (« compteur → événement → … ») **[ARBITRAGE D2]**. Les lectures de clés étrangères (commune, type, utilisateur) posent des verrous partagés de ligne sans conséquence ici.

## 9. Stratégie de rollback

| Situation | Comportement du runner | Règle du service |
|---|---|---|
| Refus métier **avant** toute modification | rollback ; `EntityManager` **conservé** | valider avant d'agir |
| Refus métier après modification ou flush | rollback ; `EntityManager` **fermé** | éviter ; le cas existe seulement pour des échecs imprévus |
| Échec de persistance, d'audit ou de contrainte | rollback ; `EntityManager` fermé | l'audit étant dans la même transaction, **rien** ne persiste |
| `UniqueConstraintViolationException` (invitation en doublon concurrente) | rollback ; `EntityManager` fermé | traduction en `OperationConflict` / refus « déjà invitée » **hors** du runner |
| `LockWaitTimeoutException` (délai du serveur : 50 s par défaut) | rollback ; fermé | traduction en `OperationConflict` ; délai à raccourcir **[ARBITRAGE D3]** |
| `DeadlockException` | rollback ; fermé | idem ; l'ordre de verrouillage doit les rendre exceptionnels |
| Compteur de références | l'`UPDATE` est annulé avec la transaction | aucun trou |

**Pas de nouvelle tentative automatique** : l'`EntityManager` est fermé après un échec technique, l'état lu peut avoir changé, et l'intention de l'utilisateur doit être revalidée ; on remonte `OperationConflict` et l'appelant décide.

## 10. Matrice d'audit

Règles : une ligne par objet modifié ; `occurred_at = $now` ; `actor_id` = utilisateur ; `operator_id` vide (réservé au lot Administration CWS) ; `batch_id` renseigné pour toute opération touchant plusieurs lignes ; `municipality_id` = **commune propriétaire de la ressource** ; avant/après réduits aux **champs métier modifiés** ; **aucun texte libre ni donnée personnelle** (description, consignes, message d'invitation, motifs sont signalés par `changed: true` ou par leur longueur) **[ARBITRAGE D5]**.

| Opération | Action | Sujet | `batch_id` | Contenu avant / après |
|---|---|---|---|---|
| Créer l'événement | `EVENT_CREATED` | `EVENT` | oui (même lot que les lignes suivantes) | après : référence, type, fuseau, dates, statut |
| (même transaction) | `PARTICIPATION_ACCEPTED`\* | `EVENT_PARTICIPATION` | oui | après : statut `ACTIVE`, `organizer: true` |
| (même transaction) | `ZONE_CREATED` | `EVENT_ZONE` | oui | après : type, statut, `confirmed: false`, `origin: AUTO_PROPOSAL` |
| Modifier l'événement | `EVENT_UPDATED` | `EVENT` | non | champs modifiés (avant/après), texte libre : `changed` |
| Activer | `EVENT_ACTIVATED` | `EVENT` | non | statut |
| Suspendre / réactiver | `EVENT_SUSPENDED` / `EVENT_REACTIVATED` | `EVENT` | non | statut ; motif : `present: true` |
| Clôturer | `EVENT_CLOSED` | `EVENT` | oui | statut ; `cause: NORMAL` ou `FROM_DRAFT` |
| (même transaction) | `PARTICIPATION_CLOSED` ×N | `EVENT_PARTICIPATION` | oui | statut avant → `CLOSED`, `cause: EVENT_CLOSED` |
| Inviter | `PARTICIPATION_INVITED` | `EVENT_PARTICIPATION` | oui | après : statut `INVITED`, commune cible |
| (même transaction) | `ZONE_CREATED` | `EVENT_ZONE` | oui | `origin: AUTO_PROPOSAL` |
| Renvoyer | `PARTICIPATION_INVITATION_RESENT` | `EVENT_PARTICIPATION` | non | date d'envoi |
| Accepter | `PARTICIPATION_ACCEPTED` | `EVENT_PARTICIPATION` | non | statut |
| Suspendre / réactiver / clôturer (participation) | `PARTICIPATION_SUSPENDED/REACTIVATED/CLOSED` | `EVENT_PARTICIPATION` | non | statut |
| Créer / modifier / confirmer / suspendre / réactiver / supprimer une zone | `ZONE_CREATED/UPDATED/CONFIRMED/SUSPENDED/REACTIVATED/DELETED` | `EVENT_ZONE` | non | champs modifiés, statut, `confirmed`, `deleted` |
| Configurer la validité | `ZONE_VALIDITY_MODE_CHANGED`, `ZONE_VALIDITY_SLOT_DELETED`, `ZONE_VALIDITY_SLOT_CREATED` | `EVENT_ZONE`, `EVENT_ZONE_VALIDITY` | oui | mode ; type de créneau, dates et heures locales, jours, choix DST (aucune donnée personnelle) |
| Opération groupée | `ZONE_BULK_OPERATION` (+ lignes par zone) | `EVENT_ZONE` | oui, **un `batch_id` pour tout le lot** | opération, zones touchées |

\* **À arbitrer** : l'événement `AuditAction` à utiliser pour la création de la participation organisatrice (`PARTICIPATION_ACCEPTED` n'est pas exact) ; proposition : réutiliser `PARTICIPATION_INVITED` avec `organizer: true`, ou ajouter `PARTICIPATION_CREATED` (valeur de `VARCHAR`, sans migration) **[ARBITRAGE D6]**.

Vérifications d'atomicité : audit persisté par le flush du runner ; rollback annule modification et audit ensemble (test avec un `AuditTrail` défaillant).

## 11. Invariants métier (vérifiés par les services, portés par le domaine)

1. Événement ⇒ participation organisatrice `ACTIVE`, même transaction (création).
2. Une participation par couple événement/commune (relecture sous verrou + `UNIQUE`).
3. L'organisatrice n'est pas invitée ; sa participation ne se clôture qu'avec l'événement.
4. Zones : participation `ACTIVE`/`SUSPENDED` seulement ; type immuable ; suppression logique sans retour ; une seule « Commune entière » non supprimée par participation.
5. Activation : au moins une zone confirmée, active, non supprimée, validité configurée (créneau non supprimé si `SPECIFIC_PERIODS`), participation `ACTIVE` (`existsActivationReadyZone`, lue sous verrou).
6. Clôture : événement + toutes les participations ouvertes dans une transaction (DM-092, remplace le prérequis A6.3) ; `INVITED` et organisatrice clôturées avec l'événement.
7. Réactivation (événement ou participation) : conserve la dernière suspension ; nouvelle suspension la remplace (DM-081).
8. Verrouillages après activation : organisatrice, type, fuseau, référence, début (DM-071) ; fin non passée (DM-082).
9. Créneaux : validés dans le fuseau de l'événement (heure inexistante refusée, choix explicite des heures ambiguës, 3 660 jours) ; remplacement = suppression logique + création ; mode/créneaux cohérents.
10. Création : droit d'utilisation exigé seulement à la création ; aucune vérification sur les événements existants (DM-073).
11. Chaque écriture : audit même transaction (§10).

## 12. Scénarios de concurrence

| # | Scénario | Résultat attendu |
|---|---|---|
| C1 | Deux créations simultanées | références distinctes et continues ; aucune attente prolongée hors du compteur |
| C2 | Même invitation envoyée deux fois | une seule participation ; l'autre : refus clair |
| C3 | Acceptation concurrente de la même invitation | une seule acceptation ; l'autre : `INVALID_PARTICIPATION_STATE` |
| C4 | Acceptation pendant la clôture de l'événement | soit acceptée puis clôturée avec l'événement, soit refusée (`EventClosed`) |
| C5 | Suspension et clôture simultanées de l'événement | sérialisées ; état final cohérent (clôturé) |
| C6 | Suppression de la dernière zone prête pendant l'activation | exclusion mutuelle (S/X) : l'activation voit l'état validé avant ou après, jamais mixte |
| C7 | Clôture d'un événement avec plusieurs participations pendant des modifications de zones | la clôture attend ; aucune zone modifiée après la clôture |
| C8 | Remplacement concurrent des créneaux d'une même zone | sérialisé par le verrou de participation ; historique complet des deux remplacements |
| C9 | Opérations groupées sur la même participation | sérialisées ; `batch_id` distincts |
| C10 | Deux communes modifient leurs zones en même temps | **non bloquées** l'une par l'autre |
| C11 | Participation clôturée par sa commune pendant la clôture de l'événement | pas d'interblocage (même ordre) ; état final cohérent |
| C12 | Interblocage provoqué artificiellement (ordre inverse) | détecté, traduit en `OperationConflict`, aucune donnée partielle |

## 13. Stratégie de tests

Tests d'intégration **MySQL sur `civipass_test`** (jamais SQLite), comme à l'étape 3 : connexions concurrentes et processus parallèles. Pour **chaque** sous-étape :

| Catégorie | Contenu |
|---|---|
| Nominal | résultat, persistance après rechargement, audit attendu (action, sujet, commune, acteur, `batch_id`) |
| Refus de permission | rôle insuffisant, instructeur, gestionnaire sur zones, `superAdmin` |
| Refus métier | états, unicité, validations, prérequis |
| Identifiants forgés | UUID étranger, inexistant, invalide : même refus ; aucune écriture, aucun verrou conservé |
| Isolation intercommunale | scénario A/B/C/D de l'étape 4 |
| Concurrence | C1 à C12 pertinents, verrous observés (délai court, `innodb_lock_wait_timeout`) |
| Rollback | `AuditTrail` défaillant et échec après écriture : **ni modification ni audit** ; compteur restauré ; `EntityManager` utilisable ou fermé selon le runner |
| Non-régression | 833 tests existants, `make quality` |

## 14. Découpage 5.2 à 5.5

**5.2 — Création d'événements et invitations**
- Préalables : correction F1 (rafraîchissement verrouillant), verrou partagé de l'événement, `EventLocker`, `EventAuditRecorder`, `OperationConflict` et traduction des erreurs techniques, dossiers `Command/` et `Result/` exclus de l'injection.
- Services : `EventCreationService::create`, `EventParticipationService::invite`, `::resendInvitation`.
- Tests : C1, C2 ; rollback (référence + événement + participation + zone + audit) ; fuseau proposé/fourni ; Polynésie non résolue.
- Arbitrages requis : D2, D4, D5, D6.

**5.3 — Transitions d'événements et de participations**
- Préalables : verrou de toutes les participations d'un événement.
- Services : `EventLifecycleService` (`update`, `activate`, `suspend`, `reactivate`, `close`), `EventParticipationService` (`accept`, `suspend`, `reactivate`, `close`).
- Tests : C3 à C7, C11, C12 ; garde d'activation sous verrou ; clôture atomique avec `batch_id` ; DM-081 ; DM-082.
- Arbitrages requis : D1, D3.

**5.4 — Zones et créneaux**
- Services : `EventZoneService` (`create`, `update`, `confirm`, `suspend`, `reactivate`, `delete`, `configureValidity`).
- Tests : C6, C8, C10 ; DST (heure inexistante/ambiguë, choix) ; limite de 3 660 jours ; historique ; chaîne zone → participation → commune ; « Commune entière » unique.
- Arbitrages requis : D7.

**5.5 — Opérations groupées, audit et consolidation**
- Services : `EventZoneBulkService::apply` ; consolidation de la matrice d'audit ; vérification de bout en bout ; documentation ; `make quality`.
- Tests : C9 ; refus en bloc ; confirmation avant écrasement ; indépendance des configurations ; audit par zone avec `batch_id`.
- Le périmètre de U5/U6, s'ils sont validés, est traité **avant 5.2** si les lectures changent (§15.1), ou dans une sous-étape dédiée.

## 15. Décisions nécessitant un arbitrage

### 15.1 U5 — visibilité des informations internes par les instructeurs (non validée)

**Compatibilité avec l'existant** : pas de contradiction avec une décision validée, mais une **évolution** de DM-084 (U3, étape 4), qui donne à la commune organisatrice la visibilité complète **au niveau de la commune** (tous rôles) et ouvre la consultation à tout rôle (U4). U5 introduit pour la première fois une visibilité **différenciée par rôle** en lecture.
**Impact** : `EventAccessPolicy::participantListVisibility` (nouveau niveau, par exemple `OrganizerOperational` pour l'instructeur), `EventParticipantReader` (projection sans motif de suspension ni message d'invitation), `InvitationView` (message d'invitation masqué à l'instructeur de la commune invitée ?), tests de visibilité de l'étape 4, DM-084/DM-057 (matrice de lecture par rôle).
**Points à trancher** : (a) quels champs sont « informations opérationnelles » (statut, nom, dates, consignes opérationnelles de l'événement) ; (b) le motif de suspension **de sa propre** participation est-il masqué aussi à l'instructeur de cette commune ; (c) le message d'invitation reçu est-il masqué à l'instructeur de la commune invitée ; (d) la liste de coordination (déjà minimale) reste-t-elle identique pour tous les rôles ; (e) les « données personnelles non nécessaires à l'instruction » : aucune n'est exposée aujourd'hui par les projections (pas d'agent, pas d'e-mail) — à confirmer.
**Pas de blocage pour 5.2** si U5 est traité séparément ; **bloquant pour la consultation** des participations si on veut l'appliquer avant d'écrire des données.

### 15.2 U6 — historique des communes participantes après clôture (non validée)

**Compatibilité** : DM-084 (U3) dit de ne pas exposer les participations `CLOSED` aux autres participantes « sauf règle documentaire validée contraire » : U6 en est l'exception proposée, donc **cohérente si elle est validée**. Compatible avec DM-056 (A6.1 : les invitations jamais acceptées passent `CLOSED` à la clôture) à condition de les distinguer des participations réellement actives : le schéma le permet (`activated_at` renseigné ⇔ participation acceptée ; l'organisatrice a `activated_at`).
**Points à trancher** : (a) « statut final » : après clôture **toutes** les participations sont `CLOSED` — le champ n'apporte rien sauf si l'on veut distinguer, par exemple, « clôturée par la commune avant la fin » de « clôturée avec l'événement » (information non stockée aujourd'hui) ; proposition : n'exposer que identifiant et nom, plus un indicateur `organizer` ; (b) à qui : communes dont la participation a été acceptée (`activated_at` non nul), organisatrice comprise ; (c) la projection historique s'ajoute-t-elle à la liste de coordination ou la remplace-t-elle après clôture ; (d) durée de conservation (RGPD, hors périmètre).
**Impact** : `EventReadModel::historyOf(eventId)` (SQL borné, filtre `activated_at IS NOT NULL`), niveau de visibilité `Coordination` étendu après clôture, tests ; DM-084 à compléter ; aucune migration.

### 15.3 Autres arbitrages

| Id | Question | Proposition |
|---|---|---|
| **D1** | Clôture d'un événement `DRAFT` alors que des communes ont déjà **accepté** (c'est possible en brouillon) : DM-077 parle de « participations invitées » | clore **toutes** les participations (aucune donnée opérationnelle à protéger en brouillon) ; les prérequis A6.3 ne s'appliquent qu'à `ACTIVE`/`SUSPENDED` |
| **D2** | Position du compteur dans l'ordre des verrous (DT-024) et exception technique `OperationConflict` | compteur en dernier ; `OperationConflict` dans `Application` |
| **D3** | Isolation et délai de verrou : `REPEATABLE READ` + règles d'ordre (proposé) ou `READ COMMITTED` ; `innodb_lock_wait_timeout` de 50 s par défaut | garder `REPEATABLE READ` ; fixer 5 s par transaction de service (session) avec traduction en `OperationConflict` |
| **D4** | Comment l'organisatrice désigne la commune invitée (identifiant, code INSEE, recherche par nom) et **quelles communes sont visibles** | par identifiant issu d'une recherche limitée aux communes `ACTIVE`, nom seulement ; refus uniforme sinon |
| **D5** | Contenu de l'audit : textes libres et données personnelles | ne jamais copier les textes libres ; signaler `changed` ou la longueur |
| **D6** | Action d'audit de la création de la participation organisatrice | ajouter `PARTICIPATION_CREATED` (sans migration) |
| **D7** | `configureValidity` : remplacement identique (aucun changement) et passage de mode | remplacement identique = aucune écriture ni audit ; `EVENT_DURATION` supprime logiquement tous les créneaux ; `SPECIFIC_PERIODS` exige au moins un créneau |
| **D8** | Ré-invitation d'une commune dont la participation est `CLOSED` | impossible : clôture définitive et unicité ; à confirmer |

### 15.4 Contradictions documentaires relevées
- **DT-024** : ordre « compteur → événement → participations → zones » ; recommandation inverse pour le compteur (F6, D2).
- **`lot-02-schema.md` §8.3** : les opérations de zones ne verrouillent que la participation ; elles doivent aussi prendre l'événement en lecture (S) pour exclure l'activation/la clôture concurrente (C6, C7). À mettre à jour en 5.2.
- **DM-084 / U5** : lecture par rôle jamais définie (voir 15.1).
- **DM-077** : formulation « participations invitées » ambiguë pour un brouillon (D1).
- Aucune contradiction avec DM-058 (« Commune entière » proposée à la création de **toute** participation, organisatrice comprise) ni DM-062 (fuseau proposé selon la commune organisatrice ; si non résolu — Polynésie —, il est obligatoire dans la commande) : ces deux règles sont **validées**, donc intégrées à la création et à l'invitation.

## 16. Mise à jour après la sous-étape 5.2 (traçabilité)

**Arbitrages tranchés** (registre : DM-085 D1, DT-024 D2, DT-036 D3, DM-086 D4, DT-037 D5, DM-087 D6, DM-088 D7, DM-089 D8, DM-090 U5, DM-091 U6 ; DT-035 validée). Les propositions des §10 à §15 restent valables sous ces réserves :
- **D2** : verrous événement → participations (id croissant) → zones → compteur ; **à la création l'événement n'existe pas** : aucun verrou d'événement n'est simulé, seule la ligne du compteur est prise, **après** validation complète ; modifie la ligne « Créer » du tableau §7 (déjà conforme) et DT-024.
- **D3** : `REPEATABLE READ` conservé ; délai de session de 5 s restauré après l'opération ; aucun retry.
- **D4** : commune désignée par identifiant ; une commune non abonnée est invitable ; aucune création d'utilisateur, d'adhésion ou d'abonnement ; invitation en attente sans expiration.
- **D6** : `PARTICIPATION_CREATED` (le « * » de la matrice §10 est levé).
- **F1** corrigé en 5.2 : les `lock()` des dépôts n'appellent plus `refresh()` ; `find()` avec mode de verrou relit déjà l'entité sous verrou ; `lockShared()` ajouté (verrou partagé `FOR SHARE`, sémantique conservée). Tests MySQL : relecture de l'état courant avec instantané préexistant, démonstration du défaut d'un `refresh()` simple, compatibilité des verrous partagés, exclusion du verrou exclusif, libération au commit et au rollback.
- **Correction de §6** : la ré-autorisation sous verrou se fait par `EventAccessPolicy` sur les entités verrouillées ; la pré-autorisation hors transaction reste (aucun verrou posé par un tiers).

**Réalisé en 5.2** : `EventCreationService::create`, `EventParticipationService::invite` et `::resendInvitation` ; `EventLocker`, `EventAuditRecorder`, `EventTransactions` ; `OperationConflict` (cas pris en charge : délai de verrou, interblocage, unicité déclarée par le service) ; commandes et résultats immuables ; U5 et U6 dans les lectures. **Limites** : `resendInvitation` enregistre la **demande** d'envoi (`lastInvitationSentAt`) — aucun mécanisme de notification n'existe dans le périmètre technique, aucun envoi n'est effectué ni confirmé ; l'interblocage réel n'est pas forçable de façon déterministe : la conversion DBAL du code 1213 est testée, pas un interblocage serveur.

## 17. Mise à jour après la validation de la 5.2 (clôture globale automatique, DM-092)

**Remplacement** : la clôture normale d'un événement n'exige plus que les autres communes aient clôturé leur participation ; **toutes les participations ouvertes (`INVITED`, `ACTIVE`, `SUSPENDED`) sont clôturées automatiquement dans la même transaction**, pour tout état de clôture autorisé (`DRAFT`, `ACTIVE`, `SUSPENDED`). Cette décision (DM-092) remplace A6.3 / DM-056 (prérequis de clôture préalable) et généralise D1 (DM-085). Les passages barrés des §3, §5 et §11 sont conservés pour la traçabilité.

**Vigilances de la 5.2 documentées** : (a) `resendInvitation` enregistre une demande, sans garantie d'envoi ; (b) la traduction des unicités dépend du nom d'index dans le message MySQL (DT-036) ; (c) invitation sans expiration acceptée pour la V1, mais jamais acceptable après la clôture de l'événement (test en 5.3).

## 18. Sous-étape 5.3 — réalisée (non committée, en attente de validation)

Services : `EventLifecycleService` (`update`, `activate`, `suspend`, `reactivate`, `close`) et `EventParticipationService` (`accept`, `suspend`, `reactivate`, `close`, en plus de `invite` et `resendInvitation`). Les invariants restent dans `Event` et `EventParticipation` ; les services orchestrent (pré-autorisation, verrou, politique rappelée sous verrou, validation avant mutation, audit dans la même transaction).

| Opération | Verrous (ordre) | Audit |
|---|---|---|
| `update` | événement X | `EVENT_UPDATED` (un seul, absent si rien ne change) ; validation à blanc sur une copie avant la première écriture |
| `activate` | événement X ; garde `existsActivationReadyZone` relue sous verrou (DM-059, R7) | `EVENT_ACTIVATED` |
| `suspend` / `reactivate` (événement) | événement X | `EVENT_SUSPENDED` / `EVENT_REACTIVATED` ; la réactivation conserve motif et date (DM-081) |
| `close` (événement) | événement X, puis toutes les participations X par identifiant croissant (`lockByEvent`) | `EVENT_CLOSED` + un `PARTICIPATION_CLOSED` par participation effectivement clôturée, `batch_id` commun (DM-092) ; rollback intégral |
| `accept`, `suspend`, `reactivate`, `close` (participation) | événement S, puis participation X | `PARTICIPATION_ACCEPTED` / `_SUSPENDED` / `_REACTIVATED` / `_CLOSED` |

Précisions :
- Aucun verrou sur le compteur global ; aucun upgrade de verrou partagé vers exclusif ; délai de verrou 5 s, aucun retry.
- La suspension d'un événement ne suspend pas ses participations (aucune décision ne le prévoit) ; la clôture globale clôture aussi les participations suspendues et les invitations en attente. L'historique est conservé (U6).
- `accept` n'exige aucun abonnement ni accès commercial (D4) et ne crée rien de commercial ; il est refusé après la clôture (vigilance 3.3 de la 5.2 testée).
- `EventParticipationRepository::lockByEvent` utilise `PESSIMISTIC_WRITE` avec `HINT_REFRESH` : sous REPEATABLE READ, une entité déjà gérée doit être relue sous verrou.
- `tests/Unit/ArchitectureTest` : `Event/Application/Service/EventLifecycleService.php` ajouté aux appelants autorisés de `activate()` (méthode homonyme de `Event`).
- Tests : `EventLifecycleServiceTest` (12) et `EventLifecycleConcurrencyTest` (7 : C3 à C7, C11, C12, deux clôtures simultanées) sur MySQL de test, processus parallèles via `tests/Support/event-action.php`.
- Aucune migration, aucune modification de schéma, aucune nouvelle décision.

### 18.1 Matrice de couverture de la 5.3

Fichiers : **S** = `EventLifecycleServiceTest`, **K** = `EventLifecycleConcurrencyTest`, **C** = `EventLifecycleCoverageTest`, **L** = `EventParticipationLockTest`. Tous sur MySQL de test.

| Opération | Nominal | Transition interdite | Permission | Isolation | Concurrence | Rollback | Audit |
|---|---|---|---|---|---|---|---|
| event.update | S | S (clôturé), C | S, C | S, C | C6 indirecte (K, C) | S (validation atomique), C (audit défaillant) | S (un audit, aucun si no-op) |
| event.activate | S | C | C | C | C (via clôture) | S (garde zone : état inchangé) | S |
| event.suspend | S | S, C | C | C | K C5, C6, C12 | C (audit défaillant) | S, K |
| event.reactivate | S | S, C | C | C | C (deux réactivations, clôture) | S | S |
| event.close | S | S, C | C | C | K C3, C4, C5 ; C | S (audit défaillant, tout annulé) | S, K (batch commun) |
| participation.accept | S | C | S, C | C (identifiants forgés) | K C3, C11 ; C (3 acceptations) | C (audit défaillant) | C |
| participation.suspend | S | C | S, C | S, C | K C7, C11, C12 | — | C |
| participation.reactivate | S | S, C | C | C | — | — | C |
| participation.close | S | S, C (organisatrice, invitation) | C | C | K C7 | — | C |
| lockByEvent | L | — | — | L (plusieurs communes) | L (délai, commit, rollback) | L | — |

Limites assumées : pas de test de rollback dédié pour `suspend`, `reactivate` et `close` de participation (même gabarit que `accept`, couvert) ; pas de test de concurrence dédié `activate` contre `update`.

## 19. Plan d'exécution des sous-étapes 5.4 et 5.5 (phase A — analyse et conception)

Base : 5.1 à 5.3 committées (`4f8bc24`). Rien de l'architecture 5.1 n'est refait. Sources : DM-006/007/009 (zones d'une participation, administrateur seul), DM-011 (mode de validité), DM-022 à DM-028 (opérations groupées), DM-051/052 (confirmation, suppression logique), DM-057, DM-058/059 (activation), DM-066, DM-068, DM-069, DM-075, DM-080, DM-088.

### 19.1 Contrats — 5.4 `EventZoneService`

| Méthode | Permission (sujet) | Préconditions relues sous verrou | Audit |
|---|---|---|---|
| `create(actor, participationId, CreateZoneCommand)` | `CreateZone` (participation) | participation ACTIVE/SUSPENDED, événement ouvert ; « Commune entière » confirmée explicitement et unique parmi les zones non supprimées (invariant 9, DM-068) | `ZONE_CREATED` (`origin: MANUAL`) |
| `update(actor, zoneId, UpdateZoneCommand)` | `ModifyZone` | zone non supprimée ; type immuable (DM-080) ; sans changement réel : rien | `ZONE_UPDATED` (champs modifiés, jamais le texte) |
| `confirm` | `ModifyZone` | idempotent : déjà confirmée ⇒ rien, aucun audit | `ZONE_CONFIRMED` |
| `suspend` / `reactivate` | `ModifyZone` | transitions du domaine (déjà suspendue / déjà active : refus) | `ZONE_SUSPENDED` / `ZONE_REACTIVATED` |
| `delete` | `DeleteZone` | zone non supprimée ; logique, sans retour (DM-052) ; créneaux conservés (non propagée) | `ZONE_DELETED` |
| `configureValidity(actor, zoneId, ZoneValidityCommand)` | `ConfigureZoneValidity` | mode `EVENT_DURATION` (sans créneau) ou `SPECIFIC_PERIODS` (au moins un créneau) ; chaque créneau inspecté dans le fuseau de l'événement relu sous verrou ; remplacement identique : rien (DM-088) | `ZONE_VALIDITY_MODE_CHANGED` (si le mode change), `ZONE_VALIDITY_SLOT_DELETED` ×n, `ZONE_VALIDITY_SLOT_CREATED` ×n, `batch_id` commun |

Résultats immuables : `ZoneResult`, `ZoneValidityResult`. Commandes : `CreateZoneCommand`, `UpdateZoneCommand`, `ZoneValidityCommand` avec `ValiditySlotInput` (données brutes : le service construit les objets du domaine temporel, aucun nouveau moteur de dates).

Aucune règle nouvelle. Précisions d'interprétation, **formalisées ensuite en DM-093, DM-094 et DM-095** (§22) :
- la confirmation n'a **aucune précondition temporelle** : DM-051/DM-059 placent la validité dans l'activation (une zone confirmée non configurée n'ouvre aucun droit) ;
- le mode `UNCONFIGURED` n'est pas une cible de `configureValidity` (état initial seulement) ;
- DM-025 (confirmation avant écrasement) ne s'applique qu'aux opérations **groupées** (son thème) ; le remplacement individuel n'exige pas de confirmation.

### 19.2 Contrats — 5.5 `EventZoneBulkService::apply`

Périmètre limité à ce que les décisions prévoient : **suspension** et **réactivation** groupées (DM-024) et **application d'une même configuration de validité** (DM-022, DM-023, DM-025, DM-026). **Non implémentés** : suppression groupée et modification groupée de propriétés — aucune décision ne les prévoit (« ne pas ajouter une opération parce qu'elle est possible »).

- Entrée : `BulkZoneCommand(operation, zoneIds[], ?ZoneValidityCommand, confirmOverwrite)` ; sélection = zones d'**une** participation (DM-028). Doublon, liste vide ou UUID invalide : refus ; zone étrangère, inexistante ou supprimée : refus en bloc uniforme (« Ressource inaccessible. »).
- Autorisation : garde de participation (`CreateZone`, même matrice : administrateur seul, état éditable) puis **politique de zone par zone** (`ModifyZone` ou `ConfigureZoneValidity`) : un service groupé n'ajoute aucun droit.
- Validation de **toutes** les zones avant la première modification (transitions du domaine, créneaux inspectés, confirmation d'écrasement : si une zone possède déjà une configuration différente, `confirmOverwrite` est exigé) ; une configuration identique n'est jamais un écrasement ni une modification (DM-088).
- Chaque zone reçoit ses propres créneaux (copie, DM-026/027).
- Audit : un par objet modifié, un `batch_id` pour tout le lot, plus une ligne `ZONE_BULK_OPERATION` (sujet : la participation) seulement si quelque chose a changé.
- Résultat : `BulkZoneResult(operation, participationId, requestedZoneIds, modifiedZoneIds, changed, batchId|null)`.

### 19.3 Verrouillage

Ordre unique : événement **S** → participation **X** → zones **X** (identifiant croissant). Nouveautés : `EventZoneRepository::lock` et `lockByIdsInParticipation` (`PESSIMISTIC_WRITE` + `HINT_REFRESH`, relecture sous verrou, `ORDER BY id`), `EventLocker::zone` et `::zones` (exigent la participation déjà verrouillée et vérifient l'appartenance). Délai 5 s, aucun retry, aucun upgrade S→X, jamais le compteur.
- L'activation et la clôture (événement X) ne coexistent donc jamais avec une opération de zone (événement S) : l'activation lit un état entièrement validé avant ou après (C6).
- Deux communes ne se bloquent pas (participations distinctes, événement S compatible) : C10.
- Deux opérations sur la même participation sont sérialisées : C8, C9.

### 19.4 Dépendances 5.4 → 5.5

Le configurateur de validité (`ZoneValidityConfigurator` : lecture des créneaux saisis, inspection, comparaison à l'existant, remplacement) est écrit en 5.4 et réutilisé tel quel par le service groupé ; les audits de zone sont ceux de la matrice §10.

### 19.5 Tests

5.4 : nominaux, permissions et isolation, temporalité (heure d'été/d'hiver, inexistante, ambiguë, choix, récurrence 3 660 jours), rollback (création, modification, confirmation, suppression, validité, audit défaillant), concurrence (activation × modification/suspension/confirmation/suppression/créneaux dans les deux ordres, clôture × zone, deux modifications). 5.5 : nominal, zone étrangère/supprimée, transition interdite, doublon, sans changement, `batch_id`, rollbacks, deux lots simultanés, lot × clôture, lot × activation, lot × opération individuelle, absence de contournement des politiques.

### 19.6 Contradictions et conditions d'arrêt

Aucune contradiction détectée entre code, décisions et plan ; aucun changement de schéma (`event_zone`, `event_zone_validity` et leurs index suffisent, `AuditAction` contient déjà les actions) ; aucune permission nouvelle. Les trois interprétations du §19.1 sont des précisions, pas des règles nouvelles.

## 20. Sous-étape 5.4 — bilan (réalisée, non committée)

`EventZoneService` : `create`, `update`, `confirm`, `suspend`, `reactivate`, `delete`, `configureValidity`, conformes au §19.1. Composants : `ZoneValidityConfigurator` (+ `ParsedValidity`, `ZoneValidityPlan`, partagés avec la 5.5), `EventLocker::zone` / `::zones`, `EventZoneRepository::lock` / `::lockByIdsInParticipation`, commandes (`CreateZoneCommand`, `UpdateZoneCommand`, `ValiditySlotInput`, `ZoneValidityCommand`) et résultats (`ZoneResult`, `ZoneValidityResult`) immuables.

- **Permissions** : administrateur communal seul ; gestionnaire, instructeur, commune participante (sur les zones d'autrui), commune étrangère, super-administrateur CWS et identifiants forgés refusés (« Ressource inaccessible. ») ; commune non abonnée mais participante autorisée.
- **Temporalité** : aucun nouveau moteur ; `SlotInspector` dans le fuseau de l'événement relu sous verrou ; heure inexistante refusée (date et heure citées), heure ambiguë : choix explicite exigé et stocké, choix obsolète refusé, récurrence : 3 660 jours acceptés, au-delà refusé ; le fuseau décide (Paris et Miquelon). Heures LOCALES stockées, jamais UTC.
- **Remplacement** : global (suppression logique + création, DM-075) ; identique (ordre indifférent) : ni écriture, ni audit, `updated_at` inchangé (DM-088) ; changement de mode : modification réelle ; historique conservé ; un `batch_id` par remplacement.
- **Tests** : `EventZoneServiceTest` (39 : nominal, permissions, temporalité, validations, 7 rollbacks par audit défaillant, y compris au 2e et 3e audit d'un remplacement) et `EventZoneConcurrencyTest` (15 : activation × suspension/suppression/confirmation/configuration, dans les deux ordres ; activation × modification/suppression/suspension/configuration ; clôture × modification, dans les deux ordres ; deux modifications ; commune A × commune B non bloquantes C10).
- **Technique** : `tests/Support/zone-action.php`, `SleepingAuditTrail` (l'opération tenante signale par fichier qu'elle tient ses verrous, puis attend avant le commit), `ParallelPhp::start/isRunning/finish`, `ZoneServiceTestCase`, `ZoneConcurrencyTestCase` ; `config/services.yaml` : deux objets de valeur exclus de l'autowiring.

## 21. Sous-étape 5.5 et consolidation — bilan (réalisées, non committées)

### 21.1 `EventZoneBulkService::apply`
Suspension et réactivation groupées (DM-024) et application d'une même configuration (DM-022, DM-023, DM-025, DM-026) conformes au §19.2. Garde de participation (`CreateZone`, même matrice administrateur seul) puis **politique de zone par zone** : aucun droit ajouté. Validation de toutes les zones avant la première écriture ; une zone étrangère, supprimée ou inconnue refuse le lot (refus uniforme) ; doublon, sélection vide, identifiant invalide, transition interdite, créneau invalide, écrasement non confirmé : refus en bloc. Sans changement réel : aucun audit, `batchId` nul. Avec changements : un audit par zone modifiée + `ZONE_BULK_OPERATION` (sujet : la participation), un `batch_id` commun.

Tests : `EventZoneBulkServiceTest` (19 : nominal, copies indépendantes, écrasement, identique, partiel, zone étrangère, sélections invalides, transition interdite, créneau invalide, aucun contournement par rôle/commune/événement clôturé, 6 rollbacks) et `EventZoneBulkConcurrencyTest` (10 : deux lots sur la même participation, deux lots identiques, ordres de sélection opposés sans interblocage sur trois rondes, lot × clôture, lot × activation dans les deux ordres, individuel × lot, lot × configuration individuelle, deux communes non bloquantes).

### 21.2 Parcours métier (`EventModuleJourneysTest`)
| Parcours | Test |
|---|---|
| A — événement simple | `testJourneyASimpleEvent` |
| B — intercommunal | `testJourneyBInterMunicipalEvent` |
| C — événement suspendu | `testJourneyCSuspendedEvent` |
| D — modification en cours d'événement | `testJourneyDChangesDuringAnActiveEvent` |
| E — opérations groupées | `testJourneyEBulkOperationsKeepTheAuditConsistent` |

Complétés par les permissions par statut : commune suspendue, inactive, compte administrateur désactivé (tout refusé, y compris le groupé).

### 21.3 Cohérence transversale
- **Permissions** : administrateur seul sur les zones (le gestionnaire gère événement et invitations, jamais les zones) ; l'organisatrice ne touche pas aux zones des participantes ; commune invitée non acceptée, suspendue ou inactive : refus ; super-administrateur CWS : aucun droit communal implicite ; le service groupé n'ajoute aucun droit.
- **Transactions** : ordre unique événement → participation(s) → zones (identifiant croissant) ; événement partagé pour les zones et participations, exclusif pour activation, clôture, modification, invitation ; aucun upgrade, aucun retry, délai 5 s, pas de transaction imbriquée (le runner la refuse), audit dans la même transaction, jamais de verrou sur le compteur hors création. Aucun interblocage observé (lots en ordres de sélection opposés, trois rondes).
- **Temporalité** : fuseau de l'événement, heures locales stockées, DST (inexistante, ambiguë, choix), récurrence 3 660 jours, créneaux remplacés conservés, zones supprimées exclues de l'activation.
- **Audit** : aucun texte libre (DT-037) ; les remplacements et lots partagent un `batch_id` ; un échec d'audit annule tout (rollbacks testés à chaque position pertinente).

### 21.4 Risques résiduels et points de vigilance
1. ~~**Horodatage d'audit** pris au début de l'opération~~ : **résolu par DT-038** (§22) ; l'horodatage est pris après l'acquisition des verrous.
2. ~~**Taille des remplacements** sans limite~~ : **résolu par DM-096** (§22) ; 100 zones × 500 créneaux dans un même lot restent possibles et lourds.
3. **Timeout** : un lot très long peut dépasser 5 s de verrou pour les opérations concurrentes (traduit en `OperationConflict`, pas de retry).
4. ~~**Interprétations** du §19.1~~ : **formalisées** par DM-093, DM-094, DM-095 (§22).
5. **Lectures** : aucun service de lecture des zones et des créneaux n'est livré en 5.4/5.5 (hors périmètre) ; les écrans devront s'appuyer sur `EventZoneRepository` / `EventZoneValidityRepository` et la politique `ViewZone`.
6. Tests de concurrence : ils reposent sur des temporisations (attente de 2,5 s, vérification d'attente de 0,6 s) ; relancés plusieurs fois sans instabilité, mais sensibles à une machine très chargée.

## 22. Passe de finalisation avant validation de TECH-02.1

Décisions formalisées (validées par le commanditaire) :
- **DT-038** — l'horodatage d'une opération (mutations et audits) est lu **après l'acquisition des verrous** ; l'identifiant d'audit (UUID v7) reste la référence d'ordonnancement. Création d'événement : lu après l'attribution de la référence (seul verrou). Test : l'horodatage d'une opération qui a attendu un verrou ≥ 2 s est postérieur à l'attente (le test échoue si l'on rétablit la lecture avant le verrou) ; variante pour la clôture et les audits de participations.
- **DM-093** — une zone peut être confirmée sans configuration temporelle ; confirmation métier et disponibilité opérationnelle restent distinctes ; conditions d'activation inchangées.
- **DM-094** — `UNCONFIGURED` est un état initial, jamais cible de `configureValidity` ; aucune réinitialisation implicite.
- **DM-095** — la confirmation avant écrasement (DM-025) s'applique aux opérations groupées ; l'individuel est une sauvegarde explicite. Vérification faite : la phrase de décision de DM-025 ne nomme pas explicitement les opérations groupées, mais son thème, son contexte et le plan 5.1 les visent ; **aucune contradiction**, DM-025 n'est pas modifiée.
- **DM-096** — plafonds : 500 créneaux par configuration, 100 zones par opération groupée ; configurables (`EVENT_MAX_SLOTS_PER_VALIDITY`, `EVENT_MAX_ZONES_PER_BULK_OPERATION`), appliqués côté backend avant toute lecture ou écriture ; valeur limite acceptée, dépassement refusé (`DomainRuleViolation`). Aucun changement de schéma.

## 23. Validation finale de TECH-02.1

**TECH-02.1 est validée et terminée.** Le commanditaire a validé les sous-étapes 5.4 et 5.5 et leur consolidation, ainsi que DT-038 et DM-093 à DM-096 (message « VALIDATION FINALE TECH-02.1 »). Aucune interface, contrôleur HTTP ni API n'est livré.

### Point bloquant avant toute exposition HTTP des opérations groupées
Le volume cumulé n'est pas plafonné : 100 zones × 500 créneaux dans une même opération restent possibles (jusqu'à 50 000 créneaux et autant d'audits dans une transaction, avec un risque de dépasser le délai de verrou de 5 s pour les opérations concurrentes). Risque **accepté temporairement** ; un plafond cumulé (ou une autre garde) doit être décidé et implémenté **avant** d'exposer `EventZoneBulkService` par HTTP (DM-096).

### Risques résiduels documentés
1. **Volume cumulé** des créneaux dans les opérations groupées (point bloquant ci-dessus).
2. **Tests de concurrence reposant sur des temporisations** (attente de 2,5 s, vérification d'attente de 0,6 s) : stables sur plusieurs relances, mais sensibles à une machine très chargée.
3. **Services de lecture** des zones et des créneaux : à développer avant les interfaces (les écrans s'appuieront sur `EventZoneRepository`, `EventZoneValidityRepository` et la politique `ViewZone`, avec des indicateurs de droits par ligne).
