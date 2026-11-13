# LOT 06 — Audit global et préparation de la V1 pilote

**Date** : 13 novembre 2026 · **Branche** : `develop` · **Nature** : audit seul (aucun code métier modifié, aucune migration, aucun push, aucun timer installé).
Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

**Niveaux de preuve utilisés**
- **[C]** prouvé par le code (lecture des routes, contrôleurs, services, templates) ;
- **[T]** couvert par un test automatisé existant (nom cité) ;
- **[F]** vérifié en fonctionnement dans cet audit (commande exécutée) ;
- **[M]** reste à tester manuellement ou sur appareil réel.
Aucune fonctionnalité n'est déclarée « opérationnelle » sur la seule foi de la documentation.

---

## 1. Synthèse exécutive

Le cœur opérationnel **à l'intérieur d'une commune déjà amorcée** est riche et solide : événements, zones et validités, demande citoyenne publique, instruction, délivrance, modification/prolongation/révocation des droits, clôture, archives, conservation, droits des personnes, exploitation. 257 fichiers de tests, isolation par commune systématique.

**Mais le parcours complet n'est pas réalisable sans intervention technique.** Les trois premiers maillons du parcours cible (§4) n'ont **aucune interface** :

1. **Aucune commune ne peut être créée** par un écran ou une commande : `MunicipalityManager::create()` n'est appelé que par les tests. La seule création en production est l'inscription automatique d'une commune *invitée* par une commune organisatrice existante.
2. **Aucun accès commercial/abonnement ne peut être attribué** : `MunicipalityCommercialAccess` n'est instancié nulle part dans `src/`. Or créer un événement exige un droit valide (`EventCreationPolicy`, DM-070/076). **Sans SQL, aucun événement ne peut donc être créé.** DM-078 prévoyait ces écrans Sonata « avant les premières démonstrations commerciales ».
3. **Aucun MUNICIPALITY_ADMIN ne peut être créé ni remplacé** pour la commune organisatrice : le seul chemin (lien d'invitation → `AdministratorEnrollment`) naît d'une *invitation à un événement*, donc d'une commune qui a déjà un administrateur et un événement. Problème de l'œuf et de la poule pour la première commune.
4. **Aucune gestion d'équipe communale** : pas d'écran pour inviter un agent, attribuer `INSTRUCTOR`/`EVENT_MANAGER`, modifier ou retirer une habilitation, désactiver un collaborateur. `MembershipManager::grant/changeRoles/deactivate` et `UserManager::deactivate` existent mais **n'ont aucun point d'appel** hors tests. Un administrateur communal ne peut donc pas constituer son équipe : sans équipe, ni instruction (rôle `INSTRUCTOR` obligatoire, DM-107) ni gestion d'événement déléguée.
5. **Sonata est un tableau de consultation** (3 listes en lecture seule : communes, comptes, appartenances). Aucune action de création, d'activation/suspension, de révocation, de référentiel. Les services d'écriture existent mais ne sont reliés à rien.
6. **Contrôle QR** : le QR encode une URL publique `/v/{jeton}` lue par n'importe quelle application caméra. **Pas de PWA** (ni manifeste, ni service worker, ni caméra intégrée), **pas de hors-ligne**, et la route est **désactivée par défaut** (`PUBLIC_VERIFICATION_ENABLED=0`) : tant que l'interrupteur est à 0, tout QR scanné répond « inconnu » (404).

**Verdict** : *en l'état, une commune pilote ne peut pas être ouverte sans SQL ni console PHP* (P0-1 à P0-4). Après ces correctifs, le produit est exploitable en pilote encadré ; la PWA hors ligne n'est pas nécessaire pour un pilote si le réseau est correct (P1/P2 selon décision).

**Résultats des contrôles exécutés** : voir §10 (qualité complète, vérification documentaire, validation Doctrine).

---

## 2. Matrice des fonctionnalités

Légende : ✅ opérationnel · 🟡 partiel · ❌ absent · ❓ non vérifié.

### 2.1 Administration CWS (Sonata)

| Fonction | État | Preuve |
|---|---|---|
| Accès au back-office réservé aux super-admins en authentification complète (pas via remember-me) | ✅ | `security.yaml` access_control `/admin` ; [T] `SonataBackOfficeTest::testASessionThatIsOnlyRememberMeCannotReachTheBackOffice`, `AdminAccessTest` |
| Consultation communes / comptes / appartenances (filtres, tri) | ✅ | `src/Admin/{Municipality,User,Membership}Admin.php` ; [T] `testSearchFiltersAndSortingWork` |
| Création d'une commune | ❌ | `ReadOnlyAdmin` : `clearExcept(['list','show'])` ; `MunicipalityManager::create` sans appelant hors tests ; [T] `testWriteRoutesDoNotExistEvenForASuperAdmin` (le verrou est voulu, le remplaçant manque) |
| Modification, activation, suspension d'une commune | ❌ | `MunicipalityManager::changeStatus/updateContact` sans appelant UI |
| Création du premier MUNICIPALITY_ADMIN | ❌ | seul chemin : lien d'invitation d'événement (`InvitationLinkController`, `AdministratorEnrollment`) |
| Invitation sécurisée + initialisation du compte administrateur | 🟡 | ✅ pour une commune *invitée* à un événement (jeton, limiteur IP, consentement de représentation) ; [T] `InvitationLinkPageTest`, `EnrollFirstAdministratorTest` ; ❌ pour la commune organisatrice |
| Gestion des comptes (désactivation, rôles CWS) | ❌ | `UserManager::deactivate/grantSuperAdmin` sans appelant UI ; aucune action Sonata |
| Révocation / remplacement d'un administrateur communal | ❌ | `MembershipManager::changeRoles/deactivate` + `LastMunicipalityAdminGuard` (garde-fou testé) mais aucun écran ; l'enrôlement est limité au *premier* administrateur |
| Accès commerciaux (attribution, révocation, historique) | ❌ | DM-078 ; aucune écriture, aucune entité instanciée |
| Référentiels métier (motifs, catégories, motifs de refus, types d'événement) | 🟡 | semés par migration / `app:event-types:initialize` / `RequestReferentialInitializer` ; **aucune administration** malgré DM-097/098 « référentiel administrable » |
| Séparation administration technique / dossiers opérationnels | ✅ | DM-005 ; super-admin sans rôle communal implicite ; [T] `testSuperAdminHasNoImplicitCommunalRoleAndTheLot01SupervisionIsUnchanged`, `AuditLogReader` |
| Clôture forcée par CWS (DM-065), impersonation (DM-061) | ❌ | aucune route ; DM-061 est une exigence « anticipée », DM-065 validée |
| Récupération d'accès : mot de passe oublié | ✅ | `/forgot-password`, `/reset-password/{token}` ; [T] `PasswordResetFlowTest`, `PasswordResetServiceTest` ; ❓ dépend de la configuration SMTP |
| Récupération d'accès : super-admin | 🟡 | `app:super-admin:create` (refuse s'il existe un super-admin actif) ; pas de procédure documentée si l'unique compte est verrouillé/désactivé (voir `docs/runbooks/incident-securite.md` [M] à relire) |
| Import du référentiel national des communes | ✅ | `app:communes:sync` (nécessaire pour inviter) ; [F] commande listée |
| Ouvrir une commune pilote sans SQL | ❌ | cf. §1 |

### 2.2 Autonomie des communes

| Fonction | État | Preuve |
|---|---|---|
| Inviter des collaborateurs | ❌ | aucune route `/communes/{id}/utilisateurs|equipe` (`routes` §3) ; navigation sans entrée « Équipe » (`ShellContextFactory`) |
| Attribuer / modifier / retirer des rôles | ❌ | `MembershipManager` sans UI |
| Invitations en attente (collaborateurs) | ❌ | — |
| Invitations *intercommunales* en attente (renvoi, suivi) | ✅ | `app_municipal_event_invitation_resend`, `InvitationDeliveryReader` ; [T] `InvitationEmailPageTest` |
| Désactiver l'accès d'un collaborateur | ❌ | `MembershipManager::deactivate` sans UI |
| Événements : création, modification, cycle de vie | ✅ (sous réserve du droit commercial, P0-2) | `EventFormController`, `EventLifecycleController` ; [T] `EventFormPageTest`, `EventLifecyclePageTest` |
| Zones et validités (créneaux, opérations groupées) | ✅ | `EventZoneController`, `ZoneValidityController` ; [T] `EventZonePageTest`, `EventZoneValidityPageTest` |
| Isolation inter-communes | ✅ | `MunicipalityContext::requireMembership`, refus uniforme ; [T] tests avec identifiants forgés (`ArchiveAccessTest`, `EventAccessIntegrationTest`, `PermitRightsScreenTest`…) |
| Les agents communaux travaillent hors Sonata | ✅ | `/admin` réservé `ROLE_SUPER_ADMIN` ; interface `/communes/{id}/…` |
| Tableau de bord communal | ❌ | `/` redirige vers le sélecteur puis la liste des événements ; `dev_ui_dashboard` est une maquette (`/dev/ui`) |

### 2.3 Parcours métier (détail §4)

| Étape | État |
|---|---|
| 1. CWS crée commune + administrateur | ❌ |
| 2. Activation du compte administrateur | 🟡 (invitation d'événement seulement) |
| 3. Invitation de collaborateurs | ❌ |
| 4. Création d'événement et zones | 🟡 (bloqué par l'absence de droit commercial) |
| 5. Invitation d'autres communes | ✅ |
| 6. Demande citoyenne publique | ✅ (`/demandes/{eventReference}/…`, [T] `Functional/Request`) |
| 7. Instruction | 🟡 (nécessite `INSTRUCTOR`, impossible à attribuer par l'UI) |
| 8. Délivrance du laissez-passer | ✅ (approbation, délivrance directe, lien bénéficiaire, PDF) |
| 9. Contrôle du QR | 🟡 (web mobile public, désactivé par défaut, pas de PWA) |
| 10. Modifier / prolonger / révoquer | ✅ (`/droits/*`, remplacement de QR) |
| 11. Clôture de l'événement | ✅ (+ archives, conservation, dormance) |

### 2.4 QR, PWA, hors ligne

| Question | Réponse | Preuve |
|---|---|---|
| Manifeste PWA | **Non** | aucun `manifest*` dans `public/`, `templates/`, `assets/` ([F] recherche) |
| Service worker | **Non** | aucune occurrence `serviceWorker` hors Sonata |
| Installable sur smartphone | **Non** (ajout à l'écran d'accueil possible comme simple raccourci, sans mode standalone) | idem |
| Scan caméra intégré | **Non** : le QR est une URL lue par l'appli caméra du téléphone | aucun `getUserMedia`/`BarcodeDetector`/lib de décodage ; `templates/permit/verification.html.twig` : « SANS JavaScript » |
| Contrôle hors connexion | **Non** | la page est rendue serveur et exige le réseau |
| Données stockées localement | Aucune | pas de `localStorage`/IndexedDB dans le code applicatif ; `PublicVerificationHeadersListener` (en-têtes de non-mise en cache) |
| Révocations / expirations | Évaluées **à chaque consultation** sur le serveur (« Droits valables au moment de cette vérification », bouton Actualiser) | `PublicVerificationController`, `PublicVerificationReader` ; [T] `PublicVerificationScenariosTest` |
| État de l'interrupteur | **0** : route répond 404 identique à un jeton inconnu | `PublicVerificationController::show`, `config/services.yaml` |

Distinction : **web mobile** = oui (page lisible dès 390 px, sans JS) ; **PWA installable** = non ; **contrôle réellement utilisable hors ligne** = non (et risqué par conception : une copie locale ne verrait ni révocation ni remplacement de QR).

---

## 3. Preuves techniques (échantillon utile à la décision)

- **Routes** : 101 routes applicatives hors Sonata/dev ([F] `bin/console debug:router`). Aucune route n'existe pour : création de commune, équipe/membres, accès commerciaux, référentiels, tableau de bord, contrôle authentifié.
- **Sonata** : `ReadOnlyAdmin` supprime create/edit/delete/batch/export ; `ReadOnlySecurityHandler` refuse toute écriture ; 3 admins (`MunicipalityAdmin`, `UserAdmin`, `MembershipAdmin`).
- **Services d'écriture sans point d'appel UI** : `MunicipalityManager` (create, changeStatus, updateContact), `MembershipManager` (grant, changeRoles, deactivate), `UserManager` (createUser, grant/revokeSuperAdmin, deactivate/activate). Seules utilisations `src/` : `AdministratorEnrollment`/`InvitationLinkController` (enrôlement du premier administrateur d'une commune invitée), `CreateSuperAdminCommand`, `ArchiveDesignationService`, `MunicipalityRegistrar`.
- **Droits commerciaux** : `grep MunicipalityCommercialAccess` → persistance et lecture uniquement.
- **Commandes console** : 27 commandes `app:*` (exploitation, conservation, notifications) ; aucune de création de commune, d'administrateur ni de droit commercial.
- **Navigation** (`ShellContextFactory`) : Événements, Demandes, Nouvelle demande, Corrections d'identité, Archives, Conservation des données, Droits des personnes. Aucune entrée Équipe, Référentiels, Contrôle.
- **Tests** : 257 fichiers (Unit/Integration/Functional) ; couverture des permissions et de l'isolation forte ; le défaut est l'absence de **scénario bout-en-bout** partant d'une commune vierge (`tests/Functional/Recette` fonctionne à partir de fixtures `ServiceWorld`, qui contournent précisément les maillons absents).

---

## 4. Parcours complet : ruptures

| Rupture | Manipulation technique actuelle |
|---|---|
| R1 — Pas de création de commune | INSERT SQL ou script PHP appelant `MunicipalityManager::create` |
| R2 — Pas de droit commercial | INSERT SQL dans la table des accès commerciaux |
| R3 — Pas de premier administrateur | INSERT SQL (compte + appartenance `MUNICIPALITY_ADMIN`) ou script `MembershipManager::grant` |
| R4 — Pas d'équipe | idem pour chaque agent ; puis retrait/désactivation par SQL |
| R5 — Contrôle désactivé et sans appli | activer l'interrupteur (hors périmètre de l'audit) |
| R6 — Aucune notification effective sans SMTP et sans distributeur planifié | `MAILER_DSN=null://null` par défaut ; `app:notifications:dispatch` doit être planifié (unités systemd livrées, non installées) |

Écrans manquants : création/édition de commune, droits commerciaux, invitation et gestion d'équipe, référentiels, tableau de bord communal, écran de contrôle authentifié (si retenu).

---

## 5. Écarts par rapport aux décisions

| Décision | Écart |
|---|---|
| **DM-078** accès commerciaux administrés depuis Sonata, « avant les premières démonstrations » | Non livré (jalon produit manqué). |
| **DM-097 / DM-098** motifs et catégories « référentiel administrable » | Aucun écran d'administration (données semées). |
| **DM-065** clôture forcée exceptionnelle par CWS depuis Sonata | Absente. |
| **DM-061** impersonation CWS (exigences anticipées) | Absente (acceptable : « anticipée »). |
| **DM-009** gestion des zones par l'administrateur communal | Implémentée ; à vérifier que la gestion de zones reste restreinte à `MUNICIPALITY_ADMIN` (cf. `EventZoneController`) [M]. |
| **DM-036** protection du dernier administrateur | Garde-fou présent (`LastMunicipalityAdminGuard`), mais sans point d'entrée ; le remplacement d'un administrateur est donc impossible hors SQL. |
| **DM-115** scan public intégral | Conforme, mais désactivé par interrupteur ; pas d'écran de contrôle pour agents. |
| **DM-005** | Conforme. |

Aucune contradiction nouvelle de la documentation n'a été relevée ; aucun document de référence n'a été modifié.

---

## 6. UX — problèmes prioritaires (agent municipal non technique)

Revue par lecture des templates et de la navigation ; **aucun test sur appareil ni avec utilisateurs [M]**.

1. **Bloquant** : un agent qui n'a pas le rôle `INSTRUCTOR` n'a pas d'entrée « Demandes » et aucun message ne l'explique ; l'administrateur ne peut pas lui attribuer ce rôle (P0-4).
2. **Page d'accueil `/`** : écran « Civipass » minimal ; un utilisateur connecté voit seulement sa déconnexion s'il n'a pas de commune (pas de message « contactez votre administrateur »). À vérifier [M] avec `app_municipal_select` quand 0 commune.
3. **Pas de tableau de bord** : l'atterrissage est la liste des événements ; pas de compteur de demandes à traiter, pas de rappel des invitations en attente.
4. **Vocabulaire** : « Droits des personnes » et « Conservation des données » (juridique) cohabitent dans le même menu que les écrans de travail ; « Archives », « Corrections d'identité » partagent l'icône `file`/`user` des autres entrées (icônes redondantes). Menu de 7 entrées pour un administrateur, sans regroupement.
5. **Contrôle terrain** : aucune entrée « Contrôler un laissez-passer » dans le menu ; l'agent de terrain dépend de l'appli caméra du téléphone et de l'ouverture d'un lien. Aucun mode « scan » ni consigne d'usage visible.
6. **Statuts** : plusieurs vocabulaires coexistent (statuts de commune actif/suspendu/inactif, de participation, de demande par commune, de laissez-passer « calculé ») ; la cohérence des libellés entre écrans reste à valider [M] (`StatusPill`, `commune_status`).
7. Les maquettes `/dev/ui` sont limitées à l'environnement `dev` (`#[Route(env: 'dev')]`, `#[When(env: 'dev')]`) : pas d'exposition en production [C].

---

## 7. État réel de Sonata

3 écrans de **consultation** (communes, comptes, appartenances), sans dashboard actions, sans journal d'audit consultable, sans droit commercial, sans référentiel, sans action d'écriture (par conception, DT-018). C'est une **fondation de supervision**, pas une administration. Les services audités d'écriture existent, testés unitairement/intégration, et attendent leur couche d'exposition (Sonata ou commandes console).

Sécurité du back-office : sain (super-admin seul, authentification complète exigée, pas d'accès aux dossiers opérationnels, aucun hash ni jeton affiché ; [T] `testNoPasswordHashOrTokenIsEverDisplayed`).

---

## 8. État réel de la PWA

Aucune (voir §2.4). Le contrôle est une **page web publique rendue côté serveur**, sans JavaScript, dont l'exactitude vient de l'évaluation à chaque lecture. Risques en réseau dégradé : page non chargée = aucune décision possible ; page en cache navigateur potentiellement périmée (les en-têtes de la page visent à empêcher la mise en cache ; bouton « Actualiser » explicite) ; absence de file de secours. À tester sur appareil réel [M] : lecture QR par iOS/Android natifs, comportement navigateur en 3G faible, affichage au soleil/plein écran.

Recommandation : **ne pas** développer de contrôle hors ligne avant décision métier explicite (risque de faux positifs après révocation). Une PWA *installable* en ligne (manifeste + caméra intégrée) est un confort, pas un prérequis du pilote.

---

## 9. Risques de sécurité et d'exploitation

| Sujet | Constat | Niveau |
|---|---|---|
| Isolation multicommunale | Forte et testée ; aucun défaut relevé dans le périmètre audité | ✅ |
| Rôles | Contrôle serveur par `MunicipalityVoter`/policies ; rôles non attribuables par UI | 🟡 |
| Comptes et sessions | Limiteur de connexion, CSRF, remember-me 30 j (explicite), cookies `httponly`/`samesite=lax`, `secure: auto` ; **aucune expiration d'inactivité** configurée (durée de session = valeurs PHP par défaut) ; pas de liste/révocation de sessions actives | P2 |
| Désactivation d'un compte | Effet immédiat testé (`UserChecker`, [T] `UserDeactivationTest`, `testADeactivatedAccountLosesTheBackOfficeImmediately`) mais sans UI | P0 (via R4) |
| SMTP / notifications | `MAILER_DSN=null://null`, `MAILER_FROM=no-reply@civipass.invalid` par défaut ; **mot de passe oublié, invitations et notifications bénéficiaire dépendent d'un SMTP réel** ; le distributeur d'outbox est une unité systemd **livrée mais non installée** | P1 |
| HTTPS et URL publiques | Gabarit nginx : redirection HTTP→HTTPS, HSTS un an ; QR et liens émis à partir de `DEFAULT_URI` (=`http://localhost` par défaut) : **à fixer en https** avant tout QR imprimé | P1 |
| `APP_SECRET`, `.env` | `APP_SECRET=` vide dans `.env`, `DATABASE_URL` avec mot de passe d'exemple : normal en dépôt, **à vérifier sur l'hôte** [M] | P1 |
| Routes `/dev/ui` | Limitées à `dev` par attribut de route et de service [C] ; rien à corriger | ✅ |
| Sauvegarde / restauration | Scripts (`ops/backup`), runbooks (`docs/runbooks`), contrôle de restauration et registre chaîné : livrés et testés en local ; **aucune exécution réelle sur l'hôte ni exercice de restauration** | P1 |
| Supervision | `app:ops:status`, battements de cœur, alerte `OPS_ALERT_EMAIL` (vide par défaut) ; unités non installées | P1 |
| CI/CD | Aucun `.github/` ni `.gitlab-ci.yml` dans le dépôt : pas de déploiement automatique détecté côté dépôt ; non vérifiable côté serveur | info |
| Fonctions coupées par sécurité | `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0` (valeurs par défaut `services.yaml`) ; **non activés** | ✅ |
| Contrôle public | Limitation par IP et échecs, réponse unique 404, 410 pour jeton remplacé ; décision d'activation à prendre avant le pilote (sinon aucun QR n'est lisible) | P0 décisionnel |

---

## 10. Contrôles exécutés

| Contrôle | Résultat |
|---|---|
| `docs/tools/verify-docs.sh` | OK (SHA-256 des originaux, liens relatifs) |
| `doctrine:schema:validate --skip-sync` | Mapping OK |
| `doctrine:schema:validate` (synchronisation base) | non conclu : la base de la machine hôte n'a pas répondu (le contrôle doit être refait via le conteneur) |
| `bin/qa full` (CS-Fixer, PHPStan niveau 6, PHPUnit) | **OK** (« All quality checks passed », code de sortie 0) [F] |
| Parcours manuel en navigateur, test sur smartphone | **non réalisés** [M] |

---

## 11. Corrections priorisées

### P0 — bloquant l'ouverture d'une commune pilote
- **P0-1** Création et administration des communes par CWS (création depuis le référentiel national, contact, activation/suspension) — via Sonata (actions d'écriture sur services audités) ou, à défaut, commandes console.
- **P0-2** Attribution/révocation des accès commerciaux (DM-078) avec historique et expiration.
- **P0-3** Amorçage du premier MUNICIPALITY_ADMIN : invitation sécurisée par e-mail (réutiliser le mécanisme de lien d'invitation) déclenchée par CWS, avec remplacement/révocation d'un administrateur (DM-036).
- **P0-4** Gestion d'équipe communale dans l'interface métier : inviter, attribuer rôles, invitations en attente, modifier/retirer, désactiver ; respect du dernier administrateur.
- **P0-5** Décision d'activation du contrôle public (`PUBLIC_VERIFICATION_ENABLED`) et de son périmètre de pilote (sans quoi aucun QR n'est exploitable).

### P1 — nécessaires au pilote
- **P1-1** Scénario de recette bout-en-bout depuis une commune vierge (sans fixtures) + recette manuelle navigateur/smartphone.
- **P1-2** Configuration de production : SMTP réel, `MAILER_FROM`, `DEFAULT_URI` https, `APP_SECRET`, installation validée des unités (accord requis).
- **P1-3** Exercice réel de sauvegarde/restauration et de supervision sur l'hôte.
- **P1-4** UX : tableau de bord communal minimal (demandes à traiter, invitations en attente), message pour utilisateur sans rôle/sans commune, entrée « Contrôle » (page d'aide au scan), regroupement du menu.
- **P1-5** Procédure documentée de récupération d'accès super-admin et administrateur communal.
- **P1-6** Administration des référentiels (motifs, catégories, motifs de refus) ou décision de les figer en V1.

### P2 — améliorations ultérieures
- PWA installable avec scan caméra intégré ; décision métier sur tout mode hors ligne.
- Expiration d'inactivité de session, liste des sessions, double authentification pour CWS.
- Journal d'audit consultable par CWS et par l'administrateur communal.
- Clôture forcée CWS (DM-065), impersonation encadrée (DM-061).
- Harmonisation des libellés de statut et icônes ; tests d'accessibilité sur appareil.

---

## 12. Découpage proposé des prochains lots (sans implémentation)

| Lot | Contenu | Dépend de | Migration probable |
|---|---|---|---|
| **LOT 07.1 — Administration CWS (fondations)** | P0-1, P0-2, P0-3 : actions Sonata d'écriture (communes, accès commerciaux, invitation du premier administrateur, remplacement d'un administrateur), audit, tests d'autorisation | arbitrage : écriture dans Sonata vs. écrans métier CWS | éventuelle (historique d'accès commerciaux, invitation administrateur) |
| **LOT 07.2 — Équipe communale** | P0-4 : écrans d'équipe, invitations de collaborateurs, rôles, désactivation, garde dernier administrateur | 07.1 (mécanisme d'invitation) | éventuelle (invitation collaborateur) |
| **LOT 07.3 — Contrôle terrain v1** | P0-5 + P1-4 partiel : activation encadrée du contrôle, page d'aide et d'usage, consignes, réseau dégradé (messages) ; PWA exclue | décision métier | non |
| **LOT 07.4 — Recette pilote et exploitation** | P1-1, P1-2, P1-3, P1-5 : recette bout-en-bout, configuration de production, exercices de restauration, procédures d'accès | 07.1–07.3 | non |
| **LOT 07.5 — Ergonomie et référentiels** | P1-4, P1-6, UX §6 | 07.2 | éventuelle |
| **LOT 08 — PWA et sécurité de session** | P2 | pilote réalisé | à cadrer |

Chaque lot suivra le protocole de cadrage validé une fois. Les migrations ne sont mentionnées qu'à titre d'hypothèse, rien n'est approuvé.

---

## 13. Limites de l'audit

Audit par lecture du code, des routes et des tests, et exécution des contrôles non destructifs. Non fait : navigation manuelle de l'application, tests sur smartphone, revue de la configuration de l'hôte de production, lecture exhaustive des 257 tests (leur couverture a été jugée par nom et par recoupement avec les routes). Les constats d'UX (§6) reposent sur la lecture des templates.
