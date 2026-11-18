# LOT 10 — Plan de recette fonctionnelle globale de Civipass V1

**Statut : PROPOSITION soumise à validation (18 novembre 2026). Aucune recette n'est lancée.** Aucune migration, aucun développement fonctionnel, aucun déploiement. Ce document fixe le périmètre, les critères d'acceptation et la matrice ; la recette ne démarre qu'après validation et arbitrages du §9.

Entrées : [lot-05-5-6-recette.md](lot-05-5-6-recette.md) (méthode et vocabulaire A/S/M repris), [lot-08-cadrage.md](lot-08-cadrage.md) Partie III, [lot-09-controle-hors-connexion-cadrage.md](lot-09-controle-hors-connexion-cadrage.md), [testing.md](testing.md), [ecarts-techniques-connus.md](ecarts-techniques-connus.md).

## 1. Objet et principes

Vérifier le fonctionnement **réel** de Civipass V1 depuis une **commune vierge** : parcours, droits, isolation, expérience de chaque profil. Les 3 525 tests PHPUnit existants sont une **preuve complémentaire** (colonne « Réf. »), jamais une validation de la recette : un scénario n'est « Réussi » que s'il a été **rejoué dans ce lot** sur les données de recette (règle déjà appliquée au LOT 05.5.6).

| Principe | Application |
|---|---|
| Commune vierge | Aucune fixture métier. Base de recette dédiée, initialisée uniquement par les commandes d'amorçage existantes (super-administrateur, référentiel national, types d'événements, référentiels de demandes) ; tout le reste est créé **par l'application** (UI ou commandes de production). |
| Données fictives | Noms, adresses, plaques, téléphones et e-mails synthétiques (domaine `.invalid`/MailCatcher). Aucune donnée personnelle réelle. |
| Local uniquement | Aucun VPS, déploiement ou push sans autorisation. `main` intact. |
| Interrupteurs | `REFUSAL_NOTICE_ENABLED`, `OFFLINE_CONTROL_ENABLED`, `PUBLIC_VERIFICATION_ENABLED` restent à **0 dans les configurations par défaut**. La recette les active **uniquement** par variables d'environnement du poste de recette (`.env.local`/environnement du processus, ignorés par Git), jamais dans `.env` ni `services.yaml`. |
| Statuts | **Réussi** (rejoué, conforme) · **Échoué → corrigé** (défaut reproduit, correctif et test de non-régression, soumis à validation si fonctionnel) · **Non exécuté** · **Bloqué** (environnement absent). |
| Modes | **A** automatisé (PHPUnit/E2E) · **S** semi-réel (vrai SMTP MailCatcher, vrai navigateur) · **M** manuel (humain ou appareil réel). |
| Anomalies | Consignées, classées (bloquante / majeure / mineure) ; tout correctif **fonctionnel** est soumis à validation avant d'être développé. |

## 2. Rôles et acteurs de la recette (comptes fictifs)

| Acteur | Rôle | Usage |
|---|---|---|
| `cws-root` | Super-administrateur CWS | Création de communes, support ; **sans droit communal implicite** |
| `cws-support` | Compte CWS avec habilitation `forced_closure` (console) | Clôture exceptionnelle |
| `A-admin`, `A-instr1`, `A-instr2`, `A-lect` | Commune A (organisatrice) : administrateur, 2 instructeurs, lecteur | Parcours principal |
| `B-admin`, `B-instr` | Commune B (invitée) | Autonomie et isolation |
| `C-admin` | Commune C (non invitée) | Tentatives d'accès croisé |
| Contrôleur en ligne / hors connexion | Sans compte (lien/appareil approuvé) | Vérification publique, contrôle |
| Citoyen 1…6, professionnel | Sans compte | Dépôts de demandes |

## 3. Plan de recette par domaine

Chaque domaine ci-dessous regroupe des scénarios détaillés dans la matrice (§5). Format de chaque scénario : **préconditions → actions → résultat attendu → critère d'acceptation** (le détail court est porté par la matrice ; les parcours longs sont au §4).

