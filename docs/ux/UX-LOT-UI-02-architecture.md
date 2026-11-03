# LOT UI-02 — Architecture UX (phase 1, révisée)

Gestion des événements, des zones (avec leur validité) et de la participation intercommunale.
Ce document remplace l'architecture du lot UI-002 livré le 2 novembre 2026, qui n'avait pas été validé.

## 1. Sources et ordre de priorité

1. **SFD V1.0** (1er novembre 2026) : référentiel fonctionnel consolidé. Il fait autorité.
2. **CDC V1** : il complète le SFD (§4 événements, §5 zones et temporalités).
3. **STD v1.1** : modèle de données et workflows. Plusieurs points y sont encore « À préciser ».
4. **Brief LOT 02** : objectifs UX, écrans, responsive. Il est complété par les arbitrages du commanditaire.

Quand deux sources divergent, le SFD l'emporte et l'écart est signalé (§8).

## 2. Décisions du commanditaire (phase 1)

| # | Sujet | Décision |
|---|---|---|
| D1 | Statuts, rôles, transitions | Repris du SFD et des STD, sans en inventer. |
| D2 | Modèle des zones | Zones rattachées à un événement, plus précisément à la participation d'une commune. Pas d'écran de bibliothèque communale en V1. |
| D3 | Zones d'un événement intercommunal | Chaque commune gère les zones de son territoire. Celles des autres communes sont en lecture seule. |
| D4 | UI-002 | Remplacé par LOT UI-02. ZoneMap devient obsolète ; l'assistant en 3 étapes, la bibliothèque et l'écran Utilisateurs sont retirés du prototype. |
| D5 | Champs d'une zone | Ceux du SFD SCR-017 seulement : **Libellé\***, **Type\*** (Commune entière / Quartier / Rue / Lieu-dit / Personnalisée), **Description** (facultative), **État**. |
| D6 | Validité des zones | **Dans le périmètre** (SFD SCR-018 et CDC §5.2). Parcours : Fiche événement → Zones → Fiche zone → Validité. |
| D7 | Décliner une invitation | Non : conforme au SFD (Invitée → Active, invitation sans expiration). |
| D8 | Qui gère les zones | L'**administrateur communal**, dans le périmètre de sa commune. Pas de rôle « gestionnaire communal ». Pas d'administration des zones d'une autre commune. Pas de droit communal implicite pour le Super Admin CWS. Les actions ne s'affichent qu'aux utilisateurs habilités. |

## 3. Statuts, rôles et permissions utilisés

| Objet | Statuts | Transitions et gardes |
|---|---|---|
| Événement | Brouillon, Actif, Suspendu, Clôturé | Brouillon → Actif si au moins une zone confirmée (SCR-015). Actif ↔ Suspendu, avec un motif à la suspension. → Clôturé quand toutes les participations sont clôturées (SFD §6.2). |
| Participation | Invitée, Active, Suspendue, Clôturée | Chaque commune active, suspend, réactive ou clôture la sienne. La clôture est définitive. L'organisatrice ne clôture pas la participation d'une autre commune. |
| Zone | Active, Suspendue, + suppression logique si utilisée | « Commune entière — [nom] » est proposée à la création, mais n'est jamais active sans confirmation explicite. Une zone suspendue neutralise aussi le droit « Commune entière ». |
| Commune | En attente, Active, Suspension planifiée, Suspendue, Résiliée | Une commune suspendue ne crée ni ne rejoint d'événement. Une commune non abonnée invitée a un accès gratuit limité à l'événement et ne peut pas en créer. |

Rôles municipaux, cumulables et définis par commune : **Administrateur communal**, **Gestionnaire événement**, **Agent instructeur**.

| Action | Qui (SFD annexe B, sauf D8) |
|---|---|
| Créer un événement | Gestionnaire événement ; administrateur communal selon son rôle. Commune active et abonnée. |
| Modifier, activer, suspendre ou clôturer l'événement | Gestionnaire événement de la commune organisatrice. |
| Inviter une commune | Gestionnaire événement de la commune organisatrice. |
| Accepter une invitation | Administrateur de la commune invitée (SCR-014). |
| Suspendre, réactiver ou clôturer sa participation | Gestionnaire événement de la commune concernée. |
| Créer ou modifier des zones et leur validité | **Administrateur communal** de la commune concernée (D8). |
| Consulter | Les membres de la commune, selon leurs permissions existantes. |

## 4. Périmètre révisé : 9 écrans

