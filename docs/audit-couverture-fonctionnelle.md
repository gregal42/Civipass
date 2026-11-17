# Audit de couverture fonctionnelle SFD / CDC — périmètre du premier pilote

**Date** : 17 novembre 2026 · **Branche** : `develop` (à `e475d16`) · **Nature** : audit documentaire et analytique. Aucun développement, aucune migration, aucun déploiement, aucun VPS, aucun changement sur `main`. Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

**Sources confrontées** : CDC V1.0 (1er nov. 2026), SFD V1.0 détaillé (corps + annexes A à D), 169 fiches de décision (114 `DM-`, 55 `DT-`, dans `decisions/`), `revisions/CONTRADICTIONS.md`, `development-roadmap.md`, `lot-06-audit.md` (13 nov.), code (`src/`, `templates/`, `config/`) et 271 fichiers de tests.

**Niveaux de preuve** (comme `lot-06-audit.md`) : **[C]** lu dans le code ; **[T]** test automatisé existant (fichier cité) ; **[D]** décision validée ; **[M]** à vérifier manuellement ou sur appareil réel. **Un test automatisé n'est jamais une recette utilisateur** : aucune des exigences ci-dessous n'a été recettée par un agent municipal ni sur smartphone réel (voir §9).

**Règle de lecture** : les décisions validées prévalent sur le SFD/CDC lorsqu'elles les modifient explicitement. Lorsqu'un écart n'est couvert par **aucune** décision explicite, il est classé « incohérence documentaire » (§6) et non « obsolète ».

**Limites de cet audit** : lecture ciblée du code (recherches, lecture des contrôleurs et gabarits clés), pas de relecture ligne à ligne de chaque service ; les tests sont cités par fichier, sans les avoir tous relancés (le dernier `bin/qa full` du 16 nov. est vert : 3358 tests). Les points marqués « à confirmer » n'ont pas été prouvés dans le code.

---

## 1. Synthèse exécutive

**Ce qui est solide.** Depuis l'audit du 13 novembre, les maillons bloquants ont été livrés : ouverture d'une commune, accès commerciaux, premier administrateur et remplacement (07.1), équipes communales (07.2), contrôle QR public durci (07.3). Le cœur métier — événements multi-communes, zones et validités, dépôt public sans compte, instruction par commune, délivrance directe, laissez-passer unique à QR stable, modifications/prolongations/révocations, clôture, archives, conservation, droits des personnes, exploitation — est implémenté, testé et isolé par commune.

**Ce qui manque réellement pour un pilote** (§3) :

| # | Écart | Nature |
|---|---|---|
| E1 | **Aucune détection de doublons potentiels** (SFD §6.3, SCR-027, REC-012, DM-101, DM-102) : ni code ni test ; seul un motif de refus « Demande en doublon » existe | fonctionnel |
| E2 | **Un demandeur refusé n'est jamais informé** : aucun message de refus ; aucun suivi public n'existe (SFD §7.1, SCR-030) | fonctionnel + arbitrage |
| E3 | **Pas d'écran d'accueil ni de tableau de bord communal** (SCR-007, SCR-010, CDC §10.1) | fonctionnel |
| E4 | **Pas de clôture forcée par CWS** (DM-065 validée) : l'alerte de dormance ne clôt jamais ; un événement dont l'organisatrice est injoignable ne peut être clos qu'en SQL | fonctionnel |
| E5 | **Aucune expiration de session maîtrisée** : comportement hérité des valeurs PHP (≈ 24 min, aléatoire) ; le brouillon de demande publique vit dans cette même session (§4.3) | sécurité |
| E6 | **Contrôle terrain sans consigne ni entrée de menu** ; activation du contrôle non décidée (P0-5 du 13 nov.) | décision + ergonomie |

**Ce qui est volontairement reporté** : PWA hors ligne (DM-115 §5), SMS (aucun code), impersonation (DM-061), journal d'activité consultable, administration des référentiels, logo/SIRET de commune, compteurs de scans (DM-127).

**Contradictions SFD/CDC et avec les décisions** (§6) : 14 relevées. Les plus structurantes : le SFD classe PWA, SMS et « mode dégradé papier 48 h » en V1 alors que le produit les exclut ; le SFD prévoit un **verrou d'instruction de 15 min** qu'aucune décision n'abandonne explicitement ; le SFD/CDC prévoient l'**inscription autonome des communes** alors que l'ouverture est manuelle par CWS sans décision écrite d'abandon ; la **révocation globale par l'organisatrice** (SCR-033) est contredite de fait par DM-116.

**Verdict.** Après correction de E1 à E6 (cinq petits lots, §8) et recette utilisateur, le périmètre figé proposé au §7 est atteignable **sans nouveau concept métier majeur**. Quatre sujets exigent un arbitrage avant développement (§7.4) : message de refus (E2), consigne et activation du contrôle (E6), sort du verrou 15 min, sort de la révocation globale organisatrice.

---

## 2. Matrice de couverture

Légende statut : **C** couvert · **P** partiel · **A** absent · **R** reporté (décision ou choix explicite) · **O** obsolète/remplacé par décision. Priorité : **P0** bloquant, **P1** avant pilote, **P2** amélioration pilote, **V2**, **INST** installation finale. « Impact » = effet sur un premier pilote encadré.

### A. Administration CWS

