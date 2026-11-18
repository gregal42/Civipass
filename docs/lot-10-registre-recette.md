# LOT 10 — Registre de recette (campagne locale du 18 novembre 2026)

Base `civipass_recette`, serveur local, données fictives, MailCatcher. Plan : [lot-10-plan-recette.md](lot-10-plan-recette.md). Environnement : [lot-10-jeux-de-donnees.md](lot-10-jeux-de-donnees.md).

## Synthèse

- Scénarios : **113** — Réussi : 105 · Non applicable : 1 · Non exécuté : 5 · Contradiction documentaire : 2.
- P1 : **78** — Réussi : 74 · Non exécuté : 2 · Contradiction documentaire : 2.
- Recettes conditionnelles : 6/6 exécutées et réussies. Matrice rejouée : 1 853 tests, 0 échec. `bin/qa full` : vert (deux passages, avant et après la campagne complète). E2E navigateur du contrôle hors connexion : 11/11.
- Aucune faille d'isolation ni contournement de permission. Aucune anomalie produit ouverte.

## Six recettes conditionnelles (variables non versionnées, poste local)

| Test | Résultat |
|---|---|
| MailCatcherRecetteTest : messages via le vrai mailer | Réussi |
| MailCatcherRecetteTest : panne SMTP, même identifiant et même lien | Réussi |
| MailCatcherRecette055Test : messages prioritaires sans donnée sensible | Réussi |
| MailCatcherRecette055Test : panne SMTP sur le message QR | Réussi |
| NotificationScreenTest : rendu navigateur des pages de notification | Réussi |
| MobileSnapshotRecetteTest : écrans pour le rendu mobile | Réussi |

6 tests, 181 assertions. Les tests d'exploitation Linux (OpsUnitsTest, NginxOperationsTest : 78 tests) ont tourné dans le conteneur Linux du projet, pas sur macOS ; ils ne prouvent rien sur le serveur de production.

## Parcours « Inondation » (P0 → P14)

P0 à P10 et P12 rejoués sur la base de recette. P11 : voir la contradiction R-CLO-02. P13 rejoué sur un second événement (clôture forcée). Détail : matrice ci-dessus. Deux événements, trois communes, 16 demandes, 8 laissez-passer.

## Invitation : validité du lien et durée de l'invitation (point UX O-3)

Constat : il n'y a pas d'incohérence de fond. L'invitation d'une commune à un événement **n'expire pas** (DM-086 ; elle ne peut plus être acceptée après la clôture de l'événement) ; seul le **lien sécurisé** de l'e-mail a une durée : 14 jours pour le contact, 3 jours pour un administrateur (`InvitationLink`, paramètres techniques). Un lien expiré se réémet par « Renvoyer l'invitation » ; la page de lien expiré le dit déjà. Le défaut est de **formulation** : l'écran dit « n'expire pas » et l'e-mail « valable jusqu'au… » sans relier les deux.

Textes corrigés **proposés, non appliqués** (à valider) :

| Où | Texte actuel | Proposition |
|---|---|---|
| Page « Inviter une commune » | « L'invitation n'expire pas. Un e-mail contenant un lien sécurisé est envoyé… » | « L'invitation reste en attente jusqu'à la clôture de l'événement. Le lien sécurisé envoyé par e-mail est valable 14 jours ; vous pourrez en envoyer un nouveau à tout moment. » |
| Fiche de l'événement (commune invitée) | « L'invitation n'expire pas. Le refus d'une invitation n'est pas prévu en V1. » | « L'invitation reste en attente jusqu'à la clôture de l'événement. Elle ne peut pas être refusée dans cette version. » |
| E-mail d'invitation | « …ouvrez ce lien (valable jusqu'au JJ/MM/AAAA) : » | « …ouvrez ce lien (valable jusqu'au JJ/MM/AAAA ; passé cette date, demandez un nouveau lien à la commune organisatrice : l'invitation, elle, reste en attente) : » |

## Limiteur de connexion et sessions HTTP de recette