| Écran | Contenu | SFD |
|---|---|---|
| 01 Tableau de bord communal | Bandeau de la commune active et de son statut ; En cours (Actifs et Suspendus) ; En préparation (Brouillons) ; Récemment clôturés ; Invitations ; « Créer un événement » si autorisé ; aucun compteur de demandes | SCR-007 |
| 02 Liste des événements | Nom, type, statut, début, fin, organisatrice, nombre de communes, étiquette « Participante » ; recherche par nom, filtres statut et type, tri chronologique, pagination | SCR-011 |
| 03 Créer un événement | Nom\*, type\* (référentiel), début\* (date et heure), description, fin ; organisatrice en lecture seule ; enregistrement **en brouillon** | SCR-012 |
| 04 Fiche événement | En-tête de synthèse et onglets **Vue d'ensemble · Zones · Communes participantes** ; activation, suspension et clôture selon les droits ; liens vers Demandes et Laissez-passer (autres lots) | SCR-010, 011, 015 |
| 05 Zones de l'événement | Zones regroupées par commune, avec libellé, type, état, résumé de validité et commune ; recherche à partir de 6 zones ; « Commune entière » à confirmer | SCR-016 |
| 06 Fiche zone : informations | Libellé\*, type\*, description, état ; création, consultation, modification ; suspension, réactivation, suppression logique | SCR-017 |
| 06 b Fiche zone : validité | Liste des créneaux de la zone ; ajout et modification d'un créneau en mode continu, ponctuel ou récurrent | SCR-018 |
| 07 Communes participantes | Organisatrice, participantes, invitations en attente, statut de chaque participation ; « Ma participation » ; détail d'une participation | SCR-013 |
| 08 Invitation et participation | A. Inviter (recherche INSEE, contact, message). B. Commune invitée : consulter, puis accepter. C. Commune non abonnée : bandeau « Participation gratuite limitée à cet événement » | SCR-014, 013 |

### Validité d'une zone (06 b) : ce que les spécifications fixent

- **Modes** :
  - plage continue entre deux dates ;
  - accès ponctuel ;
  - récurrence journalière ou hebdomadaire (CDC §5.2, SFD SCR-018).
- **Champs** : dates, heures, et jours si récurrence (SCR-018). Une zone peut avoir plusieurs créneaux (« Ajouter / éditer créneau »).
- **Règles** :
  - la fin est strictement postérieure au début ;
  - le fuseau horaire est explicite : heure de Paris (Europe/Paris), évaluée dans le fuseau de l'événement selon les STD ;
  - les horaires demandés par le citoyen ne sont jamais saisis ici ;
  - les droits effectifs sont l'intersection des validités applicables.
- **Proposition d'interface** :
  - un résumé en phrase pour chaque créneau, par exemple « Tous les jours de 07:00 à 20:00, du 01/11/2026 au 05/11/2026 (heure de Paris) » ;
  - un formulaire qui n'affiche que les champs du mode choisi ;
  - le mode continu réutilise le composant Period (UI-001).
- **Points non tranchés** : voir §8, questions Q1 à Q5. Aucune règle n'est inventée.

## 5. Parcours

