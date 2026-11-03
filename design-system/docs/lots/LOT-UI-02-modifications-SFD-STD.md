# LOT UI-02 — Modifications à apporter au SFD et aux STD

Ce document liste, sans les appliquer, les changements que les arbitrages du LOT UI-02 imposent au SFD V1.0 et aux STD v1.1. Les maquettes et le prototype suivent déjà ces décisions.

## A. SFD V1.0

| Réf. | Section actuelle | Modification demandée | Origine |
|---|---|---|---|
| S1 | §2, ligne Zone : « Commune entière, quartier, rue, lieu-dit, personnalisée ; active/suspendue » | Ajouter : la zone est créée dans le contexte d’un événement, rattachée à la participation de la commune ; aucune bibliothèque communale en V1. | E1 |
| S2 | SCR-016, SCR-017, SCR-018 : acteur « Gestionnaire communal » | Remplacer par « Administrateur communal », limité à sa commune. | E5 |
| S3 | §6.2 et SCR-011 | Confirmer : la clôture normale relève de l’organisatrice une fois toutes les participations clôturées. Le CDC §4.1 (réservée au super-administrateur) est à aligner. | E4 |
| S4 | SCR-018 Validité de zone | Ajouter le choix de validité par zone : « Pendant toute la durée de l’événement » ou « Sur des périodes spécifiques » (un ou plusieurs créneaux). | V1 |
| S5 | SCR-017 / SCR-018 | Une zone peut être enregistrée sans validité : affichage « Validité à configurer », sans statut métier ; aucun droit d’accès tant que la validité n’est pas configurée. | V2 |
| S6 | SCR-018 « Mode continu, ponctuel ou récurrent » | Préciser :<br>• accès ponctuel : une date, une heure de début et une heure de fin ;<br>• récurrence : date de début et de fin, jours, heures, passage de minuit possible ; le jour sélectionné est le jour de début ; début identique à la fin refusé. | V3, V4 |
| S7 | SCR-018 « Chevauchement … règle technique à confirmer » | Remplacer par : chevauchements autorisés ; union des créneaux ; avertissement non bloquant possible. | V5 |
| S8 | SCR-018 « fuseau horaire Europe/Paris : règle technique à confirmer » ; annexe D | Remplacer par : affichage en heure de Paris, calculs sur des instants UTC, heures inexistantes ou ambiguës traitées explicitement (règle en STD), aucun réglage de fuseau pour l’utilisateur. | V6 |
| S9 | SCR-016 Gestion des zones | Ajouter la configuration en masse :<br>• sélection multiple ;<br>• « Configurer les zones sélectionnées » ;<br>• « Appliquer à toutes mes zones » ;<br>• activation ou suspension groupée ;<br>• limitée aux zones de sa commune ;<br>• confirmation avant remplacement d’une validité ;<br>• l’activation ne délivre aucun laissez-passer et n’accorde aucun droit. | V7 |
| S10 | Annexe B, matrice des permissions | Ajouter une ligne « Gérer les zones (unitaire et en masse) » : Administrateur communal = Oui pour sa commune ; autres rôles = Non ; CWS = via impersonation seulement. | E5, D8 |
| S11 | §1.3 Exclus V1 | Ajouter : modèles de configuration de validité. | V8 |

## B. STD v1.1

### B1. Modèle conceptuel

- **E1** : `event_zone` porte lui-même les données de la zone. Le lien `ZONE o|--o{ EVENT_ZONE : snapshots` et la table `zone` (possédée par la commune) sont **retirés de la V1**. Une autre possibilité est de les garder sans aucun usage, mais il faut alors le documenter comme hors V1.
- **E2** : ajouter `EVENT_ZONE ||--o{ EVENT_ZONE_VALIDITY_SLOT : bounds`.

### B2. Dictionnaire (E3) : champs métier et choix de présentation

Le type MySQL indiqué est une proposition. Les colonnes `id`, `created_at` et `updated_at` suivent les conventions des STD (§0).

**`event`**

| Champ | Type proposé | Nature | Remarque |
|---|---|---|---|
| name | VARCHAR(160) | Métier | Obligatoire. |
| event_type_id | FK | Métier | Référentiel SCR-053 ; le type doit être actif à la création. |
| description | TEXT ? | Métier | Facultative. |
| starts_at | DATETIME(6) | Métier | Obligatoire ; stockée en UTC, saisie en heure de Paris. |
| ends_at | DATETIME(6) ? | Métier | Facultative ; strictement après starts_at. |
| organizer_municipality_id | FK | Métier | Commune active à la création ; non modifiable. |
| status | ENUM(draft, active, suspended, closed) | Métier | Workflow STD §5. |
| suspension_reason | TEXT ? | Métier | Obligatoire à la suspension. |
| activated_at, suspended_at, closed_at | DATETIME(6) ? | Métier | Horodatage des transitions. |
| — | — | Présentation | Pastilles, libellés « En cours », « En préparation », « Récemment clôturés » : regroupements d’affichage, sans colonne. |

