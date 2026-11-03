# LOT UI-02 — Architecture UX (version validée, mise à jour du 2 novembre 2026)

Gestion des événements, des zones (avec leur validité temporelle) et de la participation intercommunale.
Ce document remplace l’architecture du lot UI-002, qui n’a pas été validé. Il intègre :

- la validation de l’architecture révisée ;
- les arbitrages E1 à E5 ;
- les arbitrages fonctionnels sur la validité des zones et la configuration en masse.

## 1. Sources et ordre de priorité

1. **SFD V1.0** (1er novembre 2026) : il fait autorité. Il est complété par les arbitrages du commanditaire du 2 novembre 2026 (§2), à reporter dans le SFD (`LOT-UI-02-modifications-SFD-STD.md`).
2. **CDC V1** : §4 événements, §5 zones et temporalités.
3. **STD v1.1** : modèle de données et workflows, à compléter (E2, E3).
4. **Brief LOT 02** : objectifs UX, écrans, responsive.

## 2. Décisions

| # | Sujet | Décision |
|---|---|---|
| D1 | Statuts, rôles, transitions | Repris du SFD et des STD. |
| D2 / E1 | Modèle des zones | **Aucune bibliothèque communale en V1.** Les zones sont créées dans un événement et rattachées à la participation de la commune. |
| D3 | Événement intercommunal | Chaque commune gère ses zones. Celles des autres communes sont en lecture seule. |
| D4 | UI-002 | Remplacé par LOT UI-02. |
| D5 | Champs d’une zone | Libellé\*, Type\* (Commune entière / Quartier / Rue / Lieu-dit / Personnalisée), Description, État (SFD SCR-017). |
| D6 | Validité des zones | Dans le périmètre (SCR-018). Parcours : Fiche événement → Zones → Fiche zone → Validité. |
| D7 | Décliner une invitation | Non (SFD SCR-014). |
| D8 / E5 | Gestion des zones | **Administrateur communal**, pour sa commune seulement. Le terme « Gestionnaire communal » est remplacé. |
| E2 | Validité | Entité de validité temporelle rattachée à chaque zone d’événement. |
| E3 | Spécifications techniques | À compléter à partir du SFD, en séparant les champs métier des choix de présentation. |
| E4 | Clôture normale | Par la commune organisatrice, quand toutes les participations sont clôturées (SFD §6.2). |
| V1 | Choix de validité | Pour chaque zone : **Pendant toute la durée de l’événement** ou **Sur des périodes spécifiques** (plusieurs créneaux). |
| V2 | Zone sans validité | Enregistrable. Elle affiche **« Validité à configurer »** (ce n’est pas un statut métier) et n’ouvre aucun droit d’accès. |
| V3 | Accès ponctuel | Une date, une heure de début, une heure de fin. |
| V4 | Récurrence | Date de début et date de fin, jours, heure de début et heure de fin. Passage de minuit possible. Le jour sélectionné est le jour de début du créneau. Une heure de début identique à l’heure de fin est refusée. |
| V5 | Chevauchements | Autorisés. Le moteur fait l’union des créneaux : la zone est valable si au moins un créneau couvre l’instant contrôlé. Un avertissement non bloquant est possible. |
| V6 | Heure | Europe/Paris. Affichage en heure de Paris, calculs sur des instants UTC, heures inexistantes ou ambiguës traitées explicitement. Aucun réglage de fuseau pour les utilisateurs. |
| V7 | Configuration en masse (prioritaire) | Sélection multiple dans la liste des zones ; « Configurer les zones sélectionnées » ; « Appliquer à toutes mes zones » ; activation ou suspension groupée. Limitée aux zones de sa commune. Confirmation explicite avant de remplacer une validité. L’activation ne délivre aucun laissez-passer et n’accorde aucun droit. |
| V8 | Simplicité | Pas d’écran ni d’étape en plus, pas de paramètre technique, **pas de modèles de configuration en V1**. Réutilisation maximale du design system. |

## 3. Statuts, rôles et permissions

| Objet | Statuts | Transitions et gardes |
|---|---|---|
| Événement | Brouillon, Actif, Suspendu, Clôturé | Brouillon → Actif si au moins une zone est confirmée (SCR-015). Actif ↔ Suspendu, avec un motif à la suspension. → Clôturé quand toutes les participations sont clôturées. |
| Participation | Invitée, Active, Suspendue, Clôturée | Chaque commune gère la sienne. La clôture est définitive. |
| Zone | Active, Suspendue (+ suppression logique) | « Commune entière » est proposée, jamais active sans confirmation (libellé « À confirmer », hypothèse Q6). **« Validité à configurer » n’est pas un statut.** |
| Commune | En attente, Active, Suspension planifiée, Suspendue, Résiliée | Une commune suspendue ne crée ni ne rejoint d’événement. Une commune non abonnée participe gratuitement, sans pouvoir créer d’événement. |

| Action | Qui |
|---|---|
| Créer un événement | Gestionnaire événement (ou administrateur selon son rôle), d’une commune active et abonnée. |
| Modifier, activer, suspendre ou clôturer l’événement ; inviter | Gestionnaire événement de la commune organisatrice. |
| Accepter une invitation | Administrateur de la commune invitée. |
| Gérer sa participation | Gestionnaire événement de la commune concernée. |
| Zones : créer, modifier, valider, activer, suspendre (unitaire ou en masse) | **Administrateur communal** de la commune concernée. |
| Consulter | Selon les permissions existantes. |