| Réf. SFD/CDC | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| CDC §3.1 « création manuelle depuis Sonata » ; SCR-047 | DM-078, DM-121, DM-032 | **C** | `CwsAdministrationController`, `MunicipalityManager::create`, ouverture d'une commune depuis le référentiel INSEE | `Functional/Admin/CwsAdministrationTest`, `SonataBackOfficeTest`, `AdminAccessTest` | Recette visuelle Sonata non faite [M] | faible / P2 |
| CDC §3.2, SCR-049 accès commercial, abonnement | DM-070, DM-073, DM-076, DM-079, DM-121 §2 | **C** | accès successeur, révocation, expiration ; conditionne la seule création d'événement | `CwsAdministrationTest` | motif de révocation non conservé (DM-121 §5, accepté) ; pas de prix HT ni d'engagement 12 mois (facturation externe) | nul / — |
| CDC §11.1, SCR-051 comptes ; premier administrateur, invitation | DM-121 §1,3,4, DM-036 | **C** | invitation 7 j à usage unique, remplacement inviter→accepter→retirer, « En attente d'administrateur » | `CwsAdministrationTest`, `Onboarding/InvitationLinkPageTest`, `EnrollFirstAdministratorTest` | e-mail réel non testé (SMTP) [M] | moyen / INST |
| SCR-001 à 005 inscription autonome, souscription 12 mois | aucune (voir §6, I3) | **O** | remplacée de fait par l'ouverture CWS | — | aucune décision écrite d'abandon | nul pilote / doc |
| CDC §3.1 « vérification de légitimité » | DM-121 (implicite : CWS valide) | **P** | procédure CWS hors outil ; aucun justificatif tracé | — | processus à écrire (hors code) | moyen / P1 (procédure) |
| SCR-045 dashboard CWS | — | **A** | seules 3 listes + fiches dans Sonata | — | aucune vue de volumes/erreurs | faible / V2 |
| SCR-046 dossier CWS en lecture seule | DM-005, DM-121 §1 | **O** | volontairement absent (CWS ne lit aucun dossier) | `AdminAccessTest` | SFD contredit DM-005 | nul / doc |
| SCR-050 impersonation | DM-061 (exigences anticipées, MFA prérequis) | **R** | — | — | — | nul / V2 |
| SCR-052 supervision et **clôture forcée** événements | DM-065 (validée) | **A** | aucune route ; `EventDormancyAlertService` n'alerte que | `EventDormancy*Test` (alerte seule) | **E4** | **moyen / P1** |
| SCR-048 suppression d'une commune | — | **A** | aucune ; conservation/rétention encadrent la fin de vie | — | décision de périmètre manquante | faible / V2 |
| SCR-053/054 référentiels types, motifs de refus | DM-097, DM-098, DM-110 « administrable » | **P** | semés par migration/commande (`RefusalReasonInitializer`, `EventTypeInitializer`) | tests d'initialisation | aucune administration (figés en V1 par orientation 07.1) | faible / V2 |
| SCR-055 paramètres techniques, SCR-044 maintenance | DT (configuration) | **P** | YAML/env ; `app:ops:maintenance` en console | `Ops/OpsTest` | aucun écran ; pas de bornes éditables | faible / INST |
| SCR-056/057 modèles d'e-mail, journaux de notifications | DT-049 | **P** | modèles Twig versionnés ; état d'envoi côté agent (`NotificationScreenTest`) ; outbox | `Permit/NotificationScreenTest`, `Unit/Notification` | pas d'édition ni de vue CWS des échecs | faible / V2 |
| Récupération d'accès (CDC §11, SCR-006) | DM-121 | **C** | `/forgot-password`, `/reset-password/{token}` ; `docs/runbooks/recuperation-acces.md` (6 situations) ; `app:super-admin:create` | `PasswordResetFlowTest` | runbook à relire hors équipe [M] ; dépend du SMTP | moyen / P1 (relecture) |

### B. Gestion des événements

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| SCR-011/012 créer, modifier ; champs structurants | DM-053, DM-059, DM-071, DM-082, DM-069, DM-072 | **C** | `EventFormController` ; référence `EVT-…` ; fuseau IANA (DM-054/062) | `Event/EventFormPageTest`, `EventShowPageTest`, `EventListPageTest` | — | — |
| SCR-015 activation ; activation à l'heure de début | DM-059, DM-123 | **C** | `EventLifecycleController` + `ScheduledEventActivation` | `EventLifecyclePageTest`, `ScheduledActivationTest` | activation planifiée dépend d'un timer systemd non installé (INST) | INST |
| SCR-016/017/018 zones, validités, créneaux, groupé | DM-006…DM-030, DM-051…DM-096 | **C** | `EventZoneController`, `ZoneValidityController` ; nuit, récurrences, DST, plafonds | `EventZonePageTest`, `EventZoneValidityPageTest`, tests unitaires du calendrier | — | — |
| SCR-013/014 communes participantes, invitations | DM-001…DM-004, DM-056, DM-086, DM-089 | **C** | invitations par commune, renvoi, statuts | `EventCoordinationPageTest`, `InvitationEmailPageTest` | — | — |
| Suspension/reprise globale par l'organisatrice ; suspension/clôture de sa propre participation | DM-056, DM-066, DM-081, DM-092 | **C** | `EventLifecycleController` (`suspendre` avec motif obligatoire) | `EventLifecyclePageTest` | — | — |
| Clôture normale organisatrice ; clôture draft | DM-077, DM-085, DM-092, DM-120 | **C** | clôture globale automatique à la dernière participation | `ClosureStatistics*Test` | — | — |
| Règles multicommunales (participation sans droit, commune tardive, visibilité) | DM-084, DM-090, DM-091, DM-101 | **C** | politiques d'accès + filtrage requêtes | `Archive/ArchiveAccessTest`, `Integration/Event/EventAccessIntegrationTest` | — | — |
| CDC §4.1 « clôture globale réservée à CWS » | DM-056, DM-065 | **O** | clôture normale = organisatrice ; CWS = exception (non livrée, E4) | — | CDC contredit (déjà résolu N1/S1) | — |

