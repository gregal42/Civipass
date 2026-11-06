# Registre des décisions Civipass

Ce répertoire centralise les décisions. **Les décisions priment sur les spécifications** (voir la hiérarchie dans [`../README.md`](../README.md)).

## Règles

- `DM-XXX` : décision métier ; `DT-XXX` : décision technique. Une fiche par décision, jamais renumérotée.
- Statuts : **Proposée** (recommandation non validée), **Validée**, **Remplacée**, **Abandonnée**.
- Une fiche indique l'identifiant, le titre, la validation (date), le contexte, la décision, les conséquences, les documents concernés et les décisions remplacées.
- Les dates de validation ne sont pas connues pour les décisions antérieures à cette mission : elles sont marquées « date de validation non renseignée » et ne sont jamais inventées.
- Une recommandation d'agent n'est **jamais** une décision validée. Arbitrages **validés** : A1 à A14, R1 à R9, P1 à P6, DT-020, DT-022, DT-023, DT-030, T1 (DM-076), DRAFT → CLOSED (DM-077) et administration des accès commerciaux depuis Sonata (DM-078), `revoked_by` (DM-079), type de zone immuable (DM-080), historique de suspension (DM-081), T2 (DM-082), limite de 3 660 jours (DM-083) : DM-051 à DM-066, DM-068 à DM-105 (DM-097 à DM-105 : LOT 03, 5 novembre 2026) ; DT-024, DT-035 à DT-039. `DM-067` n'est pas attribué. Points résiduels de détail (T2 à T6) : [`../revisions/CONTRADICTIONS.md`](../revisions/CONTRADICTIONS.md). Les fiches techniques `Proposée` (DT-021, DT-025 à DT-029, DT-031) sont des propositions en attente de validation.
- Une contradiction entre un document et une décision validée est signalée dans la fiche (« Écarts documentaires signalés »), sans modifier silencieusement le document.
- Les décisions D1–D20 de [`../lot-01-schema.md`](../lot-01-schema.md) et D1–D8 de [`../ux/UX-LOT-UI-02-architecture.md`](../ux/UX-LOT-UI-02-architecture.md) gardent leur numérotation d'origine dans leurs documents (collision connue, écart N4) ; ce registre les reprend sous des identifiants DM/DT.

## Décisions métier