**`event_participation`**

| Champ | Type proposé | Nature | Remarque |
|---|---|---|---|
| event_id, municipality_id | FK | Métier | UNIQUE(event_id, municipality_id). |
| status | ENUM(invited, active, suspended, closed) | Métier | — |
| invited_at, activated_at, suspended_at, closed_at | DATETIME(6) ? | Métier | — |
| invitation_contact_email | VARCHAR(254) ? | Métier | SCR-014. |
| invitation_message | TEXT ? | Métier | Facultatif. |
| suspension_reason | TEXT ? | Métier | Obligatoire à la suspension (STD §5). |
| — | — | Présentation | « Participation gratuite · sans abonnement » se calcule à partir de l’abonnement de la commune ; pas de colonne. |

**`event_zone`**

| Champ | Type proposé | Nature | Remarque |
|---|---|---|---|
| event_participation_id | FK | Métier | Rattachement commune et événement. |
| label | VARCHAR(160) | Métier | Obligatoire (SCR-017). |
| zone_type | ENUM(whole_municipality, district, street, locality, custom) | Métier | Obligatoire. |
| description | TEXT ? | Métier | Facultative. |
| status | ENUM(active, suspended) | Métier | — |
| confirmed_at | DATETIME(6) ? | Métier | Null tant que « Commune entière » n’est pas confirmée (SCR-015). |
| validity_scope | ENUM(event, periods) ? | Métier | **Null = validité non configurée** (aucun droit). |
| deleted_at | DATETIME(6) ? | Métier | Suppression logique si la zone est utilisée. |
| — | — | Présentation | « À confirmer » (hypothèse Q6) et « Validité à configurer » sont des libellés calculés, pas des statuts. |

**`event_zone_validity_slot`** (nouvelle table, E2)

| Champ | Type proposé | Nature | Remarque |
|---|---|---|---|
| event_zone_id | FK | Métier | — |
| mode | ENUM(continuous, single, recurring) | Métier | — |
| start_local_date, end_local_date | DATE | Métier | Continu : début et fin. Ponctuel : la même date. Récurrence : bornes. |
| start_local_time, end_local_time | TIME | Métier | Heure de Paris saisie. Récurrence : début ≠ fin ; fin < début = le lendemain. |
| weekdays | SET(1..7) ? | Métier | Récurrence ; jour de début du créneau. Null = tous les jours. |
| — | — | Technique | Les instants UTC sont calculés à la lecture ou matérialisés (index). Le choix est à arbitrer. |
| — | — | Présentation | Résumé en phrase (« Tous les jours, de 07:00 à 19:00… ») : généré, non stocké. |

### B3. Règles de calcul (§5 Workflows, nouvelle sous-section « Validité des zones »)

1. Validité effective d’une zone :
   - `validity_scope = event` : de starts_at à ends_at (ou jusqu’à la clôture si ends_at est vide) ;
   - `periods` : union des créneaux ;
   - null : aucune validité.
2. Les droits effectifs restent l’intersection des validités applicables (SFD Écran 18) : événement, participation, zone, créneaux, autorisation.
3. Les heures locales sont converties en instants UTC avec Europe/Paris.
   - **Heure inexistante** (passage à l’heure d’été) : instant valide suivant (proposition Q8).
   - **Heure ambiguë** (retour à l’heure d’hiver) : première occurrence, en heure d’été (proposition Q8).
   - L’interface signale ces cas à la saisie.
4. Un créneau récurrent qui passe minuit commence le jour sélectionné et se termine le lendemain.
5. Les chevauchements ne sont pas une erreur ; un avertissement peut être renvoyé dans la réponse.

### B4. Contrats API (proposition, §6)

| Méthode | Route indicative | Acteur | Effet |
|---|---|---|---|
| POST | /api/v1/events/{eventId}/zones | Administrateur communal | Création, avec validité facultative. |
| PATCH | /api/v1/event-zones/{zoneId} | Administrateur communal | Modification. |
| PUT | /api/v1/event-zones/{zoneId}/validity | Administrateur communal | Choix event / periods et créneaux. |
| POST | /api/v1/events/{eventId}/zones/bulk-validity | Administrateur communal | { zoneIds[], scope, slot? } ; refus si une zone n’appartient pas à la participation ; réponse avec les zones remplacées ; audit par zone. |
| POST | /api/v1/events/{eventId}/zones/bulk-status | Administrateur communal | { zoneIds[], action: activate \| suspend } ; activate confirme aussi les zones non confirmées ; aucun droit ni laissez-passer créé. |

La confirmation avant remplacement est une étape d’interface. L’API accepte un indicateur `confirmReplace` pour éviter les remplacements involontaires (proposition).