| # | Domaine | Préconditions communes | Critère d'acceptation du domaine |
|---|---|---|---|
| D1 | Administration CWS et création des communes | Base vierge + `cws-root` | Commune créée, premier administrateur invité et inscrit ; CWS n'a aucun droit communal implicite ; actions tracées |
| D2 | Administration communale / utilisateurs | Commune + admin | Invitations, rôles cumulables, désactivation, dernier administrateur protégé ; rôles appliqués côté serveur |
| D3 | Événements | Commune active | Cycle brouillon → ouvert → clôturé conforme, aucun état invalide atteignable |
| D4 | Collaboration inter-communes | Commune B référencée | Invitation, acceptation, refus, suspension ; chaque commune autonome ; aucune fuite de données entre communes |
| D5 | Zones | Événement | Création/modification/suspension/validité ; effets sur demandes et laissez-passer conformes |
| D6 | Demandes citoyennes et professionnelles | Événement ouvert aux demandes | Dépôt avec/sans véhicule, pièces jointes, idempotence, validations, référence unique |
| D7 | Instruction | Demandes déposées | Approbation/refus par commune uniquement sur ses zones ; concurrence maîtrisée ; verrou d'instruction (DM-137 option A) |
| D8 | Doublons | Demandes proches | Signal informatif, limité au périmètre de la commune ; aucune décision automatique |
| D9 | Laissez-passer | Demandes approuvées | Délivrance, accès bénéficiaire, droits, périodes, zones, plaques, QR, révocation, remplacement de jeton |
| D10 | Vérification publique / contrôle en ligne | Interrupteur activé sur le poste de recette | QR valide/inconnu/expiré/révoqué/événement clos : réponses conformes, sans fuite |
| D11 | Contrôle hors connexion (locale vs 09.7) | Interrupteur 0→1 poste de recette | Locale : appariement, jeu de données, synchronisation, intégrité, horloge. **09.7 réelle : distincte (§8)** |
| D12 | Notifications et refus | MailCatcher ; interrupteur refus activé en recette | Notifications d'autorisation reçues, refus notifiés/réessayés/marqués manuellement ; aucun envoi réel |
| D13 | Sessions et habilitations | Comptes de recette | Expiration (inactivité/absolue), avertissement, aucun « se souvenir de moi », permissions CWS persistées |
| D14 | Clôture normale et exceptionnelle | Événement avec participations | Clôture normale refusée tant que des participations sont ouvertes ; clôture exceptionnelle CWS tracée, notifiée |
| D15 | Historique, audit, conservation | Parcours exécutés | Journal complet et horodaté en UTC ; purge/archivage/droits des personnes conformes ; rien supprimé avant l'échéance |

## 4. Parcours de référence (bout en bout) — « Inondation »

Commune **A** organise un événement « inondation » ; commune **B** est invitée ; les demandes sont ouvertes au public.

| Étape | Acteur | Action | Résultat attendu / point de contrôle |
|---|---|---|---|
| P0 | `cws-root` | Initialise l'environnement vierge, crée les communes A, B, C, invite `A-admin`, `B-admin`, `C-admin` | Comptes inscrits, aucun événement, aucune donnée résiduelle |
| P1 | `A-admin` | Crée l'équipe (2 instructeurs, 1 lecteur) | Rôles effectifs ; le lecteur ne peut rien décider |
| P2 | `A-admin` | Crée l'événement « inondation », 3 zones (dont une à validité restreinte) | Brouillon cohérent, audit |
| P3 | `A-admin` | Invite B ; `B-admin` accepte, rattache ses zones | B voit **uniquement** ses zones et ses demandes ; A ne voit pas les données internes de B |
| P4 | `A-admin` | Ouvre l'événement aux demandes | Formulaire public accessible |
| P5 | Citoyens 1–6 | Déposent 6 demandes : à pied, en véhicule (plaques), 1 doublon, 1 avec pièces jointes, 1 professionnel | Références uniques, accusés, idempotence au double-clic |
| P6 | `A-instr1/2`, `B-instr` | Traitent leurs demandes respectives ; 1 refus avec motif ; 1 approbation concurrente (deux instructeurs) | Une seule décision gagne, l'autre est informée ; B n'agit pas sur A et inversement ; doublon signalé sans blocage |
| P7 | Système | Délivre les laissez-passer ; notifie les bénéficiaires ; notifie les refus (recette) | Mails reçus (MailCatcher), droits par commune distincts |
| P8 | Contrôleur | Vérifie en ligne (QR valide), puis QR inconnu | Réponses conformes ; aucune donnée personnelle superflue |
| P9 | `A-instr1` | Modifie : zone, plaque, période (prolongation, raccourcissement) ; remplace le jeton QR | Effet immédiat, ancien QR invalide, notifications émises |
| P10 | `B-instr` | Révoque l'autorisation de B | Droits de A inchangés ; contrôle : B révoqué, A valide |
| P11 | `A-admin` | Tente de clôturer alors que B est encore participante | Refus explicite (participations ouvertes) |
| P12 | `B-admin` puis `A-admin` | Clôture de B, puis de A | Événement clos ; modifications bloquées, vérification conforme à la règle « événement clos » ; statistiques de clôture |
| P13 | `cws-support` | Cas séparé : clôture exceptionnelle d'un second événement avec participation bloquée | Clôture forcée, audit `support`, mail aux administrateurs communaux sans motif |
| P14 | Contrôle | Relecture du journal, de la conservation, des exports de droits | Chaîne d'audit complète, aucun accès inter-communes |

**Points d'attention constants** : autonomie de la commune invitée (B décide, révoque, clôture seule) et isolation (aucun identifiant forgé de B ne donne accès à A, et réciproquement).

## 5. Matrice des scénarios

Colonnes : **ID** · fonctionnalité · scénario · résultat attendu · **P**riorité (P1 critique, P2 majeur, P3 mineur) · **Mode** (A/S/M) · **Statut** · **Réf.** (tests existants qui couvrent déjà tout ou partie ; à rejouer, non suffisants seuls). Statut initial de toutes les lignes : **Non exécuté**.