### C. Demandes publiques

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| SCR-019…026 parcours public sans compte (étapes, brouillon, anti double-clic, référence `DEM-…`) | DM-097…DM-105 | **C** | `PublicRequestController`, brouillon en **session** (`PublicRequestDraft`) | `Request/PublicRequestFlowTest`, `PublicRequestSubmissionTest` | brouillon perdu après expiration de session (§4.3) | moyen / P1 |
| SCR-020 identité/contact (e-mail ou téléphone) | DM-100 | **C** | validations serveur | `PublicRequestFlowTest` | — | — |
| SCR-021 pièces facultatives (≤ 10 Mo, MIME réel) | DM-099 (§ pièces), CDC §17 | **C** | `TemporaryUploadService`, `UploadedDocumentInspector`, `PermitRequestAttachment::MAX_SIZE_BYTES = 10 Mo` | tests Request | nombre max (5) et antimalware non confirmés ; **durée de conservation des pièces à décider** (CDC §17) | moyen / P1 (conf.) |
| SCR-022 zones/communes proposables ; commune invitée non active | DM-104 | **C** | `ProposableZones` | `PublicRequestFlowTest` | — | — |
| SCR-023 véhicules, plaques SIV/FNI, piéton/vélo | DM-103 | **C** | `PlateFormatter`, 1 à 10 plaques | tests Request/Permit | — | — |
| Consentements, information RGPD | DM-099 §4, DM-117 | **P** | case/texte en place ; **libellé juridique non validé** | — | validation juridique externe | élevé / INST (juridique) |
| **Suivi du dépôt** | SFD §1.3 « suivi public exclu » ; DM-114 | **C** | pas de suivi ; confirmation à l'écran + e-mail de réception (`ReceiptNotifier`) | `PublicRequestSubmissionTest` | conséquence : voir E2 | — |
| Accès au laissez-passer (lien bénéficiaire, PDF) | DM-114, DM-118, DT-051 | **C** | `/laissez-passer/{secret}`, empreinte SHA-256, 404 uniforme | `Permit/BeneficiaryPassTest`, `BeneficiaryPdfTest` | citoyen sans e-mail : remise en main propre par l'agent | faible |
| **Doublons potentiels** (SFD §6.3, SCR-027, REC-012/Annexe C) | DM-101 §, DM-102, lot-03 invariants 13-14 | **A** | aucune ; documenté pour LOT 03 (« calculé à la lecture ») mais jamais codé | **aucun** | **E1** | **moyen / P1** |

### D. Instruction

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| SCR-027 liste (onglets, tri, recherche, pagination) | DM-102 | **P** | une liste (`app_municipal_requests`), recherche, onglets | `Request/MunicipalRequestConsultationTest` | recherche par plaque/téléphone/n° de titre **à confirmer** ; badge doublon absent (E1) | moyen / P1 |
| SCR-028 fiche, correction d'identité | DM-111, DT-045 | **C** | `RequestIdentityController`, verrou de version, audit | `Request/RequestIdentityFlowTest` | **proposition de renvoi après correction** : à confirmer | faible / P2 |
| **Verrou d'instruction 15 min** exclusif, reprise forcée (SFD §3, SCR-028, §6.1) | DT-024, DT-041 (verrous de base de données, pas d'UI) | **O ?** | sérialisation en base ; **aucun verrou visible**, aucune « reprise » | `MunicipalRequestDecisionTest` | **non abandonné explicitement** (I4) | faible (petite équipe) / P2 + décision |
| SCR-029/030 approbation totale/partielle, refus motivé, décision binaire par commune | DM-106, DM-107, DM-109, DM-110 | **C** | `MunicipalInstructionService`, `MunicipalDecisionApplier` ; rôle `INSTRUCTOR` exigé | `Request/MunicipalRequestDecisionTest` | — | — |
| **Notification du refus au demandeur** (§7.1, SCR-030) | DM-110 (motif), DM-117 (notifs bénéficiaire seulement) | **A** | `NotificationType` ne contient aucun refus ; aucun modèle `refus` | **aucun** | **E2** | **élevé / P1 + arbitrage** |
| Délivrance directe par agent habilité ; remplace DM-099 §2/§5 | DM-108 | **C** | `DirectIssuanceController`, atomique | `Request/DirectIssuanceFlowTest`, `AgentRequestSubmissionTest` | e-mail non produit par décision ; remise du lien manuelle | faible |
| Création du laissez-passer à la 1ʳᵉ approbation ; enrichissement ; QR stable | DM-112, DM-113 | **C** | `PermitIssuer` | `Permit/*`, `Unit/Permit/*` | — | — |
| SCR-032 modification : zones, périodes, plaques, prolongation (même expirée), renvoi | DM-116, DM-119 | **C** | `PermitZonesController`, `PermitPeriodController`, `PermitVehiclesController`, révisions immuables | `PermitZoneScreenTest`, `PermitPeriodScreenTest`, `PermitVehicleScreenTest` | — | — |
| SCR-033 révocation **locale** | DM-116 §2 | **C** | `AuthorizationRevocationService`, motif obligatoire, irréversible | `PermitRightsScreenTest` | — | — |
| SCR-033 révocation **globale par l'organisatrice** ; REC-010 | DM-116 §2,4 (autorisations des autres communes subsistent), DM-084 | **O ?** | non implémentée ; seul remplacement exceptionnel du QR (DM-113) | — | **contredite de fait, sans décision d'abandon** (I5) | moyen (multicommune) / décision |
| Notification de modification/révocation au bénéficiaire | DM-117, DT-049 | **C** | 11 types dans `NotificationType`, outbox, relance manuelle | `Unit/Notification`, `NotificationScreenTest` | **SMTP réel non testé** ; distributeur systemd non installé | élevé / INST |