Le limiteur est conservé tel quel (5 tentatives / 15 min par identifiant et IP ; plafond global 50 connexions / 15 min / IP, qui survit à une connexion réussie). Il m'a bloqué une fois : trop de connexions scriptées depuis la même IP, message « réessayer dans 10 minutes », levé à l'expiration de la fenêtre sans contournement. Ensuite, le pilote HTTP réutilise des cookies de session sur disque (`var/lot10/campaign/jars/`, ignoré par Git) : aucun nouveau blocage. Les connexions par navigateur (session, captures) restent des connexions réelles.

## Scénarios « Sans objet » (hors périmètre V1)

| ID | Justification | Décision |
|---|---|---|
| R-COL-02 | Une invitation inter-communes n'est pas déclinable en V1 ; l'écran l'affiche | DM-037 (UI-02 D7), DM-056 |

Les autres scénarios non exécutés ne sont pas « sans objet » : ils sont reportés (09.7, textes à valider, VPS pour le runbook de vérification publique, mesure de collisions en pilote) et restent à faire.

## Registre des anomalies et observations

| ID | Gravité | Scénario | Attendu / observé | Correctif | Nouveau test |
|---|---|---|---|---|---|
| ANO-001 | Mineure (environnement) | P0 | 500 après inscription : cache `dev` antérieur à la route `app_municipal_home` | `cache:clear` sur la recette ; aucun code modifié | Rejoué : OK |
| ANO-002 | Mineure (environnement de recette) | R-SES-03 | Sans `APP_DEBUG`, le serveur PHP intégré ne sert pas `/assets/` (CSS, JS) : écrans non stylés, avertissement de session absent | Instance de recette en mode debug pour les captures et la session | Rejoué : OK |
| CD-1 | Documentaire | R-CLO-02, R-NEG-11, P11 | Le plan attend un refus ; DM-092 (validée) prévoit la clôture automatique des participations | Aucun ; plan à corriger | n/a |
| CD-2 | Documentaire | R-COL-05 | Le plan suppose que A suspend B ; le produit réserve à chaque commune sa propre participation | Aucun ; plan à corriger | n/a |

Aucune anomalie produit ouverte. Aucun correctif fonctionnel ni push de développement.

## Observations UX et accessibilité (non bloquantes, à arbitrer)

- O-1 : liste d'événements et tableau « Droits des personnes » débordent horizontalement en largeur mobile (585 et 701 px pour 500).
- O-2 : le dépôt public pose un cookie de session anonyme (brouillon du formulaire). Sans session authentifiée, mais le plan R-SES-05 disait « aucune session créée » : à reformuler ou à confirmer.
- O-3 : l'écran d'invitation affiche « L'invitation n'expire pas » alors que l'e-mail indique un lien valable 14 jours.
- O-4 : les pages de vérification publique n'ont pas de titre de niveau 1.
- O-5 : la barre de navigation mobile occupe trois lignes et masque une partie du contenu ; un « · » reste après le nom de la commune dans l'en-tête.
- O-6 : un lien de réinitialisation invalide répond 200 (page « Lien invalide »), une invitation invalide répond 410 : réponses hétérogènes.
- O-7 : le message « événement clos » (accès aux dossiers fermé après clôture) est clair, mais les agents perdent toute consultation des demandes d'un événement clos ; à confirmer comme voulu.
- Points positifs : messages d'erreur explicites (422 avec cause), confirmations en deux temps pour les modifications de droits, aucune écriture partielle, textes cohérents entre écran et e-mails.

## Limites et réserves

- **09.7 : Non exécutée — recette réelle à réaliser.** Cette réserve interdit de déclarer Civipass prêt pour le pilote.
- R-NOT-07 et R-OFF-06 : textes provisoires, non validés pour un envoi réel.
- Serveur de recette mono-processus : la concurrence réelle repose sur les tests PHPUnit dédiés.
- Poste de recette ≠ smartphone ; pas de HTTPS ; MailCatcher n'éprouve pas la délivrabilité.
- Jeton QR lu en base, image QR non décodée ; second super-administrateur créé par SQL local (aucun chemin applicatif).
- Plafond de connexion global 50 / 15 min / IP : à garder en tête pour une mairie derrière une seule adresse.
- 3 avis de dépréciation PHPUnit 14 sur des classes de support de test (dette, non traitée).

## Matrice