### D1 — Administration CWS

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-CWS-01 | Super-admin | Création du super-admin par console | Compte actif, aucune commune implicite | P1 | A+M | `CreateSuperAdminCommandTest`, `AdminAccessTest` |
| R-CWS-02 | Communes | Création d'une commune depuis le référentiel national | Commune créée, tracée, sans équipe | P1 | M | `MunicipalityManagerTest`, `CwsAdministrationTest`, `NationalCommuneSynchronizerTest` |
| R-CWS-03 | Premier administrateur | Invitation, lien, activation du compte | Compte inscrit une seule fois ; lien à usage unique | P1 | M+S | `MunicipalityAdminInvitationServiceTest`, `InvitationLinkPageTest`, `EnrollFirstAdministratorTest` |
| R-CWS-04 | Isolation CWS | CWS ouvre une page communale | Refus (aucun droit communal implicite) | P1 | A+M | `CwsAdministrationTest`, `AdminAccessTest` |
| R-CWS-05 | Back-office Sonata | Parcours en lecture seule | Aucune écriture possible ; accès réservé | P2 | A+M | `SonataBackOfficeTest` |
| R-CWS-06 | Accès commercial | Activation/désactivation d'un accès | Effet immédiat, tracé | P3 | M | `CommercialAccessManagerTest`, `CwsConcurrencyTest` |

### D2 — Administration communale

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-ADM-01 | Équipe | Invitation d'un instructeur et d'un lecteur | Rôles appliqués ; invitation à usage unique | P1 | M+S | `TeamInvitationServiceTest`, `TeamPagesTest` |
| R-ADM-02 | Rôles cumulables | Attribution de plusieurs rôles | Droits = union ; vérifiés côté serveur | P2 | A+M | `VoterTest`, `MembershipManagerTest` |
| R-ADM-03 | Dernier administrateur | Retrait/désactivation du dernier admin | Refusé | P1 | A+M | `TeamManagementTest`, `TeamConcurrencyTest` |
| R-ADM-04 | Désactivation | Utilisateur désactivé tente de se connecter / agir | Accès refusé immédiatement | P1 | A+M | `UserDeactivationTest` |
| R-ADM-05 | Mot de passe | Récupération d'accès | Lien à usage unique, expiration | P2 | S | `PasswordResetFlowTest`, `PasswordResetServiceTest` |
| R-ADM-06 | Sélection de commune | Compte multi-communes | Seules ses communes ; Accueil par commune | P2 | M | `MunicipalHomeTest` |

### D3 — Événements

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-EVT-01 | Création | Événement « inondation » (brouillon) | Champs validés, référence publique unique | P1 | M | `EventCreationServiceTest`, `EventFormPageTest` |
| R-EVT-02 | Cycle de vie | Brouillon → actif → clôturé ; transitions invalides | Seules transitions autorisées | P1 | A+M | `EventLifecycleServiceTest`, `EventLifecycleConcurrencyTest`, `EventLifecyclePageTest` |
| R-EVT-03 | Activation planifiée | Activation à date | Activation à l'heure, une seule fois | P2 | A | `ScheduledActivationTest` |
| R-EVT-04 | Veille | Événement dormant | Alerte/rapport conformes | P3 | A | `EventDormancyTest`, `EventDormancyCommandTest` |
| R-EVT-05 | Lecture | Liste/détail pour une commune | Uniquement les événements visibles | P1 | M | `EventListPageTest`, `EventShowPageTest`, `EventAccessIntegrationTest` |

### D4 — Collaboration inter-communes

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-COL-01 | Invitation | A invite B ; B accepte | Participation active de B | P1 | M | `EventInvitationServiceTest`, `EventCoordinationPageTest` |
| R-COL-02 | Refus d'invitation | B refuse | A informée, B sans accès | P2 | M | idem |
| R-COL-03 | Autonomie de B | B crée ses zones, décide, révoque, clôture seule | A n'intervient sur rien de B | P1 | M | `EventModuleJourneysTest`, `TransversalRecetteTest` |
| R-COL-04 | Isolation | Identifiants forgés (requête, zone, laissez-passer de B utilisés par A et C) | 403/404, jamais de contenu | P1 | A+M | `VoterTest`, `MunicipalRequestConsultationTest`, `PermitRightsScreenTest` |
| R-COL-05 | Suspension de B | A/CWS suspend la participation de B | B en lecture seule, bandeau, historique conservé | P2 | M | `EventParticipationLockTest`, `EventCoordinationPageTest` |
| R-COL-06 | Recherche de communes | Recherche du référentiel pour invitation | Résultats pertinents, sans fuite | P3 | A | `NationalInvitationSearchTest`, `InvitationCandidateReaderTest` |

### D5 — Zones

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-ZON-01 | Création/modification | Créer 3 zones ; modifier libellé | Zones propres à la commune | P1 | M | `EventZoneServiceTest`, `EventZonePageTest` |
| R-ZON-02 | Validité | Zone à validité restreinte (révisions) | Révision tracée, effet sur droits | P2 | M | `EventZoneValidityRevisionTest`, `EventZoneValidityPageTest` |
| R-ZON-03 | Zone suspendue | Suspension puis demande/contrôle sur cette zone | Demande refusée/indiquée, droit non valable sur la zone | P1 | M | `AuthorizationZoneServiceTest`, `PermitZoneScreenTest` |
| R-ZON-04 | Modification en masse | Lot de zones | Atomique | P3 | A | `EventZoneBulkServiceTest` |
| R-ZON-05 | Concurrence | Deux modifications simultanées | Cohérence conservée | P2 | A | `EventZoneConcurrencyTest` |