### E. Contrôle terrain

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| CDC §9.1, SCR-034/035 contrôle en ligne sans compte, vert/rouge, plaques, zones | DM-113, DM-115, DM-125…128, DT-055 | **C** (**désactivé**) | `/v/{jeton}`, droits recalculés à chaque lecture, zones valides d'abord, messages nationaux fixes | `PublicVerificationPageTest`, `…ScenariosTest`, `…SecurityTest`, `…ClosedEventTest`, `RateLimiterVerificationThrottleTest` | recette appareils réels non faite (VP-01…54, reportée) ; **activation non décidée** | **élevé / P1 (décision) + INST (recette)** |
| Orange « indéterminé » (CDC §9.1) | DM-115, DM-128 | **O** | pas d'orange : valide/non valide/terminé/inconnu ; erreur serveur = pas de feu vert | `PublicVerificationSecurityTest` | CDC/SFD contredits | — |
| Sécurité du jeton, énumération, limitation de débit, journalisation anormale | DM-113, DT-048, DT-055 | **C** | 160 bits, 404 uniforme, 1 500/10 min/IP et 20 échecs/15 min ; seuils journalisés sans IP/jeton | `PublicVerificationSecurityTest` | valeur 1 500 à confronter à une charge réelle (INST) | moyen / INST |
| Événement clos : « terminé » puis générique après purge | DM-120 | **C** | message neutre sans PII | `PublicVerificationClosedEventTest` | purge non activée | — |
| CDC §9.2 / SCR-036…039 **PWA hors ligne**, seuils 12 h/24 h, appareils | DM-115 §5, DM-116 §4, roadmap | **R** | non développée ; `ControllerActivation/Device` abandonnés | — | SFD/CDC la mettent en V1 (I1) | nul si réseau correct / V2 |
| **Mode dégradé papier 48 h** (SFD §7.2, REC-007/008) | DM-118 §7 (PDF = instantané ; QR = droits courants) | **O ?** | le PDF imprime « seul le contrôle du QR fait foi » : l'inverse du mode dégradé | `BeneficiaryPdfTest` | **abandon implicite** ; protocole non validé avec les autorités (SFD §10.2) | moyen / décision |
| Journal de contrôle (CDC §9.3) | DM-115 §6, DM-127 | **R** | aucun scan journalisé | — | CDC « journal minimal » vs DM-127 | nul / V2 |
| **Entrée « Contrôle », consigne terrain** | — | **A** | aucune page d'aide ; pas de menu | — | **E6** | moyen / P1 |

### F. Fin de vie et conformité

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| Clôture : QR inutilisables, liens fermés, statistiques anonymes | DM-120 | **C** | `ClosureStatistics*`, `ClosedEventResolver` | `Event/ClosureStatistics*Test`, `Permit/ClosedEvent*Test` | — | — |
| Archives lecture seule, désignation, gel légal | DM-120 | **C** | `src/Archive/` | `Archive/*Test` | — | — |
| Statistiques coordonnées entre communes | DM-120 | **C** | `StatisticsCoordinationController` | `StatisticsCoordinationTest` | — | — |
| Conservation, purge, registre de preuve, restauration | DM-120, DT | **C** (**interrupteur 0**) | `src/Retention/` | `Retention/*Test` | **politique de purge non validée juridiquement** | élevé / INST (juridique) |
| Droits RGPD : accès, rectification, effacement, export | DM-117, DM-120 | **C** (**interrupteur 0**) | `src/Rights/` | `Rights/*Test` | effacement non activé ; process interne à écrire | élevé / INST (juridique) |
| Exploitation : sauvegarde, restauration, supervision, remise en service | roadmap, runbooks | **C** (local) | `ops/`, `src/Ops/`, 9 runbooks | `Ops/OpsTest`, `RestoreCommandsTest` | aucun exercice sur hôte réel ; `RevocationConcurrencyTest` et `RestoreReplayTest` intermittents (`ecarts-techniques-connus.md`) | élevé / INST |
| Journal d'activité communal (SCR-041, CDC §7.2) | DM-120 (audit immuable) | **P** | audit écrit dans la même transaction ; **aucun écran de lecture** pour l'administrateur communal | lecteur d'audit CWS uniquement (aucun test d'écran communal) | écart de transparence | moyen / P2 |
| Statistiques en cours d'événement (CDC §10.1, SCR-010) | — | **A** | seulement à la clôture | — | rattaché à E3 | moyen / P1 |

### G. Expérience utilisateur transversale