| Id | Titre | Thème | Statut |
|---|---|---|---|
| [DM-001](metier/DM-001-une-seule-commune-organisatrice-par-evenement.md) | Une seule commune organisatrice par événement | Événements intercommunaux | Validée |
| [DM-002](metier/DM-002-autonomie-de-chaque-commune-participante.md) | Autonomie de chaque commune participante | Événements intercommunaux | Validée |
| [DM-003](metier/DM-003-la-commune-organisatrice-possede-egalement-une-participation.md) | La commune organisatrice possède également une participation | Événements intercommunaux | Validée |
| [DM-004](metier/DM-004-une-participation-par-commune-et-par-evenement.md) | Une participation par commune et par événement | Événements intercommunaux | Validée |
| [DM-005](metier/DM-005-les-droits-cws-ne-donnent-pas-acces-aux-operations-communale.md) | Les droits CWS ne donnent pas accès aux opérations communales | Événements intercommunaux | Validée |
| [DM-006](metier/DM-006-une-zone-appartient-a-une-participation-communale.md) | Une zone appartient à une participation communale | Zones | Validée |
| [DM-007](metier/DM-007-chaque-commune-administre-uniquement-ses-propres-zones.md) | Chaque commune administre uniquement ses propres zones | Zones | Validée |
| [DM-008](metier/DM-008-aucune-bibliotheque-communale-de-zones-en-v1.md) | Aucune bibliothèque communale de zones en V1 | Zones | Validée |
| [DM-009](metier/DM-009-la-gestion-des-zones-releve-de-l-administrateur-communal.md) | La gestion des zones relève de l'administrateur communal | Zones | Validée |
| [DM-010](metier/DM-010-commune-entiere-confirmation-explicite-requise.md) | « Commune entière » : confirmation explicite requise | Zones | Validée |
| [DM-011](metier/DM-011-configuration-explicite-de-la-validite-d-une-zone.md) | Configuration explicite de la validité d'une zone | Validité temporelle | Validée |
| [DM-012](metier/DM-012-mode-toute-la-duree-de-l-evenement.md) | Mode « Toute la durée de l'événement » | Validité temporelle | Validée |
| [DM-013](metier/DM-013-mode-periodes-specifiques.md) | Mode « Périodes spécifiques » | Validité temporelle | Validée |
| [DM-014](metier/DM-014-trois-types-de-creneaux-continu-ponctuel-recurrent.md) | Trois types de créneaux : continu, ponctuel, récurrent | Validité temporelle | Validée |
| [DM-015](metier/DM-015-creneau-ponctuel-limite-a-une-journee.md) | Créneau ponctuel limité à une journée | Validité temporelle | Validée |
| [DM-016](metier/DM-016-recurrences-jours-selectionnes-et-periode-de-validite.md) | Récurrences : jours sélectionnés et période de validité | Validité temporelle | Validée |
| [DM-017](metier/DM-017-creneaux-nocturnes-autorises-heures-egales-interdites.md) | Créneaux nocturnes autorisés, heures égales interdites | Validité temporelle | Validée |
| [DM-018](metier/DM-018-chevauchements-autorises-validite-par-union.md) | Chevauchements autorisés, validité par union | Validité temporelle | Validée |
| [DM-019](metier/DM-019-zone-sans-validite-configuree-aucun-droit-effectif.md) | Zone sans validité configurée : aucun droit effectif | Validité temporelle | Validée |
| [DM-020](metier/DM-020-horaires-en-europe-paris-instants-en-utc.md) | Horaires en Europe/Paris, instants en UTC | Validité temporelle | Validée |
| [DM-021](metier/DM-021-transitions-de-changement-d-heure-traitees-explicitement.md) | Transitions de changement d'heure traitées explicitement | Validité temporelle | Validée |
| [DM-022](metier/DM-022-configuration-de-plusieurs-zones-simultanement.md) | Configuration de plusieurs zones simultanément | Opérations groupées | Validée |
| [DM-023](metier/DM-023-application-a-toutes-les-zones-d-une-participation-communale.md) | Application à toutes les zones d'une participation communale | Opérations groupées | Validée |
| [DM-024](metier/DM-024-activation-et-suspension-groupees.md) | Activation et suspension groupées | Opérations groupées | Validée |
| [DM-025](metier/DM-025-confirmation-avant-ecrasement-des-configurations-existantes.md) | Confirmation avant écrasement des configurations existantes | Opérations groupées | Validée |
| [DM-026](metier/DM-026-configurations-independantes-apres-application.md) | Configurations indépendantes après application | Opérations groupées | Validée |
| [DM-027](metier/DM-027-aucun-heritage-permanent-entre-zones.md) | Aucun héritage permanent entre zones | Opérations groupées | Validée |
| [DM-028](metier/DM-028-operations-groupees-limitees-a-sa-propre-commune.md) | Opérations groupées limitées à sa propre commune | Opérations groupées | Validée |
| [DM-029](metier/DM-029-aucune-cartographie-en-v1.md) | Aucune cartographie en V1 | Périmètre V1 | Validée |
| [DM-030](metier/DM-030-aucune-fonctionnalite-de-bibliotheque-de-zones-en-v1.md) | Aucune fonctionnalité de bibliothèque de zones en V1 | Périmètre V1 | Validée |
| [DM-031](metier/DM-031-supervision-cws-limitee-aux-trois-permissions-du-lot-01.md) | Supervision CWS limitée aux trois permissions du LOT 01 | Droits CWS | Validée |
| [DM-032](metier/DM-032-statuts-administratifs-d-une-commune-active-suspended-inacti.md) | Statuts administratifs d'une commune : ACTIVE, SUSPENDED, INACTIVE | Communes | Validée |
| [DM-033](metier/DM-033-creation-d-evenement-statut-active-et-abonnement-active.md) | Création d'événement : statut ACTIVE et abonnement ACTIVE | Communes | Validée |
| [DM-034](metier/DM-034-commune-suspended-ni-creation-ni-nouvelle-participation-capa.md) | Commune SUSPENDED : ni création ni nouvelle participation, capacités opérationnelles suspendues | Communes | Validée |
| [DM-035](metier/DM-035-autorisations-d-une-commune-suspended-ou-inactive-inutilisab.md) | Autorisations d'une commune SUSPENDED ou INACTIVE inutilisables au contrôle | Communes | Validée |
| [DM-036](metier/DM-036-protection-du-dernier-administrateur-et-du-dernier-superadmi.md) | Protection du dernier administrateur et du dernier superadmin | Comptes | Validée |
| [DM-037](metier/DM-037-decisions-ux-de-ui-02-statuts-repris-du-sfd-ui-002-remplace-.md) | Décisions UX de UI-02 : statuts repris du SFD, UI-002 remplacé, champs de zone, validité dans le périmètre, pas de refus d'invitation | UX | Validée |
| [DM-051](metier/DM-051-a1-representation-de-la-zone-confirmee.md) | A1 — Représentation de la zone « confirmée » | Arbitrage TECH-02.1 | Validée |
| [DM-052](metier/DM-052-a2-suppression-logique-de-toutes-les-zones.md) | A2 — Suppression logique de toutes les zones | Arbitrage TECH-02.1 | Validée |
| [DM-053](metier/DM-053-a3-a4-types-d-evenements-et-participation-de-l-organisatrice.md) | A3 / A4 — Types d'événements et participation de l'organisatrice | Arbitrage TECH-02.1 | Validée |
| [DM-054](metier/DM-054-a5-1-fuseau-horaire-de-l-evenement.md) | A5.1 — Fuseau horaire de l'événement | Arbitrage TECH-02.1 | Remplacée |
| [DM-055](metier/DM-055-a5-2-a5-3-heure-inexistante-et-heure-repetee.md) | A5.2 / A5.3 — Heure inexistante et heure répétée | Arbitrage TECH-02.1 | Validée |
| [DM-056](metier/DM-056-a6-cycle-de-vie-des-participations.md) | A6 — Cycle de vie des participations | Arbitrage TECH-02.1 | Validée |
| [DM-057](metier/DM-057-a7-roles-et-permissions-evenements-participations-zones.md) | A7 — Rôles et permissions (événements, participations, zones) | Arbitrage TECH-02.1 | Validée |
| [DM-058](metier/DM-058-a8-commune-entiere-proposee-automatiquement.md) | A8 — « Commune entière » proposée automatiquement | Arbitrage TECH-02.1 | Validée |
| [DM-059](metier/DM-059-a9-conditions-d-activation-d-un-evenement.md) | A9 — Conditions d'activation d'un événement | Arbitrage TECH-02.1 | Validée |
| [DM-060](metier/DM-060-simplicite-operationnelle-configuration-simple-par-defaut-av.md) | Simplicité opérationnelle : configuration simple par défaut, avancée à la demande | UX | Validée |
| [DM-061](metier/DM-061-impersonation-cws-depuis-sonata-exigences-anticipees.md) | Impersonation CWS depuis Sonata : exigences anticipées | Droits CWS | Validée |
| [DM-062](metier/DM-062-r9-communes-d-outre-mer-prises-en-charge-des-la-v1-fuseau-ia.md) | R9 — Communes d'outre-mer prises en charge dès la V1 ; fuseau IANA de l'événement | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-063](metier/DM-063-r3-recurrence-contenant-une-heure-locale-inexistante-refus.md) | R3 — Récurrence contenant une heure locale inexistante : refus | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-064](metier/DM-064-r4-recurrence-contenant-une-heure-locale-ambigue-choix-expli.md) | R4 — Récurrence contenant une heure locale ambiguë : choix explicite | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-065](metier/DM-065-r5-cloture-forcee-exceptionnelle-par-cws-depuis-sonata.md) | R5 — Clôture forcée exceptionnelle par CWS depuis Sonata | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-066](metier/DM-066-r6-participation-suspended-zones-modifiables-mais-non-operat.md) | R6 — Participation SUSPENDED : zones modifiables mais non opérationnelles | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-068](metier/DM-068-r8-la-zone-commune-entiere-automatique-est-supprimable.md) | R8 — La zone « Commune entière » automatique est supprimable | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-069](metier/DM-069-a10-creneau-continu-date-et-heure-de-debut-et-de-fin.md) | A10 — Créneau continu : date et heure de début et de fin | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-070](metier/DM-070-a11-a13-abonnement-actif-et-administration-active-pour-creer.md) | A11 / A13 — Abonnement actif et administration active pour créer un événement | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-071](metier/DM-071-a12-evenement-actif-modifiable-champs-structurants-verrouill.md) | A12 — Événement actif modifiable ; champs structurants verrouillés | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-072](metier/DM-072-a14-reference-publique-d-evenement-evt-yyyy-nnnnnn.md) | A14 — Référence publique d'événement `EVT-YYYY-NNNNNN` | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-073](metier/DM-073-p1-continuite-operationnelle-abonnement-expire-suspension-ad.md) | P1 — Continuité opérationnelle : abonnement expiré ≠ suspension administrative | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-074](metier/DM-074-p4-habilitations-cws-superadmin-conserve-deux-permissions-de.md) | P4 — Habilitations CWS : `superAdmin` conservé, deux permissions dédiées | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-075](metier/DM-075-p6-creneaux-supprimes-logiquement-reconfiguration-avec-histo.md) | P6 — Créneaux supprimés logiquement ; reconfiguration avec historique | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-076](metier/DM-076-t1-droits-d-utilisation-commerciaux-abonnement-trial-ou-acce.md) | T1 — Droits d'utilisation commerciaux : abonnement, TRIAL ou accès commercial CWS | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-077](metier/DM-077-transition-draft-closed-autorisee.md) | Transition DRAFT → CLOSED autorisée | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-078](metier/DM-078-acces-commerciaux-cws-administres-depuis-le-back-office-sona.md) | Accès commerciaux CWS administrés depuis le back-office Sonata | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-079](metier/DM-079-tracabilite-des-revocations-commerciales-revoked-by-conserve.md) | Traçabilité des révocations commerciales : `revoked_by` conservé | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-080](metier/DM-080-immutabilite-du-type-de-zone.md) | Immutabilité du type de zone | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-081](metier/DM-081-historique-de-suspension-conserve-a-la-reactivation.md) | Historique de suspension conservé à la réactivation | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-082](metier/DM-082-date-de-fin-d-un-evenement-actif-jamais-dans-le-passe-t2-con.md) | Date de fin d'un événement actif : jamais dans le passé (T2 confirmée) | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-083](metier/DM-083-limite-technique-des-recurrences-3-660-jours.md) | Limite technique des récurrences : 3 660 jours | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-084](metier/DM-084-visibilite-intercommunale-zones-participations-consultation-.md) | Visibilité intercommunale : zones, participations, consultation par les instructeurs (U2, U3, U4) | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-085](metier/DM-085-d1-cloture-d-un-evenement-draft-toutes-les-participations-so.md) | D1 — Clôture d'un événement DRAFT : toutes les participations sont clôturées | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-086](metier/DM-086-d4-designation-des-communes-invitees-par-identifiant-commune.md) | D4 — Désignation des communes invitées par identifiant ; commune non abonnée invitable | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-087](metier/DM-087-d6-participation-organisatrice-audit-participation-created.md) | D6 — Participation organisatrice : audit `PARTICIPATION_CREATED` | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-088](metier/DM-088-d7-remplacement-de-creneaux-identique-aucune-ecriture-ni-aud.md) | D7 — Remplacement de créneaux identique : aucune écriture ni audit | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-089](metier/DM-089-d8-reinvitation-apres-cloture-interdite.md) | D8 — Réinvitation après clôture interdite | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-090](metier/DM-090-u5-visibilite-des-instructeurs-informations-operationnelles-.md) | U5 — Visibilité des instructeurs : informations opérationnelles seulement | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-091](metier/DM-091-u6-historique-intercommunal-apres-cloture.md) | U6 — Historique intercommunal après clôture | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-092](metier/DM-092-cloture-globale-automatique-toutes-les-participations-ouvert.md) | Clôture globale automatique : toutes les participations ouvertes sont clôturées dans la même transaction | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-093](metier/DM-093-confirmation-d-une-zone-sans-configuration-temporelle.md) | Confirmation d'une zone sans configuration temporelle | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-094](metier/DM-094-unconfigured-etat-initial-non-cible-de-configuration.md) | UNCONFIGURED : état initial, jamais cible de `configureValidity` | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-095](metier/DM-095-confirmation-avant-ecrasement-limitee-aux-operations-groupees.md) | Confirmation avant écrasement : opérations groupées ; l'individuel est une sauvegarde explicite | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-096](metier/DM-096-plafonds-de-securite-creneaux-et-zones-groupees.md) | Plafonds de sécurité : 500 créneaux par configuration, 100 zones par opération groupée | Arbitrage TECH-02.1 (complément) | Validée |
| [DM-097](metier/DM-097-motifs-de-demande-referentiel-administrable-libelles-neutres.md) | Motifs de demande : référentiel administrable, libellés neutres (A1) | LOT 03 — Demandes | Validée |
| [DM-098](metier/DM-098-categories-de-beneficiaires-referentiel-distinct-du-motif.md) | Catégories de bénéficiaires : référentiel distinct du motif (A2) | LOT 03 — Demandes | Validée |
| [DM-099](metier/DM-099-depot-pour-soi-meme-et-saisie-par-un-agent-habilite.md) | Dépôt public pour soi-même ; saisie pour un tiers par un agent habilité (A3, A5) | LOT 03 — Demandes | Validée |
| [DM-100](metier/DM-100-identite-et-contact-adresse-postale-facultative.md) | Identité et contact : adresse postale toujours facultative (A4) | LOT 03 — Demandes | Validée |
| [DM-101](metier/DM-101-dossier-intercommunal-unique-et-visibilite-entre-communes.md) | Dossier intercommunal unique et visibilité des demandes entre communes (A6) | LOT 03 — Demandes | Validée |
| [DM-102](metier/DM-102-une-seule-liste-des-demandes.md) | Une seule liste des demandes (A7) | UX — LOT 03 | Validée |
| [DM-103](metier/DM-103-mode-de-deplacement-exclusif-et-plaques-siv-fni.md) | Mode de déplacement exclusif ; plaques françaises SIV/FNI | LOT 03 — Demandes | Validée |
| [DM-104](metier/DM-104-zones-proposables-au-depot.md) | Zones proposables au dépôt : « ouverte aux demandes » définie | LOT 03 — Demandes | Validée |
| [DM-105](metier/DM-105-references-dem-pour-les-demandes-lp-pour-les-laissez-passer.md) | Références : `DEM-AAAA-NNNN` pour les demandes, `LP-` pour les laissez-passer délivrés | LOT 03 — Demandes | Validée |