### D6 — Demandes

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-DEM-01 | Dépôt public sans véhicule | Citoyen valide le formulaire | Référence + accusé | P1 | M | `PublicRequestFlowTest`, `PublicRequestSubmissionTest` |
| R-DEM-02 | Dépôt avec véhicule | Plaques saisies, plusieurs | Plaques normalisées | P1 | M | idem, `PermitVehicleServiceTest` |
| R-DEM-03 | Pièces jointes | Types/tailles autorisés, refusés | Rejets explicites, purge des temporaires | P2 | M | `RequestSubmissionServiceTest` |
| R-DEM-04 | Idempotence | Double envoi | Une seule demande | P1 | A+M | `RequestSubmissionServiceTest` |
| R-DEM-05 | Professionnel | Dépôt par un professionnel | Même traitement, catégorie correcte | P2 | M | `PublicRequestFlowTest` |
| R-DEM-06 | Dépôt par agent | Saisie par un agent | Tracé comme saisie d'agent | P2 | M | `AgentRequestSubmissionTest` |
| R-DEM-07 | Hors période | Dépôt avant l'ouverture / après la fermeture | Refus clair | P1 | A+M | `RequestSubmissionServiceTest`, `ClosedEventGuardsTest` |
| R-DEM-08 | Correction d'identité | Correction par la commune | Tracée, sans doublon de dossier | P2 | M | `RequestIdentityFlowTest`, `RequestIdentityCorrectionTest` |

### D7 — Instruction

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-INS-01 | Consultation | Liste, filtres, fiche | Seules les demandes visibles de la commune | P1 | M | `MunicipalRequestConsultationTest` |
| R-INS-02 | Approbation | Durée, zones de la commune | Autorisation créée pour la commune seule | P1 | M | `MunicipalRequestDecisionTest`, `MunicipalInstructionServiceTest` |
| R-INS-03 | Refus | Motif obligatoire | Décision définitive tracée | P1 | M | idem |
| R-INS-04 | Concurrence | 2 instructeurs approuvent/refusent simultanément | Une décision ; l'autre reçoit un message clair | P1 | A+M | `MunicipalDecisionConcurrencyTest` |
| R-INS-05 | Sans permission | Lecteur/non-membre décide | Refus serveur | P1 | A+M | `VoterTest`, `MunicipalRequestDecisionTest` |
| R-INS-06 | Fenêtre d'approbation | Durée dépassant la fenêtre | Refus ou plafonnement documenté | P2 | A | `MunicipalApprovalWindowCompatibilityTest` |
| R-INS-07 | Délivrance directe | Délivrance sans demande | Autorisation + trace | P2 | M | `DirectIssuanceFlowTest`, `DirectIssuanceServiceTest` |
| R-INS-08 | Collisions de verrou | Mesure des collisions en usage réel (DM-137) | Taux de collisions mesuré | P3 | M | `docs/decisions` DM-137 |

### D8 — Doublons

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-DBL-01 | Détection | Même téléphone / plaque / identité | « Doublon possible » avec liens | P1 | M | `RequestDuplicatesTest` |
| R-DBL-02 | Périmètre | Doublon chez B invisible pour A | Aucune demande hors périmètre n'apparaît | P1 | A+M | `RequestDuplicatesTest` |
| R-DBL-03 | Non bloquant | Décider malgré le signal | Autorisé | P1 | M | idem |
| R-DBL-04 | Limites connues | Accents/casse, noms inversés, téléphone familial | Comportement conforme à `ecarts-techniques-connus.md` | P3 | M | `tests/Support/duplicates-perf.php` (perf seulement) |

### D9 — Laissez-passer

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-LP-01 | Délivrance | Après approbation | Laissez-passer et lien bénéficiaire | P1 | M | `PermitIssuanceTest`, `PermitIssuanceConcurrencyTest`, `BeneficiaryPassTest` |
| R-LP-02 | PDF/QR | Lien, PDF, QR | Lien valide, QR opaque sans donnée personnelle | P1 | M | `BeneficiaryPdfTest`, `PermitQrScreenTest` |
| R-LP-03 | Modification de zone | Ajout/retrait de zone | Effet immédiat, notifié | P1 | M | `AuthorizationZoneServiceTest`, `ZoneConcurrencyTest` |
| R-LP-04 | Plaques | Ajout/retrait de véhicule | Idem | P1 | M | `PermitVehicleServiceTest`, `VehicleConcurrencyTest` |
| R-LP-05 | Période | Prolonger, raccourcir, expirer, reprendre | Droits conformes à chaque période | P1 | M | `AuthorizationPeriodServiceTest`, `PeriodConcurrencyTest`, `PermitPeriodScreenTest` |
| R-LP-06 | Remplacement de jeton | Régénérer le QR | Ancien QR invalide | P1 | M | `PermitTokenReplacementServiceTest`, `TokenReplacementConcurrencyTest` |
| R-LP-07 | Révocation | Révoquer une autorisation de B | A inchangée ; révocation définitive | P1 | M | `AuthorizationRevocationTest`, `RevocationConcurrencyTest` |
| R-LP-08 | Droits | Évaluation des droits combinés | Cohérente avec les écrans | P1 | A | `PermitRightsEvaluatorTest`, `PermitRightsScreenTest` |