## 4. Écrans (9)

| Écran | Contenu |
|---|---|
| 01 Tableau de bord | Commune active et son statut ; Invitations ; En cours (Actifs et Suspendus) ; En préparation (Brouillons, si la commune peut créer) ; Récemment clôturés ; « Créer un événement » seulement pour une commune abonnée habilitée (absent sinon, le bandeau explique pourquoi). |
| 02 Liste des événements | Nom, statut, type, début, fin, organisatrice (avec étiquette Votre commune / Participante / Invitée), nombre de communes ; recherche, filtres statut et type, tri chronologique, pagination. |
| 03 Créer ou modifier un événement | Nom\*, type\*, description, début\*, fin ; organisatrice en lecture seule ; création **en brouillon**. |
| 04 Fiche événement | En-tête de synthèse ; onglets Vue d’ensemble · Zones · Communes participantes ; activation, suspension, réactivation, clôture ; liens vers Demandes et Laissez-passer. |
| 05 Zones de l’événement | Zones par commune (la sienne d’abord) ; recherche à partir de 6 zones ; **sélection multiple et barre d’actions groupées** (zones de sa commune seulement) ; panneau « Configurer les zones sélectionnées ». |
| 06 Fiche zone : informations | Libellé, type, description, état, rattachement ; modifier, suspendre, réactiver, supprimer, confirmer. La création propose le choix de validité, facultatif. |
| 06 b Fiche zone : validité | Choix « Pendant toute la durée de l’événement » ou « Sur des périodes spécifiques » ; liste des créneaux ; ajout et modification ; avertissement de chevauchement ; état « Validité à configurer ». |
| 07 Communes participantes | Organisatrice, participantes, invitations ; abonnement ou participation gratuite ; détail d’une participation (modale) ; « Votre participation ». |
| 08 Invitation et participation | A. Inviter (référentiel INSEE, contact, message). B. Commune invitée : consulter, puis accepter. C. Commune non abonnée. Cas d’une commune suspendue. |

### Configuration en masse (écran 05)

1. L’administrateur coche des zones de sa commune, ou choisit « Appliquer à toutes mes zones ».
2. Le panneau s’ouvre dans la page, sans changer d’écran. Il reprend la liste des zones sélectionnées et le même formulaire de validité que la fiche zone (choix de validité et un créneau).
3. Si certaines zones ont déjà une validité, une confirmation les nomme avant de la remplacer.
4. « Activer » et « Suspendre » agissent sur la sélection, après confirmation. L’activation confirme aussi les zones « À confirmer ». Elle ne délivre aucun laissez-passer et n’accorde aucun droit.
5. Le serveur vérifie que chaque zone appartient à la participation de la commune. La journalisation est faite zone par zone.

Pour rester simple en crise, le panneau groupé applique **une** validité : la durée de l’événement ou un créneau. D’autres créneaux s’ajoutent zone par zone. Il n’y a pas de modèle de configuration.

## 5. Parcours

- **Parcours principal** : Tableau de bord → Événements → Créer (brouillon) → Fiche → Zones → confirmer « Commune entière » ou créer des zones → configurer la validité (en masse ou par zone) → Communes → Inviter → Activer.
- **Parcours secondaires** :
  - consultation en lecture seule ;
  - modification depuis la fiche ;
  - rejoindre un événement depuis l’invitation ;
  - consulter un événement où sa commune participe ;
  - changer de commune ;
  - commune non abonnée ;
  - commune suspendue.

## 6. Composants

- **Réutilisés** : AppShell, ContextSelector, Banner, Alert, PageHead, SectionHead, EventCard, DataTable, FilterBar, Pagination, SearchField, Field, Select, Choice, Check, Period, ErrorSummary, ConfirmPanel, ConfirmDialog, Modal, EmptyState, Skeleton, Toast, Tag, StatusPill, Facts, Panel, ActionMenu, Timeline, SubNav, Lifecycle, Button `unavailable_reason`.
- **Nouveaux** :
  - EventHeader ;
  - ZoneCard (avec `select` et `validity_scope`) ;
  - ValiditySlot / ValidityForm (avec `with_scope`) ;
  - **BulkBar** (contrôleur `cp--bulk`) ;
  - InvitationCard ;
  - CommuneStatus ;
  - contrôleur `cp--validity`.
- **Obsolètes ou non utilisés** : ZoneMap (pas de cartographie), Stepper.

## 7. Modèle de données

Le rattachement d’une zone à une commune et à un événement passe par la participation (STD : `event_zone` → `event_participation`). Il est confirmé par le SFD (SCR-017). Les modifications à apporter au SFD et aux STD sont dans `LOT-UI-02-modifications-SFD-STD.md`.

## 8. Questions encore ouvertes (non bloquantes)

- **Q6** — Libellé « À confirmer » pour une zone « Commune entière » non confirmée : hypothèse UX, à confirmer.
- **Q7** — Rubrique « Zones » de la navigation principale : sans bibliothèque, elle ouvre les zones de l’événement en cours de la commune. Faut-il la garder, ou la retirer du menu ?
- **Q8** — Règle proposée pour les heures inexistantes (instant valide suivant, 03:00) et ambiguës (première occurrence, heure d’été) : à inscrire dans les STD.
- **Q9** — Activation d’un événement dont les zones confirmées ont toutes « Validité à configurer » : autorisée par le SFD (seule une zone confirmée est exigée). Faut-il un avertissement non bloquant à l’activation ? Non ajouté.