## Décisions techniques

| Id | Titre | Thème | Statut |
|---|---|---|---|
| [DT-001](technique/DT-001-identifiants-uuid-v7-generes-cote-application-stockes-en-bin.md) | Identifiants UUID v7 générés côté application, stockés en BINARY(16) | Données | Validée |
| [DT-002](technique/DT-002-roles-communaux-en-json-valides-cote-domaine-separes-des-rol.md) | Rôles communaux en JSON validés côté domaine, séparés des rôles Symfony | Données | Validée |
| [DT-003](technique/DT-003-collation-binaire-pour-les-identifiants-normalises.md) | Collation binaire pour les identifiants normalisés | Données | Validée |
| [DT-004](technique/DT-004-dates-en-utc-avec-type-doctrine-surcharge.md) | Dates en UTC avec type Doctrine surchargé | Données | Validée |
| [DT-005](technique/DT-005-pas-de-verrouillage-optimiste-sur-les-memberships-en-v1.md) | Pas de verrouillage optimiste sur les memberships en V1 | Données | Validée |
| [DT-006](technique/DT-006-audit-minimal-table-unique-audit-log-ecrite-dans-la-transact.md) | Audit minimal : table unique `audit_log` écrite dans la transaction | Audit | Validée |
| [DT-007](technique/DT-007-transactionrunner-un-refus-metier-ne-ferme-pas-l-entitymanag.md) | TransactionRunner : un refus métier ne ferme pas l'EntityManager | Transactions | Validée |
| [DT-008](technique/DT-008-telephones-normalises-en-e-164.md) | Téléphones normalisés en E.164 | Données | Validée |
| [DT-009](technique/DT-009-base-de-test-dediee-et-provisionnement-reproductible.md) | Base de test dédiée et provisionnement reproductible | Tests | Validée |
| [DT-010](technique/DT-010-authentification-formulaire-csrf-de-session-limitation-des-t.md) | Authentification : formulaire, CSRF de session, limitation des tentatives | Authentification | Validée |
| [DT-011](technique/DT-011-mot-de-passe-12-caracteres-minimum-sans-regle-de-complexite.md) | Mot de passe : 12 caractères minimum sans règle de complexité | Authentification | Validée |
| [DT-012](technique/DT-012-se-souvenir-de-moi-cookie-signe-30-jours-authentification-co.md) | « Se souvenir de moi » : cookie signé, 30 jours, authentification complète pour /admin | Authentification | Validée |
| [DT-013](technique/DT-013-reinitialisation-du-mot-de-passe-jeton-hache-usage-unique-at.md) | Réinitialisation du mot de passe : jeton haché, usage unique atomique | Authentification | Validée |
| [DT-014](technique/DT-014-adresse-ip-du-client-aucun-proxy-de-confiance-par-defaut-com.md) | Adresse IP du client : aucun proxy de confiance par défaut, compteurs sur un seul serveur | Infrastructure | Validée |
| [DT-015](technique/DT-015-design-system-copie-versionnee-avec-provenance-typescript-tr.md) | Design system : copie versionnée avec provenance, TypeScript transpilé hors projet | Interface | Validée |
| [DT-016](technique/DT-016-navigateurs-vises-chrome-edge-firefox-et-safari-modernes-202.md) | Navigateurs visés : Chrome, Edge, Firefox et Safari modernes (2023 et après) | Interface | Validée |
| [DT-017](technique/DT-017-previsualisation-du-design-system-en-environnement-dev-uniqu.md) | Prévisualisation du design system en environnement dev uniquement | Interface | Validée |
| [DT-018](technique/DT-018-back-office-sonata-en-lecture-seule-gestionnaire-de-securite.md) | Back-office Sonata en lecture seule, gestionnaire de sécurité limité à LIST/VIEW | Back-office | Validée |
| [DT-019](technique/DT-019-symfony-8-1-conserve-jusqu-a-la-planification-de-sa-sortie.md) | Symfony 8.1 conservé jusqu'à la planification de sa sortie | Plateforme | Validée |
| [DT-020](technique/DT-020-choix-des-heures-ambigues-dstchoices-en-json.md) | Choix des heures ambiguës : `dstChoices` en JSON | Données | Validée |
| [DT-021](technique/DT-021-resolution-des-changements-d-heure-par-un-service-de-domaine.md) | Résolution des changements d'heure par un service de domaine explicite | Données | Proposée |
| [DT-022](technique/DT-022-journal-d-audit-actor-id-operator-id-batch-id.md) | Journal d'audit : `actor_id`, `operator_id`, `batch_id` | Audit | Validée |
| [DT-023](technique/DT-023-initialisation-reproductible-et-idempotente-des-eventtype.md) | Initialisation reproductible et idempotente des `EventType` | Données | Validée |
| [DT-024](technique/DT-024-verrouillage-et-ordre-des-verrous.md) | Verrouillage et ordre des verrous | Transactions | Validée |
| [DT-025](technique/DT-025-generation-de-la-reference-publique-d-evenement.md) | Génération de la référence publique d'événement | Données | Proposée |
| [DT-026](technique/DT-026-liste-blanche-de-fuseaux-iana-eventtimezone.md) | Liste blanche de fuseaux IANA (`EventTimezone`) | Données | Proposée |
| [DT-027](technique/DT-027-port-municipalityentitlementprovider.md) | Port `MunicipalityEntitlementProvider` | Plateforme | Proposée |
| [DT-028](technique/DT-028-service-de-cloture-de-l-evenement-mode-force-reporte.md) | Service de clôture de l'événement : mode forcé reporté | Transactions | Proposée |
| [DT-029](technique/DT-029-mecanismes-de-securite-prepares-pour-les-permissions-cws.md) | Mécanismes de sécurité préparés pour les permissions CWS | Sécurité | Proposée |
| [DT-030](technique/DT-030-acces-commerciaux-cws-table-municipality-commercial-access.md) | Accès commerciaux CWS : table `municipality_commercial_access` | Plateforme | Validée |
| [DT-031](technique/DT-031-transitions-enums-de-domaine-comme-source-unique-symfony-wor.md) | Transitions : enums de domaine comme source unique, Symfony Workflow non branché | Architecture | Proposée |
| [DT-032](technique/DT-032-audit-operator-id-et-batch-id-mappes-avec-la-migration-de-l-.md) | Audit : `operator_id` et `batch_id` mappés avec la migration de l'étape 3 | Audit | Remplacée |
| [DT-033](technique/DT-033-migration-du-module-event-et-validation-mysql.md) | Migration du module Event et validation MySQL | Données | Validée |
| [DT-034](technique/DT-034-politique-d-acces-du-module-event-matrice-unique-commune-ded.md) | Politique d'accès du module Event : matrice unique, commune déduite de la ressource | Sécurité | Proposée |
| [DT-035](technique/DT-035-architecture-transactionnelle-du-module-event-sous-etape-5-1.md) | Architecture transactionnelle du module Event (sous-étape 5.1) | Transactions | Validée |
| [DT-036](technique/DT-036-d3-isolation-repeatable-read-conservee-delai-de-verrou-de-5-.md) | D3 — Isolation REPEATABLE READ conservée, délai de verrou de 5 secondes | Transactions | Validée |
| [DT-037](technique/DT-037-d5-audit-metier-structure-sans-texte-libre-ni-donnee-personn.md) | D5 — Audit métier structuré, sans texte libre ni donnée personnelle | Audit | Validée |
| [DT-038](technique/DT-038-horodatage-d-audit-pris-sous-verrou.md) | Horodatage des écritures et des audits pris après l'acquisition des verrous | Audit | Validée |
| [DT-039](technique/DT-039-referentiels-de-demande-initialisation-et-gestion-par-commande.md) | Référentiels de demande : persistance en base, initialisation idempotente ; administration ultérieure par Sonata | Données | Validée |

## Modèle de fiche

```markdown
# DM-000 — Titre

- Identifiant / Thème / Statut / Validation / Décisions remplacées
## Contexte
## Décision
## Conséquences fonctionnelles ou techniques
## Documents concernés
## Écarts documentaires signalés
```