| Réf. | Décision | St. | Implémentation [C] | Tests [T] | Écart | Impact / Prio |
|---|---|---|---|---|---|---|
| SCR-001 sélection de commune, changement de contexte ; commune par défaut | — | **P** | sélecteur ; `ShellContextFactory` | tests Shell/Identity | « commune par défaut » non confirmée ; **aucune commune accessible : écran de blocage** (SCR-001) **à confirmer** | moyen / P1 |
| SCR-007/010 **accueil / tableau de bord** | — | **A** | `/` redirige vers le sélecteur puis la liste des événements | — | **E3** | **moyen / P1** |
| SCR-008/009 équipes : inviter, rôles, retirer, dernier admin | DM-036, DM-122, DM-124 | **C** | écran « Équipe » | `Admin/TeamPagesTest` | recette ergonomique avec agents [M] ; pas d'e-mail lors d'un retrait (DM-122 §3) | moyen / P2 |
| Désactivation immédiate d'un utilisateur | DM-036, DM-122 | **C** | `User::isEqualTo` (actif/mot de passe/e-mail) + `UserChecker` | `AuthenticationTest` | rôle retiré : effet « à la prochaine requête » (relecture en base) [C partiel] | faible |
| États vides, messages d'erreur non techniques | SFD contrôles communs | **P** | messages travaillés sur les écrans livrés | tests fonctionnels | pas de revue transverse | moyen / P2 |
| Sessions : expiration, durée maximale, révocation | CDC §11.2, SFD §9.1 | **P** | cookies sécurisés ; **aucune règle d'inactivité** | `RememberMeTest` | **E5**, §4.3 | **moyen / P1** |
| Accessibilité RGAA, appareils modestes | CDC §17 | **P** | tokens, contraste mesuré pour le contrôle (07.3) | `MobileSnapshotRecetteTest` | aucune revue RGAA ni test lecteur d'écran | moyen / P2 |
| Responsive mobile pour élus | CDC §10.2 | **P** | design system responsive | `MobileSnapshotRecetteTest` | recette smartphone réel [M] | moyen / INST |

---

## 3. Écarts fonctionnels réellement bloquants

Rien ne bloque plus l'ouverture technique d'une commune (P0-1 à P0-4 du 13 nov. sont levés). Les écarts suivants doivent être traités **avant le pilote**.

| # | Écart | Pourquoi bloquant pour un pilote | Arbitrage requis ? |
|---|---|---|---|
| **E1** | Doublons potentiels non détectés | Un même administré dépose plusieurs dossiers (formulaire ouvert au grand public en crise) ; sans signal, les agents émettent plusieurs laissez-passer par personne, ce que le modèle (« un dossier et un laissez-passer par personne ») veut éviter. Spécification complète déjà décidée (lot-03 §6 inv. 13-14, DM-101 : limité aux dossiers visibles pour la commune) | Non : spécifié |
| **E2** | Refus jamais communiqué | Le SFD prévoit « motif transmis au citoyen » ; sans suivi public ni message, un refusé n'a **aucun** retour. Risque de rappels massifs en mairie | **Oui** : contenu du message, donnée exposée (motif du référentiel seulement ?), canal sans e-mail, texte juridique |
| **E3** | Pas d'accueil / tableau de bord | L'agent n'a ni compteur de demandes à traiter ni message « aucun rôle / aucune commune » ; la mesure de charge pendant une crise est impossible | Non (périmètre CDC §10.1 : compteurs simples) |
| **E4** | Pas de clôture forcée CWS | Sans elle, un événement non clos (organisatrice partie, administrateur injoignable) bloque archives et purge ; l'alerte de dormance **ne clôt jamais** | Non : DM-065 validée (justification + audit) ; MFA non requis pour la seule clôture |
| **E5** | Sessions non maîtrisées | Postes municipaux partagés, comptes CWS à fort pouvoir (§4.3) | **Oui** : durées (§4.3) |
| **E6** | Contrôle sans consigne et non activé | Sans décision d'activation (`PUBLIC_VERIFICATION_ENABLED`), tout QR scanné répond « inconnu » ; sans consigne, les contrôleurs ne savent pas lire le résultat (vert ≠ autorisé ici) | **Oui** : périmètre d'activation du pilote ; contenu de la consigne |

---

## 4. Écarts ergonomiques et de sécurité

### 4.1 Ergonomie (aucun n'est bloquant isolément)
- Menu de 7 entrées pour un administrateur, écrans juridiques (Droits des personnes, Conservation) mêlés aux écrans de travail ; icônes redondantes ; statuts à harmoniser (commune/participation/part/laissez-passer calculé).
- Recette visuelle de Sonata et de l'écran Équipe ; validation avec de vrais agents municipaux (deux écarts du 07.2).
- Vue « Corrections d'identité », « Archives » : pas d'aide contextuelle.
- Motif de révocation commerciale non conservé (accepté, DM-121 §5).
- Accessibilité : aucune revue RGAA ni test avec lecteur d'écran (CDC §17).
- Paramètres communaux (SCR-040) : seule CWS peut modifier le contact d'une commune ; pas de logo ni de SIRET (donc pas de logo d'organisatrice sur le PDF, SFD §7.2).

