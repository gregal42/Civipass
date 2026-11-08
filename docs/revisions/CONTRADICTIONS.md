# Contradictions documentaires et points à confirmer (révision 2)

Les originaux ne sont jamais modifiés. Les corrections sont portées par les propositions [SFD V1.1](SFD-V1.1-proposition-lot02.md) et [STD V1.2](STD-V1.2-proposition-lot02.md) ; les documents propres au dépôt (`docs/*.md`) ont reçu un **bandeau de révision** qui conserve le texte d'origine.

## A. Résolues

| Id | Sujet | Résolution | Traçabilité |
|---|---|---|---|
| S3 | Bibliothèque de zones / instantanés | DM-008 : supprimés | Bandeau dans `data-model.md`, `architecture.md`, `development-roadmap.md` ; STD V1.2 §1 |
| S4 | GeoJSON | DM-029 | STD V1.2 §1 |
| S5 | Validité simplifiée | DM-014 à DM-018, DM-069 | `lot-02-schema.md` §4.6 |
| S6 | « Fin strictement après début » | DM-017 : instants résolus | SFD V1.1 §5 |
| S7 | « Gestionnaire communal » | DM-009 / DM-057 | SFD V1.1 §9 |
| S8 | Fuseau variable vs Paris | DM-062 (R9) : fuseau IANA de l'événement | `lot-02-schema.md` §6.5 |
| S9 | LOT-UI-02 cite des « invariants 15 et 17 » | Le STD v1.1 en compte 10 ; le schéma LOT 02 en compte 18 (§7). Correspondance à porter dans UI-02 : 15 → invariants 10 à 11, 17 → invariants 6 et 17 *(lecture proposée : à vérifier avec l'auteur du document)* | `lot-02-schema.md` §7 |
| S10 | Qui gère la participation | DM-057 (R1, R2) | SFD V1.1 §3 |
| P1 | A13 vs LOT 01 D16/D17 | DM-073 : expiration d'abonnement ≠ suspension administrative |
| N1 / S1 | Clôture globale | **Clôture normale** = organisatrice (SFD §6.2, DM-056) ; **clôture exceptionnelle forcée** = CWS depuis Sonata (DM-065, SFD SCR-052). Le CDC §4.1 (« réservée au superadmin, seulement si plus aucune participation active ») est **remplacé** : correction proposée, original inchangé | SFD V1.1 §2 et §9 |
| N2, N3 | Participation « brouillon » ; organisatrice active à l'activation | DM-053 : `ACTIVE` dès la création | |
| N4 | Collision D1–D8 / D1–D20 | Table de correspondance ci-dessous ; IDs DM/DT du registre font foi | §D |
| N5 | Supervision CWS vs « aucun droit communal implicite » | Voir §E ; `docs/security.md` révisé (texte d'origine : commit `0a8b5fb`) | |
| REC | Deux séries REC-01..12 / REC-001..015 | Table de correspondance ci-dessous ; série de référence proposée : Annexe C `REC-001..015` ; §10.1 renommée « critères de recette synthétiques » | §F |
| A15 | Impersonation | DM-061 | |
| R1 à R9, A10 à A14 | Voir le registre | DM-057, DM-059, DM-062 à DM-066, DM-068 à DM-072 | |

## B. Restantes, documentaires

| Id | Contradiction | Action |
|---|---|---|
| S2 | 5 statuts de commune (SFD) vs 3 (code) | Analyse §C ; **aucun statut supprimé sans validation** |
| — | Le SFD ignore : opérations groupées, participation organisatrice `ACTIVE` en `DRAFT`, suppression toujours logique, référence publique, fuseaux IANA | SFD V1.1 |
| — | Ancien SF/RM : SF-11, SF-23, SF-18, SF-04 contradictoires avec le SFD ([matrice](../references/SFD/ANALYSE-matrice-sfrm.md)) | Arbitrage au lot concerné ; SF/RM non archivé |
| UI-02 | 47 écarts avec les règles validées | [ECARTS-UI-02.md](ECARTS-UI-02.md) |

## C. S2 — Statuts de commune : analyse

| Statut SFD (Écran 47 / SCR-047) | Signification | Équivalent actuel | Écart |
|---|---|---|---|
| En attente | Commune inscrite, non encore approuvée par CWS (« approuver ») | Aucun (une commune créée est `ACTIVE` ou `INACTIVE`) | Statut d'**approbation** absent |
| Active | Fonctionnement normal | `ACTIVE` | — |
| Suspension planifiée | Suspension commerciale **différée** tant qu'une participation est active | Aucun | Notion de **date** de suspension, plutôt qu'un statut |
| Suspendue | Suspension effective | `SUSPENDED` | — |
| Résiliée | Fin de contrat | `INACTIVE` (hors service) et/ou abonnement `CANCELLED` | Mélange administratif / commercial |

Constat : le SFD mélange dans un seul cycle trois dimensions que le code sépare (statut administratif `MunicipalityStatus`, abonnement `SubscriptionStatus`, participation à un événement). « En attente » relève de l'**approbation**, « suspension planifiée » est un **état transitoire daté**, « résiliée » relève de l'**abonnement**.
Conséquences d'un alignement : ajouter `PENDING` à l'enum ne coûte pas de migration (`VARCHAR`) mais modifie les règles LOT 01 (D2, D16, D17, last-admin) ; une « suspension planifiée » exige une date et un traitement planifié (lot Abonnements) ; aucune de ces évolutions n'est requise par le LOT 02, qui ne teste que « ACTIVE ». **Aucun statut n'est supprimé** ; l'alignement est renvoyé au lot Abonnements, sur validation. DM-032 reste en vigueur.

## D. N4 — Correspondance des identifiants de décision

| Identifiant d'origine | Registre |
|---|---|
| LOT 01 D1 | DT-001 |
| LOT 01 D2 | DM-032 |
| LOT 01 D3, D7, D12 | Pas de fiche dédiée (contact de commune, `lastLoginAt`, builders de test) |
| LOT 01 D4 | DT-011 |
| LOT 01 D5, D10, D20 | DT-006 |
| LOT 01 D6 | DT-005 |
| LOT 01 D8 | DT-003 |
| LOT 01 D9 | DM-033 |
| LOT 01 D11, D13 | DT-008 |
| LOT 01 D14 | DT-009 |
| LOT 01 D15, D18, D19 | DM-036 |
| LOT 01 D16 | DM-034 |
| LOT 01 D17 | DM-035 |
| UI-02 D1, D4, D5, D6, D7 | DM-037 |
| UI-02 D2 | DM-006, DM-008, DM-030 |
| UI-02 D3 | DM-007 |
| UI-02 D8 | DM-009 |

*(Correspondances établies à la lecture ; les identifiants non listés n'ont pas de fiche dédiée.)* Les documents d'origine gardent leur numérotation ; citer toujours « LOT 01 D16 » ou « UI-02 D3 » ou l'identifiant du registre.

## E. N5 — Quatre notions CWS à distinguer

| Notion | Définition | Statut | Décision |
|---|---|---|---|
| Administration technique CWS | Sonata en lecture seule (utilisateurs, communes, appartenances) ; trois permissions de supervision LOT 01 (membres, contact, audit) | En vigueur | DT-018, DM-031 |
| Impersonation auditée | Session d'assistance avec les droits d'un utilisateur, MFA, bandeau, audit de l'opérateur réel | Exigences validées, lot dédié | DM-061 |
| Clôture forcée exceptionnelle | Clôture d'un événement par un super-admin CWS habilité, justification, audit | Validée, lot support | DM-065 |
| Permissions métier communales | Création, invitation, zones, instruction… | **Jamais** accordées à CWS par défaut | DM-005, DM-057 |

## F. Correspondance des deux séries de recettes du SFD

| §10.1 | Annexe C | Observation |
|---|---|---|
| REC-01 | REC-001, REC-002 | |
| REC-02 | — | Sans équivalent en Annexe C |
| REC-03 | — (proche de REC-014) | |
| REC-04 | REC-003 | |
| REC-05 | REC-004, REC-005 | |
| REC-06 | REC-006 | |
| REC-07 | REC-008 | |
| REC-08, REC-12 | REC-007 | |
| REC-09 | REC-009 | |
| REC-10 | REC-011 | |
| REC-11 | REC-012 | |
| — | REC-010, REC-013, REC-014, REC-015 | Propres à l'Annexe C |

Correspondance établie à la lecture des intitulés : à valider avant toute renumérotation du SFD.

## G. Points résiduels (révision 6)

**DM-092** (clôture globale automatique) remplace le prérequis de clôture préalable des participations (A6.3 de DM-056, plan 5.1) ; R3 et R4 documentés (DM-086, DT-036).

**TECH-02.1 validée et terminée (validation finale du commanditaire) ; risques résiduels et point bloquant : plan 5.1 §23.** **5.4 et 5.5 — aucune contradiction documentaire ni conflit code/décisions relevé.** Trois **interprétations** ont été **formalisées** à la passe de finalisation : **DM-093** (confirmation d'une zone sans configuration temporelle), **DM-094** (`UNCONFIGURED` jamais cible de configuration) et **DM-095** (DM-025 : confirmation avant écrasement pour les opérations **groupées**). Lecture de DM-025 : sa phrase de décision ne nomme pas explicitement les opérations groupées ; son thème, son contexte et le plan 5.1 les visent — **ambiguïté levée sans contradiction et sans réécriture de DM-025**. Horodatage d'audit : **DT-038**. Plafonds de sécurité : **DM-096**. Suppression groupée et modification groupée de propriétés **non implémentées** (aucune décision ne les prévoit). Détail : plan 5.1 §19.1, §19.2 et §21.

Résolus en 5.2 : **D1 à D8, U5, U6** (DM-085 à DM-091, DT-024, DT-036, DT-037) ; **U5 et U6** remplacent les points U5/U6 de l'étape 4.


Résolus à l'étape 4 : **U1** (DM-057 conservé), **U2**, **U3**, **U4** (DM-084).

Résolus à l'étape 2 : **T2** (DM-082), `revoked_by` (DM-079), type de zone immuable (DM-080), historique de suspension (DM-081), limite de 3 660 jours (DM-083).

Résolus : **T1** (droits d'utilisation : DM-076), **DRAFT → CLOSED** (DM-077), **liste des `EventType`** (DM-053, DT-023).

Les points P1 à P6, DT-020 et DT-022 sont **validés** (DM-062, DM-070, DM-071, DM-072 à DM-075, DT-020, DT-022). **A13 vs D16/D17 est résolu** : l'expiration d'abonnement n'interdit que la création (DM-073) ; la suspension administrative reste régie par D16/D17.

| Id | Point de détail | Défaut proposé | Bloquant |
|---|---|---|---|
| T3 | Persistance des habilitations CWS | décidée dans le lot Administration CWS | Non |
| T4 | Modification d'un créneau unique | remplacement (suppression logique + création) | Non |
| T5 | `SUSPENDED` sans motif | tout `SUSPENDED` suit D16/D17 | Non |
| T6 | Fuseau de l'année de référence | UTC | Non |
| — | Référentiel territorial : communes polynésiennes par archipel | à constituer depuis une source officielle | Non pour la migration ; requis avant production |
| DT-030 | Table `municipality_commercial_access` (accès commercial CWS) | à valider avant la migration | Avant l'étape 3 |
| — | Résolution fuseau des communes de Polynésie française | aucune hypothèse ; liste officielle à fournir | Avant production |
| — | MFA absent | prérequis du lot Administration CWS | Non |
| — | Écarts UI-02 (47) non réévalués après P1–P6 | à corriger côté design | Avant intégration UI |
| **R1** | `EventAccessPolicy::forParticipation(ViewParticipation)` reste une décision sur l'entité (tous rôles) ; une future vue de détail d'une participation devra utiliser une projection tenant compte du rôle (U5) | à traiter avec la lecture de détail | Non |
| **R2** | Invitation en attente sans expiration ni relance automatique (D4) : une commune sans administrateur la conserve indéfiniment | mécanisme d'habilitation futur | Non |
| **R3** | `resendInvitation` enregistre la demande, jamais un envoi effectif (aucune notification dans le périmètre) | lot Notifications | Non |
| **R4** | La traduction des unicités repose sur le nom d'index dans le message MySQL ; l'interblocage réel n'est pas forçable de façon déterministe (conversion DBAL testée) | à surveiller | Non |

## H. LOT 03 — Demandes (5 novembre 2026)

| Id | Contradiction | Résolution | Traçabilité |
|---|---|---|---|
| Q1 | Motif : texte obligatoire (SFD) / liste (UX) / `reason_code` (modèle) | Référentiel distinct de la catégorie, libellés neutres | DM-097 |
| Q2 | Catégories : sept (CDC) / « particulier-professionnel » (roadmap) / cinq (UI-03) | Cinq, référentiel administrable | DM-098 |
| Q3 | Modes cumulables (STD, UX) / « piéton/vélo ou véhicule(s) » (SFD) | Exclusif | DM-103 |
| Q4 | `LP-AAAA-…` (SFD SCR-026) / `DEM-AAAA-NNNN` (UI-001) | `DEM-` pour les demandes, `LP-` pour les laissez-passer | DM-105 |
| Q5 | « Zone ouverte aux demandes » non définie | `mayOpenRights()` | DM-104 |
| Q6 | SFD SCR-022 et REC-02 : commune invitée ciblable, instruction différée | **Sans objet** : une commune invitée n'a aucune zone proposable ; elle n'instruit que les demandes déposées après être devenue opérationnelle | DM-104, DM-101 |
| Q7 | CDC §7.2 : création directe d'un dossier **et** d'un laissez-passer | Dossier au LOT 03, laissez-passer reporté | DM-099 |
| Q8 | Dossier « figé » (roadmap) / correction d'identité par les agents (SFD §6.3) | Figé au LOT 03 ; corrections historisées au LOT 04 | lot-03-schema §4.3 |
| Q9 | Plaques étrangères | Hors V1 (conséquence de DM-103), risque accepté | DM-103 |

Arbitrages S1 à S8 validés le 5 novembre 2026 ([lot-03-schema.md](../lot-03-schema.md) §15). Reste à valider : textes juridiques de confidentialité (avant production).


## H. LOT 04.0 / 04.1 — contradictions relevées et traitement

| Réf. | Contradiction | Traitement |
|---|---|---|
| L4-1 | DM-099 §2 (« aucune délivrance automatique ») et §5 (délivrance directe reportée) contre la délivrance directe du LOT 04 | **DM-108** remplace ces deux points ; DM-099 annotée, texte conservé |
| L4-2 | `lot-03-schema.md` : décision « totale ou partielle » contre décision binaire V1 | **DM-106** ; mention annotée |
| L4-3 | Docblock de `RequestSubmissionService::submitByAgent` : zones d'autres communes demandables ; le code les refuse | DM-108 §5 tranche (zones de sa commune seulement) ; docblock à corriger avec la délivrance directe (04.3) |
| L4-4 | Roadmap : durées 24/48/72 h rattachées au LOT 05 ; brief LOT 04 : choix de durée à l'approbation | **DM-109** : calcul au LOT 04, cycle de vie au LOT 05 ; roadmap mise à jour |
| L4-5 | Roadmap, `lot-03-schema.md`, STD §2.2 : notes privées et verrou métier de 15 min au LOT 04 | **Retirés de la V1** (DM-106/lot-04-schema §1) ; mentions annotées, STD non modifié |
