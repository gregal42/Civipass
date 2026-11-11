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
| Q8 | Dossier « figé » (roadmap) / correction d'identité par les agents (SFD §6.3) | Figé au LOT 03 ; **résolue au LOT 04.4** (DM-111, DT-045) : correction des seuls champs d'identité et de contact, tracée dans l'audit par commune (noms des champs, jamais les valeurs ; pas de table d'historique des valeurs, arbitrage du 9 novembre 2026) | lot-03-schema §4.3, lot-04-schema §9 |
| Q9 | Plaques étrangères | Hors V1 (conséquence de DM-103), risque accepté | DM-103 |

Arbitrages S1 à S8 validés le 5 novembre 2026 ([lot-03-schema.md](../lot-03-schema.md) §15). Reste à valider : textes juridiques de confidentialité (avant production).


## H. LOT 04.0 / 04.1 — contradictions relevées et traitement

| Réf. | Contradiction | Traitement |
|---|---|---|
| L4-1 | DM-099 §2 (« aucune délivrance automatique ») et §5 (délivrance directe reportée) contre la délivrance directe du LOT 04 | **DM-108** remplace ces deux points ; DM-099 annotée, texte conservé |
| L4-2 | `lot-03-schema.md` : décision « totale ou partielle » contre décision binaire V1 | **DM-106** ; mention annotée |
| L4-3 | Docblock de `RequestSubmissionService::submitByAgent` : zones d'autres communes demandables ; le code les refuse | DM-108 §5 tranche (zones de sa commune seulement) ; docblock remplacé au 04.3 (`issueDirectly`) |
| L4-4 | Roadmap : durées 24/48/72 h rattachées au LOT 05 ; brief LOT 04 : choix de durée à l'approbation | **DM-109** : calcul au LOT 04, cycle de vie au LOT 05 ; roadmap mise à jour |
| L4-5 | Roadmap, `lot-03-schema.md`, STD §2.2 : notes privées et verrou métier de 15 min au LOT 04 | **Retirés de la V1** (DM-106/lot-04-schema §1) ; mentions annotées, STD non modifié |


## I. LOT 05.0 — contradictions relevées et traitement