| ID | P | Statut | Observé / preuve |
|---|---|---|---|
| R-CWS-01 | P1 | Réussi | Console : super-admin créé, audit USER_SUPER_ADMIN_CHANGED ; aucune commune implicite (R-CWS-04). Tests CreateSuperAdminCommandTest/AdminAccessTest rejoués. |
| R-CWS-02 | P1 | Réussi | UI Sonata : Aubenas, Privas, Annonay ouvertes depuis le référentiel, audit MUNICIPALITY_OPENED_BY_CWS ×3. |
| R-CWS-03 | P1 | Réussi | Invitations reçues dans MailCatcher, inscription ×3 ; lien rejoué → 410. |
| R-CWS-04 | P1 | Réussi | cws-root : 6 URL communales → 404. |
| R-CWS-05 | P2 | Réussi | Sonata : création/édition/suppression/batch → 404 ; admin communal 403 ; anonyme 302 login. |
| R-CWS-06 | P3 | Réussi | Attribution, double attribution refusée, révocation → création d’événement 403, réattribution OK. |
| R-ADM-01 | P1 | Réussi | 4 invitations (2 instructeurs, gestionnaire, instructeur B) acceptées par lien à usage unique. |
| R-ADM-02 | P2 | Réussi | Cumul instructeur+gestionnaire : droits = union ; retrait d’un rôle → effet immédiat (403). |
| R-ADM-03 | P1 | Réussi | Désactivation du seul admin de B refusée (message explicite) ; auto-retrait forgé sans effet ; auto-rétrogradation 422. |
| R-ADM-04 | P1 | Réussi | Compte désactivé : session existante → /login, reconnexion refusée. |
| R-ADM-05 | P2 | Réussi | Mot de passe réinitialisé par lien MailCatcher ; rejeu → « Lien invalide ». |
| R-ADM-06 | P2 | Réussi | Compte b-instr rattaché à Aubenas et Privas : sélection de commune, droits distincts, demande A via B → 404. |
| R-EVT-01 | P1 | Réussi | Événements EVT-2026-000001 et 000002 créés, références uniques. |
| R-EVT-02 | P1 | Réussi | Brouillon → actif → suspendu → réactivé → clôturé rejoués à la main (événement 3) : suspension = droits « suspendus », dépôt public fermé, réactivation rétablit les droits (DM-056 A6.2) ; transitions invalides : EventLifecycleServiceTest rejoué. |
| R-EVT-03 | P2 | Réussi | ScheduledActivationTest rejoué (A). |
| R-EVT-04 | P3 | Réussi | EventDormancyTest rejoué (A). |
| R-EVT-05 | P1 | Réussi | Liste de B : événement de A visible comme « Invitée » ; liste de C : absent. |
| R-COL-01 | P1 | Réussi | A invite B (e-mail MailCatcher), B accepte ; participation active. |
| R-COL-02 | P2 | Non applicable | Refus d’une invitation non prévu en V1 : « invitation non déclinable » (UI-02 D7, DM-037 ; DM-056). L’écran le dit (« Le refus d’une invitation n’est pas prévu en V1 »). Hors périmètre V1. |
| R-COL-03 | P1 | Réussi | B crée ses zones, décide, révoque, suspend, reprend et clôture sa participation seule ; zones de A en lecture seule (DM-084). |
| R-COL-04 | P1 | Réussi | 36 contrôles d’identifiants forgés (R-NEG-01/02/03, R-CWS-04) refusés. |
| R-COL-05 | P2 | Réussi | Comportement réel : chaque commune suspend sa propre participation (A n’a aucun levier sur B). Plan imprécis. Suspension/reprise : droits de B suspendus puis rétablis, droits de A inchangés. Écriture sur participation clôturée → 403. Participation suspendue : création de zone autorisée, aucune zone opérationnelle, révocation de droits refusée (409 explicite) — conforme à DM-066 ; rejoué sur l’événement 3. |
| R-COL-06 | P3 | Réussi | NationalInvitationSearchTest rejoué ; recherche « Privas » rejouée en UI. |
| R-ZON-01 | P1 | Réussi | Zones créées/validées ; type invalide → 422 ; 2e « Commune entière » refusée. |
| R-ZON-02 | P2 | Réussi | Rue du Port à validité restreinte : droit borné au 22/11 20:00 sur la vérification. |
| R-ZON-03 | P1 | Réussi | Zone suspendue : vérification « Zone suspendue », zone absente du dépôt public, réactivation rétablit le droit. |
| R-ZON-04 | P3 | Réussi | EventZoneBulkServiceTest rejoué (A). |
| R-ZON-05 | P2 | Réussi | EventZoneConcurrencyTest rejoué (A). |
| R-DEM-01 | P1 | Réussi | Dépôts publics : références DEM-2026-0001…, accusés reçus par e-mail. |
| R-DEM-02 | P1 | Réussi | Plaques saisies et normalisées (AA-101-AA…). |
| R-DEM-03 | P2 | Réussi | .exe, 11 Mo et faux PDF refusés (422, messages explicites) ; PDF valide accepté. |
| R-DEM-04 | P1 | Réussi | Double POST simultané du récapitulatif → une seule demande. |
| R-DEM-05 | P2 | Réussi | Dossier professionnel catégorie « Professionnels ». |
| R-DEM-06 | P2 | Réussi | Délivrance par agent : agent émetteur tracé. |
| R-DEM-07 | P1 | Réussi | Après clôture : formulaire « demandes plus ouvertes ». Avant ouverture (brouillon) : 404 uniforme, aucune étape accessible ; pendant suspension : formulaire fermé. |
| R-DEM-08 | P2 | Réussi | Correction du nom avec confirmation « même personne » ; version périmée → 409 ; B-instr sur dossier A → 404. |
| R-INS-01 | P1 | Réussi | Listes filtrées par commune : A 7→9 dossiers, B 3. |
| R-INS-02 | P1 | Réussi | Approbations 24/48/72 h et jusqu’à clôture ; autorisation propre à la commune. |
| R-INS-03 | P1 | Réussi | 3 refus avec motif ; décision définitive. |
| R-INS-04 | P1 | Réussi | Approbation et refus simultanés : une seule décision, l’autre informé. Rejoué sur 6 paires (1 + 5) : une seule décision à chaque fois, message du perdant clair. Serveur mono-processus : sérialisé ; concurrence réelle couverte par MunicipalDecisionConcurrencyTest rejoué. |
| R-INS-05 | P1 | Réussi | Gestionnaire seul (A-lect) : pages et POST décision → 403. |
| R-INS-06 | P2 | Réussi | MunicipalApprovalWindowCompatibilityTest rejoué (A). |
| R-INS-07 | P2 | Réussi | Délivrance directe DEM-2026-0014, durée 24 h. |
| R-INS-08 | P3 | Non exécuté | DM-137 : compréhension du message de conflit vérifiée (6 cas) ; fréquence réelle des collisions non mesurable en recette locale à un opérateur : à mesurer en pilote. |
| R-DBL-01 | P1 | Réussi | « Doublon possible » avec lien DEM-0001↔0004. |
| R-DBL-02 | P1 | Réussi | Même identité déposée pour A (DEM-0016) et pour B seul (DEM-0017) : A voit son doublon interne (0018, 0020) mais pas celui de B ; B n’a aucun signal. Fiche partagée 0002 vue par B sans les zones de A. |
| R-DBL-03 | P1 | Réussi | Les deux dossiers Martin approuvés malgré le signal. |
| R-DBL-04 | P3 | Réussi | Conforme à ecarts-techniques-connus.md : accents/casse détectés, « famille » (même téléphone) signalée, noms/prénoms inversés non détectés. |
| R-LP-01 | P1 | Réussi | LP-2026-0001…0008 délivrés, notifications regroupées (1 message pour Hugo A+B). |
| R-LP-02 | P1 | Réussi | Lien bénéficiaire, PDF 200 application/pdf, QR = jeton opaque 32 car. sans donnée personnelle. Le contenu de l’image QR n’a pas été décodé (limite). |
| R-LP-03 | P1 | Réussi | Retrait de zone : effet immédiat à la vérification. |
| R-LP-04 | P1 | Réussi | Remplacement de plaque : effet immédiat. |
| R-LP-05 | P1 | Réussi | Raccourcir et prolonger rejoués ; expiration et reprise : tests rejoués. |
| R-LP-06 | P1 | Réussi | Instructeur refusé (409) ; admin remplace le QR ; ancien QR → 410 « QR remplacé ». |
| R-LP-07 | P1 | Réussi | B révoque : B « Révoquée », A « Valide actuellement », 2e révocation refusée. |
| R-LP-08 | P1 | Réussi | PermitRightsEvaluatorTest rejoué. |
| R-VP-01 | P1 | Réussi | « Droits actifs », périmètre minimal, no-store, noindex, aucun cookie. |
| R-VP-02 | P1 | Réussi | Jeton inconnu → 404 neutre « non reconnu ». |
| R-VP-03 | P1 | Réussi | PublicVerificationTimeTest rejoué ; période raccourcie reflétée. |
| R-VP-04 | P1 | Réussi | Remplacé → 410 ; révoqué (B) → « Révoquée ». |
| R-VP-05 | P1 | Réussi | Événement clos → « Événement terminé… aucune information ». |
| R-VP-06 | P1 | Réussi | Interrupteur à 0 : PublicVerificationSecurityTest rejoué. |
| R-VP-07 | P2 | Réussi | Limiteur : PublicVerificationSecurityTest rejoué (A). |
| R-VP-08 | P2 | Non exécuté | Le runbook exige un VPS HTTPS dédié, reporté par décision du 17/11 : hors périmètre local. Équivalent fonctionnel local : R-VP-01 à 05 rejoués. |
| R-OFF-01 | P1 | Réussi | ControlHttpTest/ControlActivationServiceTest rejoués ; route ouverte à 1 sur le poste. |
| R-OFF-02 | P1 | Réussi | Code d’activation créé sur la base de recette (une seule fois affiché, accès instructeur 403, C 404, code invalide 403) ; cycle complet appareil par e2e navigateur sur données de test. |
| R-OFF-03 | P1 | Réussi | ControlDatasetTest et tests JS rejoués. |
| R-OFF-04 | P2 | Réussi | bin/qa e2e : 11 étapes, Chrome réel, réseau coupé, PWA/Service Worker (poste, pas un smartphone). |
| R-OFF-05 | P1 | Réussi | Horloge +7 h, +13 h, recul : e2e navigateur et tests JS. |
| R-OFF-06 | P2 | Non exécuté | Texte « Contrôle » provisoire, non validé par le métier ; ControlGuideTest rejoué. |
| R-OFF-07 | P1 | Non exécuté | Non exécutée — recette réelle à réaliser (smartphones réels, HTTPS). |
| R-NOT-01 | P1 | Réussi | Notifications LP reçues (MailCatcher) ; MailCatcherRecetteTest + 055 : 4/4. |
| R-NOT-02 | P1 | Réussi | Panne SMTP simulée : « retry », puis envoi au rétablissement ; MailCatcher recettes de panne ✔. |
| R-NOT-03 | P1 | Réussi | Refus Sonia reçu, motif présent ; motif « duplicate » : message sans motif. |
| R-NOT-04 | P1 | Réussi | Réessai observé ; barème 1/5/15/60/240 et abandon : RefusalNoticeTest rejoué. |
| R-NOT-05 | P1 | Réussi | Karim sans e-mail : listé « Aucune adresse e-mail », marqué informé. |
| R-NOT-06 | P1 | Réussi | RefusalNoticeTest rejoué (interrupteur à 0 = aucun envoi). |
| R-NOT-07 | P1 | Non exécuté | Libellé juridique du refus provisoire : validation métier attendue avant envoi réel. |
| R-NOT-08 | P2 | Réussi | OutboxPurgeTest/SecretPurgeTest rejoués. |
| R-SES-01 | P1 | Réussi | Test unitaire rejoué + navigateur (voir R-SES-03). |
| R-SES-02 | P1 | Réussi | SessionLifetimeTest rejoué. |
| R-SES-03 | P2 | Réussi | Navigateur réel (Chrome piloté, délai minimal produit 300 s) : avertissement à 180 s avec compte à rebours, sonde sans prolongation (119→114 s), « Rester connecté » prolonge (299 s), expiration → /login, page protégée redirigée. Captures R-SES-03 et R-SES-01. |
| R-SES-04 | P1 | Réussi | AuthenticationTest rejoué (cookie forgé ignoré). |
| R-SES-05 | P2 | Réussi | /v : aucun cookie. Dépôt public : un cookie de session anonyme (brouillon du formulaire) est posé, sans session authentifiée (SessionExpiryTest). Voir observation O-2. |
| R-SES-06 | P1 | Réussi | Opérateur sans habilitation : 404 ; habilité : clôture ; trace CWS_PERMISSION_GRANTED. |
| R-SES-07 | P2 | Réussi | Plafond global 50 connexions/15 min/IP atteint en campagne : blocage conforme. |
| R-CLO-01 | P1 | Réussi | Clôture de l’événement 1 : événement clos, participations clôturées (DM-092). |
| R-CLO-02 | P1 | Contradiction documentaire | Le plan attend un refus si B est participante ; DM-092 (validée) prévoit la clôture automatique de toutes les participations. Comportement conforme à DM-092 ; plan à corriger. |
| R-CLO-03 | P1 | Réussi | Zones, décisions, droits, QR, dépôt : 403/410/fermé ; vérification « événement terminé ». |
| R-CLO-04 | P2 | Réussi | EventClosureNotificationsTest/ProducersRaceTest rejoués. |
| R-CLO-05 | P1 | Réussi | Clôture forcée par cws-support : refs/mot de passe/motif invalides sans effet, clôture, audit SUPPORT, e-mail aux admins sans motif. |
| R-CLO-06 | P1 | Réussi | cws-root sans habilitation : 404. |
| R-AUD-01 | P1 | Réussi | 152 entrées d’audit, 47 types d’action, horodatage UTC, acteur/origine. |
| R-AUD-02 | P1 | Réussi | Purge en simulation : 0 candidat ; événements, 16+ demandes, laissez-passer conservés. |
| R-AUD-03 | P1 | Réussi | DossierPurgeTest/RegisterProofTest rejoués (échéance non atteinte en recette). |
| R-AUD-04 | P2 | Réussi | LegalHoldTest/ArchiveAccessTest rejoués. |
| R-AUD-05 | P2 | Réussi | Droit d’accès : attestation, décision, export ZIP journalisé ; accès croisé 404. |
| R-AUD-06 | P2 | Réussi | RestoreCommandsTest rejoué ; runbook restauration non déroulé. |
| R-AUD-07 | P1 | Réussi | doctrine:schema:validate OK ; app:ops:integrity-check : 0 anomalie bloquante. |
| R-NEG-01 | P1 | Réussi | 12 forgeries A↔B refusées ; fiche partagée sans zones de A. Visualisation d’une zone de B par A autorisée en lecture (DM-084). |
| R-NEG-02 | P1 | Réussi | C-admin : 9 accès → 404. |
| R-NEG-03 | P1 | Réussi | Gestionnaire/instructeur : 9 tentatives → 403. |
| R-NEG-04 | P1 | Réussi | Doublon signalé sans blocage. |
| R-NEG-05 | P1 | Réussi | Dépôt après clôture refusé. |
| R-NEG-06 | P1 | Réussi | Cf. R-ZON-03. |
| R-NEG-07 | P1 | Réussi | Cf. R-INS-04. |
| R-NEG-08 | P1 | Réussi | Cf. R-VP-02 à 04. |
| R-NEG-09 | P1 | Réussi | Cf. R-NOT-02/04. |
| R-NEG-10 | P1 | Réussi | Expiration : navigateur et SessionExpiryTest rejoués ; aucune écriture partielle. |
| R-NEG-11 | P1 | Contradiction documentaire | Cf. R-CLO-02 (DM-092). |
| R-NEG-12 | P1 | Réussi | Invitation rejouée 410, lien de réinitialisation rejoué refusé, code d’activation invalide 403. |
| R-NEG-13 | P2 | Réussi | Balises <script>/onerror stockées, échappées à l’affichage. |
| R-NEG-14 | P2 | Réussi | Nom 300 car., plaque ZZZ, fichiers invalides : 422. |