### 4.2 Sécurité (hors sessions)
- Isolation inter-communes, jetons, limiteurs, en-têtes : solides et testés (identifiants forgés). Aucun défaut nouveau relevé.
- Authentification : limitation 5/15 min par couple identifiant+IP et 50/15 min globale ; réinitialisation 30 min, usage unique ; CSRF ; remember-me 30 j **optionnel**, avec écritures exigeant l'authentification complète sur `/communes`.
- **MFA absent** (prérequis d'une éventuelle impersonation, DM-061). Pour un pilote : risque accepté pour les agents communaux ; **à décider pour les comptes CWS** (§4.3).
- Pas de liste ni de révocation de sessions actives (acceptable en pilote).
- Pièces justificatives : antimalware « à définir » (CDC §9.1) non traité ; durée de conservation à décider.
- Contrôle public : exposition volontaire de nom et plaques (DM-115 §7) — base légale et information à valider **hors code**.

### 4.3 Sessions — étude (aucun développement)

**État actuel mesuré** [C/F] : sessions PHP natives sur fichiers ; `session.gc_maxlifetime=1440` s (≈ 24 min), `gc_probability=1` (nettoyage **probabiliste** : une session peut durer plus longtemps selon le trafic) ; `cookie_lifetime=0` (cookie de session navigateur) ; `cookie_secure: auto`, `httponly`, `samesite=lax`. Remember-me : 30 jours, optionnel, rouvre une session complète sans saisie. Désactivation d'un compte : effective à la requête suivante (`isEqualTo`, `UserChecker`). Retrait d'un rôle communal : relu en base à chaque requête (`MunicipalityContext::requireMembership`) [C].

**Constat important** : le brouillon de la demande **publique** et la confirmation de dépôt vivent dans cette même session. Aujourd'hui un citoyen qui laisse son formulaire ouvert plus d'environ 24 min peut perdre sa saisie (« Votre session a expiré »). Toute règle d'inactivité doit donc **ne porter que sur les utilisateurs authentifiés**, jamais sur le parcours public.

**Hypothèse à analyser : inactivité 30 min, configurable** (ex. `SESSION_IDLE_TIMEOUT_MINUTES=30`), plus **durée maximale absolue** (valeur à arbitrer, non fixée ici).

| Sujet | Analyse | Recommandation |
|---|---|---|
| Postes municipaux partagés | Une session oubliée sur un poste de mairie donne accès aux dossiers de la commune (PII). L'expiration à l'inactivité est le contrôle compensatoire principal | Inactivité obligatoire pour tous les comptes authentifiés ; remember-me **désactivé** sur ces profils ou limité (voir ci-dessous) |
| Agents mobilisés en crise | Une expiration trop courte pénalise un agent qui instruit en continu ; reconnexion fréquente = frein et tentation de contournements. Une inactivité de 30 min reste supérieure à l'intervalle entre deux actions d'un instructeur actif | 30 min par défaut, **configurable par environnement** ; avertissement 2 min avant ; reconnexion avec retour à la page |
| Formulaires en cours | Perte de saisie à l'expiration (agent : correction d'identité, délivrance directe ; CWS : formulaires Sonata) | Prévoir un message explicite, **aucune perte silencieuse** ; ne pas étendre aux brouillons publics ; les formulaires agents sont courts (étapes) : conserver la saisie côté serveur n'est pas requis en V1 |
| Révocation immédiate des habilitations | Déjà immédiate pour désactivation (`isEqualTo`) et rôles (relecture) : **ne dépend pas de la durée de session** | Aucune action ; tester qu'un cookie ancien ne survit pas à `changePassword` |
| Mécanismes Symfony | Pas de timeout natif d'inactivité dans le firewall. Possibles : (a) écouteur `kernel.request` stockant `last_activity` en session et invalidant au-delà du seuil ; (b) `framework.session.cookie_lifetime`/`gc_maxlifetime` (non déterministes, déconseillé comme seul contrôle) ; (c) durée absolue par horodatage `session_started_at` ; (d) remember-me `lifetime` à réduire ou à désactiver | Combiner (a)+(c), exclure les routes publiques (`/v`, `/demandes`, `/laissez-passer`, `/invitation`, `/mot-de-passe-oublié`) ; trancher (d) |
| Remember-me | Rouvre une session complète pendant 30 j : **contredit** toute inactivité de 30 min | **Décision** : retirer la case, ou la conserver pour les agents avec durée raccourcie, **jamais** pour CWS (déjà écartée sur `/admin`) |
| Comptes CWS | Pouvoir élevé, aucun MFA | Inactivité plus courte (ex. 15 min) et durée absolue plus courte ; MFA reporté mais tracé comme prérequis de DM-061 |
| Durée maximale | À fixer après retour d'agents ; ordre de grandeur courant : 8–12 h pour les agents (journée de crise), moins pour CWS | **Ne pas fixer avant arbitrage** |