### D10 — Vérification publique et contrôle en ligne

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-VP-01 | QR valide | Ouverture du QR | « Valide » + périmètre minimal | P1 | M | `PublicVerificationScenariosTest`, `PublicVerificationPageTest` |
| R-VP-02 | QR inconnu | Jeton inexistant | Réponse neutre, identique à l'expiré côté fuite | P1 | A+M | `PublicVerificationSecurityTest` |
| R-VP-03 | QR expiré | Hors période | « Expiré » | P1 | A+M | `PublicVerificationTimeTest` |
| R-VP-04 | QR révoqué | Après révocation / remplacement | « Non valable » | P1 | A+M | `PublicVerificationScenariosTest` |
| R-VP-05 | Événement clos | Après clôture | Réponse conforme à la règle de clôture | P1 | A+M | `PublicVerificationClosedEventTest` |
| R-VP-06 | Interrupteur à 0 | `PUBLIC_VERIFICATION_ENABLED=0` | Route indisponible | P1 | A | `PublicVerificationSecurityTest` |
| R-VP-07 | Limitation d'abus | Rafale de requêtes | Limiteur actif | P2 | A | `PublicVerificationSecurityTest` |
| R-VP-08 | Runbook | Procédure `recette-verification-publique.md` | Rejouée | P2 | M | runbook |

### D11 — Contrôle hors connexion

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-OFF-01 | Activation | Interrupteur à 0 → route fermée ; à 1 (poste de recette) | Comportement conforme | P1 | A | `ControlHttpTest`, `ControlActivationServiceTest` |
| R-OFF-02 | Appareils | Enrôlement, approbation, code d'appariement, renouvellement, révocation | Cycle conforme | P1 | A+M (navigateur poste) | `ControlDeviceLifecycleTest`, `ControlConcurrencyTest` |
| R-OFF-03 | Jeu de données | Téléchargement, synchronisation par compartiments, intégrité (tronqué/corrompu/rejoué) | Rejets conformes | P1 | A | `ControlDatasetTest`, `tests/Js` |
| R-OFF-04 | PWA | Hors connexion simulée (navigateur poste, mode avion DevTools) | Contrôle fonctionne, bandeau | P2 | S | `ControlPwaTest`, `bin/e2e-control` |
| R-OFF-05 | Horloge | Détection d'horloge incohérente | Alerte conforme | P1 | A | `tests/Js` |
| R-OFF-06 | Consigne Contrôle | Texte et entrée « Contrôle » | Texte **validé par le métier** | P2 | M | `ControlGuideTest` |
| R-OFF-07 | **Smartphones réels (09.7)** | Android/iPhone, HTTPS, veille, horloge, installation PWA | **Hors recette locale — voir §8** | P1 | M | aucun |

### D12 — Notifications et refus

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-NOT-01 | Notifications d'autorisation | Zone, période, véhicule, révocation, remplacement | Un message regroupé et ordonné par bénéficiaire, reçu dans MailCatcher | P1 | S | `NotificationDispatcherTest`, `ZoneNotificationTest`, `PeriodNotificationTest`, `RevocationNotificationTest`, `MailCatcherRecetteTest` |
| R-NOT-02 | Échec de notification | SMTP indisponible | Réessais (outbox), statut visible, commande de statut | P1 | A+S | `NotificationStatusCommandTest`, `DispatcherConcurrencyTest`, `incident-notification.md` |
| R-NOT-03 | Refus : envoi | Interrupteur refus activé en recette ; décision de refus | Message minimal (motifs silencieux `duplicate`/`other`) reçu | P1 | S | `RefusalNoticeTest` |
| R-NOT-04 | Refus : échec | SMTP en erreur | Réessais 1/5/15/60/240 min, abandon après 6 tentatives/24 h, marquage manuel | P1 | A+S | `RefusalNoticeTest` |
| R-NOT-05 | Refus : sans adresse | Demande sans e-mail | Statut « à informer autrement », saisie manuelle du canal | P1 | M | `RefusalNoticeTest` |
| R-NOT-06 | Interrupteur refus à 0 | Valeur par défaut | Aucun envoi | P1 | A | `RefusalNoticeTest` |
| R-NOT-07 | Textes | Libellé juridique du refus | Validé par le métier avant tout envoi réel | P1 | M | aucune |
| R-NOT-08 | Purge | Purge outbox/secrets | Conforme à la conservation | P2 | A | `OutboxPurgeTest`, `SecretPurgeTest` |