| Réf. | Contradiction | Traitement |
|---|---|---|
| L5-1 | `security.md`, `architecture.md`, roadmap et STD : page publique du scan **sans nom**, plaques seules ; brief 05.0 : identité réservée à un contrôleur habilité ; **rectificatif final** : nom, prénom, plaques, zones et validité par zone **publics**, sans habilitation | **DM-115** (arbitrage du 9 novembre 2026) : les anciens textes sont réécrits avec mention de la version précédente ; risque RGPD résiduel documenté |
| L5-2 | Roadmap : contrôle au LOT 06 (PWA, activation d'appareils) ; `data-model.md` : `ControllerActivation`, `ControllerDevice` | **DM-115** : contrôle public en ligne au **LOT 05.4** ; habilitation et appareils **retirés** de la V1 ; le LOT 06 ne garde que la PWA hors ligne, à redéfinir |
| L5-3 | Roadmap : notifications et outbox au LOT 07 | **DM-117 / DT-049** : notifications indispensables au LOT 05.3 ; le LOT 07 garde les évolutions de communication |
| L5-4 | `data-model.md` : état stocké sur `Permit` ; `PermitAuthorization` « statut » | **DM-112** : modèle hybride, validité **calculée** et jamais persistée ; faits administratifs seuls persistés |
| L5-5 | `GrantWindowCalculator::effectivePeriods()` ignore le statut administratif de la commune ; DM-035 l'exige au contrôle | **DT-047** : évalué par `PermitRightsEvaluator` (commune non ACTIVE : `COMMUNE_INACTIVE`) |
| L5-6 | Risque de confusion entre la référence `LP-` et un secret d'accès | **DM-105 / DM-112** : `LP-` est une référence d'affichage, jamais un secret ni un moyen de recherche |
| L5-7 | Texte de confidentialité du dépôt (LOT 03) : ne mentionne pas l'exposition publique du nom et des plaques par le scan | **Non traité au 05.1** : à mettre à jour avant la production (DM-115 §7, DM-117) |
| L5-8 | DM-114 §2 : « révocation globale » des liens | **DM-118** : révocation **individuelle**, explicite et auditée ; plusieurs liens actifs coexistent |
| L5-9 | DM-114 §2 : fin effective = clôture « si elle précède la fin prévue » (silencieux sur une clôture postérieure) | **Résolu le 9 novembre 2026** (DM-118 §4, `AccessExpiry`) : fin effective = le **plus tôt** de la fin planifiée et de la clôture ; clôture tardive sans effet ; fin planifiée repoussée prise en compte ; conforme à `GrantWindowCalculator` |
| L5-10 | Le QR du 05.2 encode `/v/{jeton}` alors que la page de contrôle est au 05.4 | Route absente d'ici là (404) ; QR conforme DM-113/DM-115 ; aucun QR n'est à distribuer en production avant le 05.4 |
| L5-11 | Masquage des jetons dans les journaux du serveur web | **Toujours non appliqué** (proposition nginx, `lot-05-schema.md` § 10) : le journal applicatif est purgé (DT-051), pas ceux de nginx/Traefik |
| L5-12 | `framework.yaml` : `trusted_proxies: private_ranges` (dev) | **Résolu le 9 novembre 2026** (LOT 05.2 bis) : Symfony 8.1.11 (avis corrigés) et proxy de confiance **Traefik seul** via `SYMFONY_TRUSTED_PROXIES` ; XFF falsifié par un conteneur voisin désormais ignoré ; production inchangée (aucun proxy de confiance) |
| L5-13 | DM-116 §3 et la matrice de `lot-05-schema.md` §6 ne prévoient ni raccourcissement ni correction de libellé ; DM-117 §1 ne liste ni raccourcissement ni remplacement du QR | **DM-119** (Proposée) : D1 et D9 **validés** (complètent DM-116) ; notifications `PERMIT_SHORTENED` et `QR_REPLACED` validées dans leur principe ; matrice des permissions et DM-117 à mettre à jour à la livraison (05.5.2 et 05.5.5) |
| L5-14 | D7 (audit visible dans chaque commune) face à DM-101 §3 (droits et historiques des autres communes masqués) | **Résolu le 10 novembre 2026** (DM-119 §7) : ligne d'audit neutre (type, date, commune concernée) pour les autres communes, ni motif ni donnée personnelle (validé) |
| L5-15 | D2 (reprise sans rétablissement rétroactif) face à la fenêtre unique copiée ; DT-049 §21 (`ALREADY_INCLUDED` par identifiant d'autorisation) qui supprimerait à tort un message de révision | **Résolu dans le code, migration à valider** (DM-119 §4.2, §11) : option A (écraser `valid_from`) **écartée** ; **B2** (périodes successives, table des périodes closes) **validée** et implémentée au 05.5.2 (migration `Version20261110155643` non appliquée) ; règle `ALREADY_INCLUDED` **restreinte à `AUTHORIZATION_ADDED`** au 05.5.1 (code livré, colonnes d'outbox dans la migration en attente) |
| L5-16 | Le PDF bénéficiaire est une photographie qui écrit des états de zone « valides » : un PDF déjà téléchargé reste lisible comme valide après une révocation | **Résolu au LOT 05.5.1** (DM-119 §6, validé) : PDF sans mention figée de validité, bandeau « seul le contrôle du QR code fait foi », refus de génération (409) sans droit actif ni à venir, lien bénéficiaire « Droits révoqués » ; limite assumée : un PDF déjà téléchargé ne se rappelle pas, son QR renvoie à l'état courant |
| L5-17 | Règle 8 du projet (pas de suppression physique d'une décision, d'une autorisation ou de son historique) face à la réintégration de zone ou de plaque (`removed_at` effacé) et à l'anonymisation prévue par DM-117 §3 | **Ouvert** (DM-119 §9) : anonymisation **sur place** (aucune suppression), révisions sans mutateur hors service d'anonymisation, jeton **neutralisé** plutôt que supprimé ; durées de conservation = **hypothèses** ; aucune suppression physique nouvelle sans validation ; interprétation de la règle 8 à confirmer |
| L5-18 | DM-113 §4 ne fixe aucune fréquence de remplacement du QR ; une limite horaire bloquante a été envisagée puis **abandonnée** par le commanditaire | **Ouvert** (DM-119 §4.5) : dispositif non bloquant (friction, alerte d'exploitation, anti-automatisation) proposé, non validé |
| L5-19 | DM-119 §8 (catalogue de motifs des plaques : `VEHICLE_CHANGED`, `ENTRY_ERROR`, `VEHICLE_ADDED`, `VEHICLE_REMOVED`, `BENEFICIARY_REQUEST`, `OTHER`, un seul jeu) et §10 (type de révision `VEHICLES_CHANGED`) face à l'arbitrage du 10 novembre 2026 (motifs par opération, remplacement reconstituable par un type de révision) | **Résolu le 10 novembre 2026** (LOT 05.5.4, DM-119 §4.4, §8, §10) : un catalogue par opération (ajout, retrait, remplacement, réactivation) ; `VEHICLE_ADDED` / `VEHICLE_REMOVED` ne sont plus des motifs (ils répètent le type de révision) ; types de révision `VEHICLE_ADDED`, `VEHICLE_REMOVED`, `VEHICLE_REINSTATED`, `VEHICLE_REPLACED_OUT`, `VEHICLE_REPLACED_IN` ; notification `VEHICLES_REMOVED` ajoutée |
| L5-20 | DM-119 §9 / DM-117 §3 : durée de conservation des plaques (3 mois) sans mécanisme livré, alors que le LOT 05.5.4 écrit des plaques dans `permit_revision_vehicle` | **Ouvert, BLOQUANT avant la production** : mécanisme de conservation et d'anonymisation du **LOT 05.6** (plaques de `permit_revision_vehicle` et lignes retirées de `permit_vehicle`) |
| L5-21 | DM-115 (exposition du nom et des plaques au scan) face à la clôture de l'événement : la page publique d'un jeton **actif** n'examinait pas l'état de l'événement | **Résolu le 10 novembre 2026** (décision définitive du commanditaire, DM-115 amendé) : événement clos = statut neutre « Événement terminé » seulement, règle côté serveur dans le lecteur métier, tests d'intégration et fonctionnels ; conservation administrative : **LOT 05.6** |
| L5-22 | DM-119 §4.5 (second remplacement dans l'heure : motif « répété ») face à l'arbitrage du 10 novembre 2026 (motif réel obligatoire + référence ressaisie + indicateur serveur) | **Résolu le 10 novembre 2026** (LOT 05.5.5, Q4) : le catalogue reste `LOST`, `SHARED_OR_PUBLISHED`, `SUSPECTED_MISUSE`, `DEVICE_COMPROMISED`, `OTHER` ; « rapproché » est calculé par le serveur et enregistré dans la révision |
| L5-23 | Texte de la page du bénéficiaire et du PDF : « Ce QR code ne change pas » | **Résolu le 10 novembre 2026** (LOT 05.5.5) : le QR n'est remplacé qu'exceptionnellement ; la page et le PDF le disent |
| L5-24 | Détecteur de plaques dans les commentaires (LOT 05.5.4) : protection maximale face au confort de saisie (12 phrases à quantités sur 12 refusées à tort) | **Résolu le 11 novembre 2026** (décision D1, [lot-05-5-6-recette.md](../lot-05-5-6-recette.md) §6) : détecteur ciblé (`PlateInCommentDetector`) qui n'exempte que les mots courants en minuscules entre espaces simples ; faux négatif (tiret insécable, point, souligné, barre oblique) corrigé ; résidus documentés |
| L5-25 | Ordre chronologique total de deux révisions écrites dans la même seconde par deux processus (DM-119 §10 : « révisions immuables et ordonnées ») | **Accepté pour la V1 le 11 novembre 2026** (décision D2) : ordre `occurred_at` puis identifiant UUID v7, exact dans un processus, non garanti entre deux ; jamais utilisé pour le calcul des droits ; lots et liens de remplacement conservés ; l'écran le dit ; aucune migration de séquence (à reprendre si l'audit l'exige) |
| L5-26 | DM-119 §9 : « purge existante » du lien bénéficiaire ; DM-119 §9 et `lot-05-schema.md` §11 : textes libres, justificatifs et clés d'idempotence absents de la matrice de conservation | **Signalé le 11 novembre 2026** ([lot-05-6-cadrage.md](../lot-05-6-cadrage.md) §2) : aucune purge ne touche `permit_access_token` ; **résolu par la nouvelle orientation** (DM-120 : le lien est fermé à la clôture et supprimé à la purge ; textes libres, justificatifs et clés d'idempotence couverts par la matrice du cadrage §4) ; notes de remplacement ajoutées à DM-119 §9 et `lot-05-schema.md` §11 |
| L5-27 | DM-114 (lien bénéficiaire valable 30 jours après la fin effective de l'événement, identité, plaques et QR affichés) face à l'orientation du 11 novembre 2026 (aucune information personnelle accessible après la clôture) | **Résolu le 11 novembre 2026** (décision du commanditaire, DM-114 amendé, DM-120) : le lien bénéficiaire est **fermé immédiatement à la clôture** ; implémentation au sous-lot 05.6.2 |
| L5-28 | CLAUDE.md règle 8 (« pas de suppression physique d'une décision, d'une autorisation ou de son historique ») face à la suppression contrôlée des événements et de leurs données après la conservation justifiée (orientation du 11 novembre 2026) | **Résolu le 11 novembre 2026 (D1)** : règle 8 reformulée (« Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. »), reportée dans CLAUDE.md et DM-120. Ancien statut : **Ouvert, bloquait le sous-lot 05.6.4** ([lot-05-6-cadrage.md](../lot-05-6-cadrage.md) §2 C9, §12) : reformulation proposée (« pas de suppression avant la fin de la conservation justifiée, puis suppression contrôlée et tracée ») ; les clés `RESTRICT` sont conservées (décision 8) ; **CLAUDE.md n'est pas modifié sans validation** |
| L5-29 | DM-119 §9 et version 1 du cadrage 05.6 (anonymisation progressive des champs à `T`+30 j, `T`+3 mois, `T`+6 mois) | **Abandonné le 11 novembre 2026** : remplacé par le cycle de vie clôture, archive intermédiaire, purge, suppression ([DM-120](../decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md)) ; note de remplacement ajoutée à DM-119 §9 |
| L5-30 | DM-115 : un ancien QR remplacé répond 410 sans limite de durée (05.5.5, Q9) face à la décision O1 du 11 novembre 2026 (aucune conservation résiduelle) | **Évolution acceptée le 11 novembre 2026** (DM-115 amendé, DM-120) : 410 conservé **avant** la purge du dossier, **404 neutre après** ; tests au sous-lot 05.6.4 ([lot-05-6-cadrage.md](../lot-05-6-cadrage.md) §7) |
| L5-31 | Rattachement de l'habilitation de l'organisateur à l'événement : le rôle `EVENT_MANAGER` est communal (aucune désignation par événement) face à l'énoncé « organisateur habilité pour cet événement et cette commune » (accès aux archives) | **Résolu le 11 novembre 2026 (D2)** : option B, désignation explicite de l'organisateur par événement et par commune. Ancien statut : **Ouvert, bloquait le sous-lot 05.6.3** ([lot-05-6-cadrage.md](../lot-05-6-cadrage.md) §6.2, R9) : option A (rattachement communal existant, recommandée) ou option B (désignation par événement, migration) |
| L5-32 | `permit_permit.first_decision_id` (NOT NULL, `RESTRICT`) : donnée **partagée** dépendant d'une décision **propre à une commune**, face à la purge par dossier communal (D3) | **Ouvert, à trancher à l'ouverture du sous-lot 05.6.4** ([lot-05-6-cadrage.md](../lot-05-6-cadrage.md) C14) : option 1 (supprimer la décision avec les données partagées, sans migration, recommandée) ou option 2 (clé nullable, migration M5) | Aucune modification du modèle avant arbitrage |
| L5-33 | Purge de l'outbox à **90 jours** (`app:notifications:purge-outbox`, durée provisoire) face à la conservation des intentions envoyées **pendant l'archive** (preuve de notification) | **Ouvert, à arbitrer au sous-lot 05.6.4** ([rgpd-politique-conservation.md](../rgpd-politique-conservation.md) §9) | Aucune modification avant arbitrage |
| L5-34 | `security.md` : lien bénéficiaire valable jusqu'à 30 jours **après la fin** de l'événement face à la décision (DM-114 amendé) : fermé **à la clôture** | **Ouvert, mise en cohérence de `security.md` au sous-lot 05.6.2** | Décision métier déjà validée |
| L5-35 | Aucune politique de durée pour les **journaux web et techniques** et les **données d'administration et de facturation** du SaaS (hors cycle événementiel) | **Ouvert, à définir avec le DPO** (hors LOT 05.6) | La politique événementielle ne s'y applique pas |