**Décisions à prendre** : seuil d'inactivité agents / CWS ; durée absolue ; sort de remember-me ; périmètre d'exclusion (publique). Estimation : un incrément court (un écouteur, une variable d'environnement, tests) — **sans migration**.

---

## 5. Fonctionnalités volontairement reportées

| Fonctionnalité | Origine du report | Cible |
|---|---|---|
| PWA installable, scan caméra intégré, hors ligne, appareils, seuils 12/24 h | DM-115 §5, DM-116 §4 ; « lot ultérieur non planifié » | V2 (si besoin terrain avéré) |
| SMS (tous canaux) | CDC §8.1 « non connecté V1 » ; DM-117 | V2 |
| Impersonation CWS, MFA | DM-061 (exigences anticipées) | V2 |
| Journal de contrôle, compteurs de scans | DM-115 §6, DM-127 | V2 |
| Administration des référentiels, modèles d'e-mail éditables, dashboard CWS | orientation 07.1 (figés), CDC §15 | V2 |
| Compte citoyen, suivi public, cartographie, bibliothèque de zones, paiement, facturation, exports de masse, impression par lots, demandes de pièces complémentaires | SFD §1.3, CDC §15, DM-008/029/030 | V2 / hors périmètre |
| Verrou d'instruction visible (15 min) | non décidé (I4) | arbitrage |
| Révocation globale par l'organisatrice | non décidé (I5) | arbitrage |
| Mode dégradé papier 48 h | non décidé (I2) | arbitrage + validation autorités |
| Logo, SIRET, paramètres communaux éditables | non décidé | P2 / V2 |
| Redis / limiteurs multi-instance, import en masse d'équipes | DT-055 ; 07.2 | V2 |

---

## 6. Incohérences documentaires à corriger

| Id | Contradiction | Statut de la preuve | Proposition |
|---|---|---|---|
| I1 | SFD §1.1/1.2 et CDC §9.2 : **PWA hors ligne incluse en V1** ; écrans 36–39 et `PWA` dans SCR-055 ; recette REC-008 | DM-115 §5, roadmap : reportée | Réviser SFD/CDC (V1.2) : PWA « lot ultérieur non planifié » ; retirer REC-008 du pilote |
| I2 | SFD §1.1 « mode dégradé papier 48 h », §7.2, REC-007 ; CDC §17 vs PDF actuel (« seul le QR fait foi ») | DM-118 §7 **sans mention du papier 48 h** | Décision explicite (abandon ou lot) ; protocole à valider avec les autorités |
| I3 | SFD SCR-001…005 et CDC §3.1 : **inscription autonome** + souscription ; produit : ouverture manuelle par CWS | DM-121, 07.1 ; **aucune décision écrite d'abandon** | Fiche DM « ouverture par CWS en V1 » ; marquer SCR-002…005 « reportés » |
| I4 | SFD §3, §6.1, SCR-028 : **verrou d'instruction 15 min**, reprise forcée | DT-024/041 : verrous en base ; pas d'UI | Décision : abandon en V1 (conflit géré à l'enregistrement) ou lot dédié |
| I5 | SFD §2.1, SCR-033, REC-010 : **révocation globale par l'organisatrice** | DM-116 §2/4, DM-084 : autorisations des autres communes subsistent ; DM-118 : « révocation globale » = liens | Décision explicite ; supprimer REC-010 ou en faire un lot |
| I6 | CDC §9.1 / SFD §7.3 : état **orange** « indéterminé » | DM-128 : 404/erreur/terminé, aucun orange | Réviser SFD/CDC |
| I7 | CDC §9.3 « journal de contrôle minimal » vs SFD SCR-034 « pas de scan log » vs DM-127 | DM-127 tranche | Aligner le CDC |
| I8 | CDC §3.1 « statuts commune » et SFD SCR-047 (5 statuts) vs code (3) | **S2 ouverte** dans `CONTRADICTIONS.md` | Trancher (aucun statut supprimé sans validation) |
| I9 | SFD SCR-046 « dossier CWS en lecture seule », SCR-050 impersonation vs DM-005, DM-121 §1 | décisions prévalent | Marquer SCR-046 obsolète ; SCR-050 V2 |
| I10 | SFD SCR-043 « e-mail et SMS » ; SCR-056 modèles éditables vs code (e-mail seul, modèles en code) | DM-117 | Réviser |
| I11 | SFD §3 « Gestionnaire événement » / SCR-016 « gestionnaire communal » | DM-009/057 (S7 résolue) | Vérifier la terminologie dans le SFD V1.2 |
| I12 | Deux séries de recettes (REC-01…12 / REC-001…015) | table de correspondance F | Ne conserver que l'Annexe C pour la recette pilote |
| I13 | CDC §18 « PostgreSQL » vs stack MySQL 8.4 | DT-001, DT-011 | Corriger le CDC (hypothèse non contractuelle) |
| I14 | `lot-06-audit.md` §P1-4 et §6 décrit des écrans/risques désormais livrés (équipes, Sonata d'action) ; `development-roadmap.md` cite « LOT 06 » en deux sens (audit / PWA) | DM-115 amendé en forme (07.3) | Ajouter un bandeau « situation au 13 nov., mis à jour par cet audit » |

---

## 7. Proposition de périmètre figé du premier pilote

### 7.1 Principe
Un **pilote encadré** : une commune organisatrice, 1 à 3 communes participantes, quelques dizaines d'agents au plus, **réseau disponible**, assistance CWS joignable, procédure papier hors outil (pas de mode dégradé intégré). Données réelles seulement après validation juridique.

### 7.2 Inclus (à développer avant le pilote)
E1 doublons · E2 message de refus (après arbitrage) · E3 accueil et tableau de bord minimal · E4 clôture forcée CWS · E5 sessions · E6 entrée « Contrôle » et consigne · message « aucun rôle / aucune commune » · recherche par plaque/téléphone/n° (à confirmer) · regroupement du menu et harmonisation des libellés.

### 7.3 Déjà inclus (livré, à recetter)
Ouverture de commune et accès commerciaux ; invitation, équipe, remplacement d'administrateur ; événements, zones, validités ; demande publique ; instruction, délivrance directe, modifications, prolongations, révocation locale ; laissez-passer (PDF, lien, QR stable, remplacement de QR) ; contrôle web mobile ; notifications bénéficiaire par e-mail ; clôture, archives, conservation, droits (interrupteurs à 0).

### 7.4 Arbitrages requis avant développement
1. **E2** : le demandeur refusé reçoit-il un message ? Quel contenu (motif du référentiel seul ?), quel canal sans e-mail ?
2. **E6** : activation du contrôle pour le pilote (périmètre) ; contenu de la consigne (navigation privée conseillée sur appareil partagé, déjà décidée).
3. **E5** : seuils d'inactivité (agents / CWS), durée absolue, remember-me.
4. **I2, I4, I5** : sort du papier 48 h, du verrou 15 min et de la révocation globale organisatrice (reporter explicitement, ou planifier).
5. **I3** : acter par écrit que l'inscription autonome est hors V1.

### 7.5 Exclu du pilote
PWA/hors ligne, SMS, impersonation/MFA, journal de contrôle, référentiels administrables, logo/SIRET, suivi public.

### 7.6 À tester avant le pilote (recette, pas du développement)
Parcours complet depuis une commune vierge **sans fixtures** (P1-1 du 13 nov. — le test `tests/Functional/Recette` s'appuie sur des fixtures) ; recette ergonomique avec 2–3 agents ; recette Android/iPhone du contrôle (VP-01…54) ; revue accessibilité ; relecture du runbook de récupération d'accès par un tiers ; test de l'expiration de session ; envoi d'e-mails réels.

### 7.7 Relève de l'installation finale (INST)
SMTP réel, `MAILER_FROM`, `DEFAULT_URI` en https, `APP_SECRET`, clés de chiffrement ; nginx appliqué sur hôte réel (journaux masqués), DNS, certificat ; unités systemd (distributeur d'outbox, activation planifiée, dormance) ; exercice réel de sauvegarde/restauration et supervision ; test de charge et valeur définitive de `VERIFICATION_ACCESS_LIMIT` ; fiabilisation de `RevocationConcurrencyTest` et `RestoreReplayTest`.

### 7.8 Validations externes (hors code)
| Sujet | Responsable | Bloque |
|---|---|---|
| Base légale, information des personnes, texte de confidentialité du dépôt (DM-099 §4, DM-115 §7) | collectivités / DPO / conseil juridique | toute donnée réelle |
| Politique de conservation, de purge, d'effacement (DM-117, DM-120) | idem + archives | activation `RETENTION_PURGE_ENABLED`, `RIGHTS_ERASURE_EXECUTION_ENABLED` |
| Responsabilités organisatrice / participante / CWS ; contrat | CWS + communes | pilote |
| Protocole papier dégradé avec les forces de l'ordre | autorités | si I2 est retenu |
| Procédure de vérification de légitimité d'une commune (CDC §3.1) | CWS | ouverture d'une commune réelle |
| Disponibilité de la marque et du domaine « Civipass » | CWS | mise en ligne |
| Prestataire e-mail (délivrabilité, quotas) | CWS | notifications réelles |

---

## 8. Lots restants, ordonnés par dépendances

| Ordre | Lot proposé | Contenu | Dépend de | Migration ? | Taille |
|---|---|---|---|---|---|
| **08.1** | **Accueil et contexte** | E3 : tableau de bord minimal (demandes à traiter, invitations en attente, statistiques simples), message sans rôle/commune, état vide, regroupement du menu, libellés | — | non | S |
| **08.2** | **Entrée Contrôle** | E6 : page d'aide au scan et consigne, lien menu ; **décision d'activation** | arbitrage | non | XS |
| **08.3** | **Sessions** | E5 : expiration d'inactivité + durée absolue configurables, avertissement, exclusion du parcours public | arbitrage §4.3 | non | XS-S |
| **08.4** | **Doublons potentiels** | E1 : calcul à la lecture, badge non bloquant, limité aux dossiers visibles ; index éventuel | — | **peut-être** (index ; à cadrer) | S-M |
| **08.5** | **Notification de refus** | E2 : message au demandeur (canaux disponibles) | arbitrage | à cadrer (type de notification) | S |
| **08.6** | **Clôture forcée CWS** | E4 : action Sonata auditée avec justification (DM-065), suspension éventuelle | — | non | S |
| **09** | **Recette pilote locale** | parcours depuis commune vierge sans fixtures ; recette ergonomique ; accessibilité ; relecture runbooks | 08.x | non | M |
| **10** | **Revue sécurité et RGPD** (ex-LOT 08 de l'audit) | revue indépendante, bornes de pièces, antimalware, base légale | 09 | non | M |
| **INST** | **Mise en service** (hors développement local) | §7.7 | 09–10, validations §7.8 | — | — |

Ordre recommandé : **08.2 → 08.1 → 08.3 → 08.6 → 08.4 → 08.5 → 09 → 10**. 08.2 et 08.3 sont courts et débloquent des décisions ; 08.1 est le plus visible pour les agents ; 08.4/08.5 ont des conséquences de données et exigent un cadrage soigné.

---

## 9. Ce que cet audit ne prouve pas

- Aucune exigence n'a été recettée par un utilisateur réel ni sur appareil réel ; les tests automatisés démontrent des règles, pas l'ergonomie ni la lisibilité en plein soleil.
- Les exigences juridiques, de contrat et d'exploitation (§7.8) sont **hors de portée du code**.
- Les statuts « **à confirmer** » (recherche par plaque/téléphone, nombre maximal de pièces, proposition de renvoi après correction, commune par défaut, écran « aucune commune ») nécessitent une lecture plus fine avant cadrage.
- Le SFD V1.0 n'a pas été comparé phrase à phrase aux 14 documents de lots ; la matrice est ordonnée par parcours et par écran, avec les points saillants.