### D13 — Sessions et habilitations

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-SES-01 | Expiration d'inactivité | Agents 30 min, CWS 15 min | Redirection connexion, aucune action exécutée | P1 | A+M (délais réduits par env.) | `SessionExpiryTest`, `SessionLifetimeTest` |
| R-SES-02 | Durée absolue | Agents 12 h, CWS 8 h | Expiration même actif | P1 | A | idem |
| R-SES-03 | Avertissement | 2 min avant expiration | Avertissement affiché, sonde sans prolongation | P2 | **M navigateur** | aucun (JS non testé en navigateur réel ; absent des pages Sonata) |
| R-SES-04 | Pas de « se souvenir » | Cookie REMEMBERME forgé | Ignoré | P1 | A | `AuthenticationTest` |
| R-SES-05 | Sessions publiques | Dépôt public et `/v` | Aucune session créée | P2 | A | `SessionExpiryTest` |
| R-SES-06 | Habilitation CWS | Attribution/révocation par console ; sans habilitation | Refus ; trace | P1 | A+M | `CwsPermissionCommandTest`, `ForcedClosureTest` |
| R-SES-07 | Connexion | Limiteur de tentatives | Blocage conforme | P2 | A | `AuthenticationTest` |

### D14 — Clôture

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-CLO-01 | Clôture normale | Toutes participations clôturées | Événement clos, statistiques de clôture | P1 | M | `EventLifecycleServiceTest`, `ClosureStatisticsTest` |
| R-CLO-02 | Participations ouvertes | Clôture avec B encore participante | Refus explicite | P1 | A+M | `EventLifecycleCoverageTest`, `EventLifecycleConcurrencyTest` |
| R-CLO-03 | Événement clos | Tentatives de modification (zones, décisions, plaques) | Toutes bloquées | P1 | A+M | `ClosedEventBarrierTest`, `ClosedEventGuardsTest`, `ClosedEventLinksTest` |
| R-CLO-04 | Notifications de clôture | Producteurs et concurrence | Une seule série de messages | P2 | S | `EventClosureNotificationsTest`, `EventClosureProducersRaceTest` |
| R-CLO-05 | Clôture exceptionnelle | Habilitation `forced_closure`, aperçu, motif obligatoire | Clôture forcée tracée (acteur `support`), mail aux admins sans motif, limiteur | P1 | M | `ForcedClosureTest`, runbook `cloture-exceptionnelle-cws` |
| R-CLO-06 | Sans habilitation | Compte CWS sans permission | Refus | P1 | A+M | `ForcedClosureTest` |

### D15 — Historique, audit, conservation

| ID | Fonctionnalité | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|---|
| R-AUD-01 | Journal d'audit | Chaque action du parcours P0→P14 | Entrée datée (UTC), acteur, sujet ; même transaction | P1 | M | `AccessAndAuditTest`, `AuditOperatorBatchTest` |
| R-AUD-02 | Conservation | Aucune suppression avant échéance | Rien purgé en avance | P1 | A | `RetentionRunTest`, `RetentionAccessTest` |
| R-AUD-03 | Purge de dossier | Échéance atteinte | Suppression contrôlée, tracée, preuve au registre | P1 | A | `DossierPurgeTest`, `RegisterProofTest` |
| R-AUD-04 | Archivage et gel | Mise en conservation légale | Données gelées | P2 | A | `LegalHoldTest`, `ArchiveAccessTest` |
| R-AUD-05 | Droits des personnes | Accès/export/rectification/effacement | Conforme, délais suivis | P2 | M | `Rights*Test` |
| R-AUD-06 | Restauration | Sauvegarde/restauration contrôlée | Cohérence après restauration | P2 | A | `RestoreCommandsTest` ; runbook `restauration` |
| R-AUD-07 | Cohérence finale | `doctrine:schema:validate`, contrôle d'intégrité | OK | P1 | A | `OpsIntegrityCheckCommand` |

### Scénarios négatifs transversaux

| ID | Scénario | Attendu | P | Mode | Réf. |
|---|---|---|---|---|---|
| R-NEG-01 | `A-instr` forge l'URL d'une demande, zone ou laissez-passer de B | 403/404 sans contenu | P1 | A+M | `VoterTest`, `MunicipalRequestConsultationTest` |
| R-NEG-02 | `C-admin` (non invitée) ouvre l'événement de A | Refus | P1 | A+M | `EventAccessIntegrationTest` |
| R-NEG-03 | Lecteur sans permission tente décision/édition | Refus serveur, pas seulement bouton masqué | P1 | A+M | `VoterTest` |
| R-NEG-04 | Demande en doublon | Signal, pas de blocage | P1 | M | `RequestDuplicatesTest` |
| R-NEG-05 | Demande hors période | Refus | P1 | A+M | cf. R-DEM-07 |
| R-NEG-06 | Zone suspendue | Cf. R-ZON-03 | P1 | M | cf. R-ZON-03 |
| R-NEG-07 | Approbations concurrentes | Cf. R-INS-04 | P1 | A+M | cf. R-INS-04 |
| R-NEG-08 | QR inconnu / expiré / révoqué | Cf. R-VP-02 à 04 | P1 | A+M | cf. R-VP |
| R-NEG-09 | Échec de notification | Cf. R-NOT-02 / R-NOT-04 | P1 | A+S | cf. R-NOT |
| R-NEG-10 | Expiration de session en cours de formulaire | Perte maîtrisée, aucune écriture partielle | P1 | A+M | cf. R-SES-01 |
| R-NEG-11 | Clôture avec participations ouvertes | Cf. R-CLO-02 | P1 | A+M | cf. R-CLO-02 |
| R-NEG-12 | Rejeu d'un jeton d'invitation, de réinitialisation, d'appariement | Refus (usage unique) | P1 | A+M | `InvitationLinkPageTest`, `PasswordResetServiceTest`, `ControlActivationServiceTest` |
| R-NEG-13 | Injection/XSS dans les champs libres des demandes | Échappement, aucune exécution | P2 | M | tests de gabarits |
| R-NEG-14 | Débordements : pièces jointes volumineuses, plaques invalides | Rejet explicite | P2 | M | `RequestSubmissionServiceTest` |