- **Parcours principal** : Tableau de bord → Événements → Créer (brouillon) → Fiche → Zones → confirmer « Commune entière » ou créer une zone → Fiche zone → Validité → Communes participantes → Inviter → Activer l'événement.
- **Consulter** : liste ou tableau de bord → fiche en lecture seule (aucune action affichée sans droit).
- **Modifier** : Fiche → « Modifier les informations » (gestionnaire de l'organisatrice).
- **Rejoindre** : carte d'invitation → écran d'invitation → « Accepter » (administrateur de la commune invitée) → la participation devient Active → fiche en tant que participante → zones de sa commune.
- **Consulter un événement où sa commune participe** : fiche avec l'étiquette « Participante », en lecture seule sur les informations générales.
- **Changer de commune** : sélecteur UI-001, rechargement, bannière, statut de la nouvelle commune.
- **Commune suspendue** : bandeau explicite ; Créer et Accepter sont retirés.

## 6. Composants

- **Réutilisés** : AppShell, ContextSelector, Banner, Alert, PageHead, SectionHead, EventCard, DataTable, FilterBar, Pagination, SearchField, Field, Select, Choice, Check, Period, ErrorSummary, ConfirmPanel, ConfirmDialog, EmptyState, Skeleton, Toast, Tag, StatusPill, Facts, Panel, ActionMenu, Timeline.
- **Repris de UI-002** (propositions déjà documentées) : SubNav pour les onglets de la fiche, Lifecycle pour les statuts du SFD, Button `unavailable_reason`, Timeline `detail`, textes réglables de `cp--unsaved-guard`.
- **À créer** :
  - `event_header` : synthèse de l'événement et lien de la commune active à l'événement ;
  - `zone_card` : zone en carte (libellé, type, état, commune, résumé de validité, actions permises) ;
  - `validity_slot` : créneau en lecture (résumé) et en édition (mode, champs conditionnels) ;
  - `invitation_card` : invitation à traiter ;
  - `commune_status` : statut de la commune (abonnée, participation gratuite, suspendue).
- **Retirés ou obsolètes** : ZoneMap (pas de cartographie en V1), Stepper (plus utilisé ; gardé dans le DS, marqué non utilisé).

## 7. Vérification du modèle de données (demandée par le commanditaire)

Ce que prévoient le SFD et les STD pour le rattachement d'une zone à une commune et à un événement :

- **SFD SCR-017**, critère de recette : « La zone créée reste attachée à une commune et un événement ». Le rattachement est prévu.
- **STD, modèle conceptuel** :
  - `EVENT_PARTICIPATION ||--o{ EVENT_ZONE : configures` : une zone d'événement appartient à la participation d'une commune, donc à une commune et à un événement ;
  - invariants 15 et 17 : une zone demandée, ou une révision de droit, appartient à la même participation ;
  - le rattachement existe donc, par `event_zone` → `event_participation` → (`event`, `municipality`).

**Écarts relevés.** Je ne modifie rien dans le modèle : ce sont des corrections à valider.

| # | Écart | Correction fonctionnelle proposée |
|---|---|---|
| E1 | Les STD prévoient aussi une table `zone` possédée par la commune (`MUNICIPALITY ||--o{ ZONE`), liée à `event_zone` par « snapshots ». C'est une bibliothèque communale, que ni le SFD ni la décision D2 ne retiennent. | Préciser dans les STD que `event_zone` porte lui-même libellé, type, description et état, et que le lien vers `zone` est facultatif et inutilisé en V1 (ou supprimé). Décision à prendre par l'équipe technique. |
| E2 | **Aucune table pour la validité des zones** (SCR-018) dans le modèle ni dans le dictionnaire. | Ajouter une entité, par exemple `event_zone_validity`, liée à `event_zone` : mode (continu, ponctuel, récurrent), dates, heures, jours. Les champs exacts sont à fixer avec les réponses Q1 à Q5. |
| E3 | Les tables `event`, `event_participation`, `zone` et `event_zone` n'ont que `id` (« À préciser »). | Les champs des maquettes LOT UI-02 (§4) serviront de base au dictionnaire. Ils sont à reporter dans les STD. |
| E4 | Clôture globale : SFD §6.2 et SCR-011 la confient à l'organisatrice une fois les participations clôturées ; CDC §4.1 la réserve au super-administrateur. | Suivre le SFD (plus récent) ; la clôture forcée par CWS se fait dans Sonata, hors lot. À acter dans le CDC. |
| E5 | « Gestionnaire communal » (SFD SCR-016 à 018) n'est pas un rôle défini. | D8 : administrateur communal. Corriger le libellé dans le SFD. |

## 8. Questions restantes, à confirmer sans bloquer la maquette

Elles ne sont pas tranchées par les spécifications. La maquette affiche la question au lieu de choisir une règle.

- **Q1 — « Accès ponctuel »** : un jour avec une heure de début et une heure de fin, ou un instant précis ? Le CDC dit seulement « accès ponctuel ».
- **Q2 — Récurrence** : faut-il des bornes de dates (du… au…) ? Les créneaux qui passent minuit (22:00 → 06:00) sont-ils permis, alors que la fin doit être strictement après le début ?
- **Q3 — Chevauchement de créneaux** : « règle technique à confirmer » (SCR-018). Aucun contrôle ni message n'est inventé.
- **Q4 — Zone sans créneau** : valable pendant tout l'événement, ou aucun droit possible ? La maquette affiche « Aucun créneau défini » sans interprétation.
- **Q5 — Changement d'heure** : le CDC renvoie aux tests ; aucune règle dans l'interface.
- **Q6 — Libellé d'affichage de « Commune entière » proposée** et non confirmée : proposition « À confirmer », avec une action « Confirmer cette zone ».

## 9. Livrables de la phase 2

Les écrans 01 à 08 et 06 b, en 1440, 768 et 375 px, avec leurs états :

- chargement, vide, aucun résultat ;
- erreur de validation, erreur technique ;
- action interdite, lecture seule ;
- confirmations de création et de modification ;
- invitation envoyée, invitation en attente ;
- commune suspendue, commune non abonnée.

Viennent ensuite les nouveaux composants, le prototype navigable, la mise à jour de l'archive du design system, le changelog (avec les retraits issus de UI-002) et les instructions d'intégration.