## 6. Scénarios critiques à exécuter en priorité

Ordre proposé (si un seul échoue : arrêt, anomalie bloquante, arbitrage) :

1. **Isolation et droits** : R-NEG-01, R-NEG-02, R-NEG-03, R-CWS-04, R-COL-04, R-INS-05.
2. **Chaîne cœur** : parcours P0→P12 (R-CWS-02/03, R-EVT-01/02, R-COL-01/03, R-ZON-01, R-DEM-01/02, R-INS-02/03/04, R-LP-01/07, R-CLO-01/02).
3. **Contrôle** : R-VP-01 à R-VP-06 ; R-OFF-01 à R-OFF-03 en local.
4. **Notifications** : R-NOT-01 à R-NOT-06 (MailCatcher uniquement).
5. **Clôture exceptionnelle et sessions** : R-CLO-05/06, R-SES-01/02/06.
6. **Audit et conservation** : R-AUD-01 à R-AUD-03, R-AUD-07.
7. Puis le reste (P2, P3).

## 7. Tests actuellement ignorés dans PHPUnit (6)

Liste obtenue par `phpunit --display-skipped` (conteneur `saas_civipass_php-fpm`, base de test migrée). Les 6 sauts sont **volontaires** : ce sont des **recettes semi-réelles** qui ne s'exécutent que si une variable d'environnement les active. Aucun n'est un test désactivé pour masquer un échec. Les tests conditionnés à un schéma non migré s'exécutent, la base de test étant migrée ; les tests d'exploitation Linux (`OpsUnitsTest`, `NginxOperationsTest`) **s'exécutent** dans le conteneur et ne sont pas ignorés.

| # | Test | Variable d'activation | Ce qu'il fait | Impact sur la recette |
|---|---|---|---|---|
| 1 | `NotificationScreenTest::testRenderThePagesForTheBrowserRecette` | `CIVIPASS_RECETTE_RENDER=1` | Produit les pages de notification pour revue visuelle en navigateur | **À activer** (revue visuelle des écrans de notification) |
| 2 | `MobileSnapshotRecetteTest::testProduceTheRealScreensForTheMobileRendering` | `CIVIPASS_RECETTE_SNAPSHOT=1` | Produit des écrans pour rendu mobile (iframe 390 px) | **À activer** (rendu mobile sur poste ; **ne remplace pas 09.7**) |
| 3 | `MailCatcherRecetteTest::testBothMessagesReachMailCatcherThroughTheRealMailer` | `CIVIPASS_RECETTE_MAILCATCHER=1` (+ MailCatcher joignable, `DEFAULT_URI`) | Les deux messages atteignent MailCatcher via le vrai mailer | **À activer** (R-NOT-01) |
| 4 | `MailCatcherRecetteTest::testASmtpOutageIsRetriedWithTheSameMessageIdAndTheSameLink` | idem | Panne SMTP : réessai avec le même identifiant et le même lien | **À activer** (R-NOT-02) |
| 5 | `MailCatcherRecette055Test::testEveryPriorityMessageOfTheOperationsReachesMailCatcher…` | idem | Tous les messages prioritaires des opérations sans donnée sensible | **À activer** (R-NOT-01) |
| 6 | `MailCatcherRecette055Test::testASmtpOutageOnTheQrMessageIsRetriedOnceWithTheSameMessageId` | idem | Panne SMTP sur le message QR : un seul réessai, même identifiant | **À activer** (R-NOT-02) |

Impact : les 6 sont **à exécuter pendant la recette** (ils couvrent R-NOT-01/02 et les rendus). Ils sont absents du passage `bin/qa full` par conception, ce qui est sans conséquence tant qu'ils sont joués pour la recette.

## 8. Limites de la recette locale et prérequis de la recette sur smartphones réels (09.7)

### Limites de la recette locale

- Pas de HTTPS ni de sous-domaine dédié : service worker/PWA, installation et certains comportements de stockage ne sont fiables que sur appareil réel via HTTPS.
- Navigateur de poste ≠ smartphone : tactile, veille, mémoire, horloge, mode avion réel, navigateurs mobiles (Safari iOS) non représentés.
- SMTP : MailCatcher uniquement ; aucune délivrabilité réelle (spam, SPF/DKIM) ; « envoyé » = remis au serveur de messagerie.
- Pas de charge réelle ni de latence réseau ; mesure des doublons faite sur 10 000 demandes en base locale.
- Délais de session : validés avec délais réduits par variables d'environnement ; l'avertissement de session n'existe pas sur les pages Sonata.
- Exploitation serveur réelle (systemd du VPS, Nginx, sauvegardes serveur) non couverte : les tests d'exploitation ne tournent que sur le conteneur.
- Concurrence : testée par tests dédiés ; la recette manuelle ne la reproduit qu'à deux opérateurs.

### Prérequis de la recette 09.7 (smartphones réels)

1. **Autorisation d'infrastructure explicite** distincte (environnement HTTPS accessible depuis les appareils, sous-origine dédiée au contrôle) — **aucun VPS/déploiement sans cette autorisation**.
2. Appareils : au moins 1 Android récent + 1 iPhone (Safari), + 1 appareil ancien si possible ; versions notées.
3. Interrupteur `OFFLINE_CONTROL_ENABLED=1` **limité à l'environnement de recette**, jamais par défaut.
4. Données **fictives** uniquement (conditions d'usage de données réelles non remplies : AIPD, acceptation de R-OFF-01, textes validés, 09.7).
5. Textes validés (bandeau, consigne « Contrôle »).
6. Protocole : installation/écran d'accueil, appariement, synchronisation, mode avion, veille longue, redémarrage, **horloge modifiée**, QR valides/expirés/révoqués, batterie, espace de stockage.
7. Fiche de résultats par appareil ; critères de la section 14–16 du cadrage LOT 09.

## 9. Besoins en données fictives et arbitrages

### Données fictives (sans fixtures métier préchargées)

| Besoin | Proposition |
|---|---|
| Base | Base **dédiée de recette** (`civipass_recette`, nom contenant `recette`), distincte de `civipass` (dév.) et `civipass_test` ; migrée avec les migrations existantes uniquement. |
| Amorçage | Commandes existantes seulement : super-admin, synchronisation du référentiel national, types d'événements, référentiels de demandes. |
| Création | Tout le reste **par l'UI ou les commandes de production** (communes, utilisateurs, événements, zones, demandes). |
| Jeux de saisie | Fichier versionné de **données de saisie fictives** (6 citoyens, plaques `AA-123-AA`…, téléphones en `06 00 00 00 xx`, e-mails `@recette.invalid`) à copier-coller ; aucune donnée issue de personnes réelles. |
| Volumétrie | 10 000 demandes déjà utilisées via `duplicates-perf.php` (base de test uniquement) ; pas de jeu massif en recette manuelle. |
| Temps | Horloge réelle en manuel ; scénarios de dépassement (période, expiration, session) par tests A ou délais réduits en environnement du poste. |
| E-mails | MailCatcher (déjà dans le projet) ; aucun envoi réel. |

### Arbitrages indispensables avant de démarrer

| # | Question | Recommandation |
|---|---|---|
| A1 | **Base de recette dédiée** (`civipass_recette`) : sa création est locale, hors migration nouvelle. Autorisée ? | Oui (évite de polluer `civipass`) |
| A2 | **Activation des interrupteurs sur le poste de recette** (variables d'environnement locales non versionnées, jamais dans `.env`) pour tester la vérification publique, le contrôle hors connexion et l'envoi des refus vers MailCatcher | Oui, avec retour à 0 après la recette ; vérifié par un test de configuration par défaut |
| A3 | **Mode d'exécution** : manuel guidé par navigateur (par vous ou par moi via script E2E `bin/e2e-control`/Playwright) ou automatisé ? | Parcours de référence en **E2E navigateur scénarisé** (reproductible), négatifs et concurrence par PHPUnit/outils existants |
| A4 | **Textes à valider avant la recette** : consigne « Contrôle » et libellé du message de refus. Sinon recette marquée « texte provisoire » | Valider avant R-NOT-07 et R-OFF-06 |
| A5 | **Politique d'anomalie** : tout correctif fonctionnel soumis à validation ; correctifs techniques/de test autonomes | Conserver |
| A6 | **Critère de sortie** : 100 % des P1 « Réussi » ; P2 « Réussi » ou accepté avec dette documentée ; aucun bloquant ouvert | À valider |
| A7 | **Date/lieu de 09.7** et fourniture d'un environnement HTTPS (autorisation d'infrastructure) | À décider séparément ; hors du périmètre local |
| A8 | **Les 6 recettes semi-réelles ignorées** : accord pour les activer par variables d'environnement locales (MailCatcher, rendu, instantanés mobiles) | Oui, uniquement sur le poste de recette |

## 10. Livrables de la recette et critères de sortie

Livrables : matrice renseignée (ce document, colonnes Observé/Statut/Preuve), registre des anomalies, rapport synthétique à 7 points, captures et traces MailCatcher, journal d'audit extrait, recommandation de clôture du LOT 10.

**Critères de sortie** (proposés) : tous les scénarios P1 « Réussi » ; zéro anomalie bloquante ou majeure ouverte non acceptée ; isolation inter-communes confirmée par les scénarios négatifs ; interrupteurs de sécurité à 0 dans les configurations par défaut (vérifié en fin de recette) ; `bin/qa full`, `verify-docs.sh` et `doctrine:schema:validate` verts ; 09.7 restant explicitement **non exécutée** tant que l'infrastructure n'est pas autorisée.

