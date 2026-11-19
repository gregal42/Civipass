# LOT 11.1 — Audit sécurité automatisé (19 novembre 2026)

**Nature** : constats uniquement. Aucune correction, aucun changement de code métier, de configuration applicative ni de migration. Seuls ajouts : trois outils/tests en lecture seule (§7) et le registre de sécurité ([registre-securite.md](registre-securite.md), SEC-002 à SEC-012). Plan : [lot-11-plan-audit-securite-rgpd.md](lot-11-plan-audit-securite-rgpd.md) §1.

**Environnement** : poste de développement (conteneur `saas_civipass_php-fpm`, base de test, serveur local HTTP via Traefik/Nginx de développement). Rien n'a été mesuré sur un serveur HTTPS réel : tout ce qui dépend du Nginx de production reste à vérifier au LOT 11.4.

**Échelle de criticité** : Critique (exploitable, données ou comptes en danger) · Élevée · Moyenne (défense en profondeur absente ou risque conditionnel) · Faible · Info.

## 1. Synthèse

| Zone | Résultat |
|---|---|
| Isolation entre communes, identifiants forgés | **Aucune faille.** 0 route de gestion ne répond 200 ou 5xx à un anonyme, à un agent sans droit CWS sur `/admin`, ni à un agent d'une autre commune (identifiants de commune et d'événement forgés) |
| Matrice routes → permissions | 146 routes inventoriées ; aucune route de gestion sans contrôle explicite (pare-feu ou politique) ; routes de développement `/dev/ui` fermées hors `dev` (trois verrous) |
| En-têtes de sécurité | Surfaces à secret en URL (`/v`, `/laissez-passer`, `/controle`) **bien protégées** ; origine de gestion **sans CSP, X-Frame-Options, nosniff ni Referrer-Policy** (SEC-002) |
| Dépendances | `composer audit` : 0 avis de sécurité ; `importmap:audit` : 0 vulnérabilité ; 18 mises à jour mineures ou correctifs disponibles (Info, SEC-011) |
| Secrets | Aucun secret dans le dépôt ni dans l'historique (motifs de clés privées, jetons AWS/GitHub/Slack/Stripe/Google) ; `APP_SECRET` et `NOTIFICATION_KEYRING` vides dans `.env` versionné ; contrôle de déploiement incomplet (SEC-005) |
| Autres constats | Dépôt public sans limitation de débit à la soumission (SEC-003) ; sauvegardes non chiffrées (SEC-004) ; pas de 2FA CWS (SEC-006) |

**Aucun constat Critique ni Élevé.** Cinq constats Moyens, trois Faibles, trois Info.

## 2. Constats

Chaque constat est enregistré au registre (statut *Ouverte*, correction à cadrer dans un sous-lot séparé).

### SEC-002 — En-têtes de sécurité absents sur l'origine de gestion — **Moyenne**

- **Preuve (serveur local)** : `curl -sI http://civipass.cws.gregory.localhost/login` → seuls `Cache-Control`, `Set-Cookie` (HttpOnly, SameSite=Lax) et `X-Robots-Tag` ; ni `Content-Security-Policy`, ni `X-Frame-Options`, ni `X-Content-Type-Options`, ni `Referrer-Policy` (présent seulement sur `/forgot-password`). Même constat, mesuré par un test HTTP temporaire, sur : liste et fiche d'événement, équipe, création d'événement (agent), `/admin/dashboard`, `/admin/communes/commune/list`, `/admin/cws/communes/ouvrir` (CWS), `/demandes/{réf}/identite` (dépôt public, données personnelles).
- **Preuve (configuration cible)** : `ops/nginx/templates/10-civipass-site.conf.template:50` ne pose que `Strict-Transport-Security` ; l'origine de contrôle (`20-civipass-control.conf.template:57-62`) pose la série complète. Les en-têtes ne sont posés dans le code que pour des contrôleurs particuliers (`grep` : Permit*, Archive, Onboarding, PasswordReset, `ControlHeadersListener`, `PublicVerificationHeadersListener`).
- **Risque** : pas de défense en profondeur contre l'injection de script (aucune CSP), contre le détournement de type MIME, ni contre l'affichage dans un cadre (le cookie SameSite=Lax limite le clickjacking authentifié, mais ne le supprime pas pour les pages publiques et la connexion). HSTS seul est présent en production.
- **Correction recommandée** : ligne de base posée par Nginx au niveau `server` de l'origine de gestion (`nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` ou `strict-origin-when-cross-origin`, `Permissions-Policy`) ; CSP d'abord en `Report-Only` (Sonata embarque des scripts et styles en ligne), puis restrictive ; jeu de contrôles `verify-nginx.sh` étendu ; attention à la règle déjà documentée : un `add_header` dans un `location` supprime ceux du `server`.

### SEC-003 — Aucune limitation de débit à la soumission du dépôt public — **Moyenne**

- **Preuve** : `grep consume( src/Request` → unique usage `AbstractRequestFormController.php:262` (limiteur `public_request_upload_ip`, 40 téléversements / 15 min / IP). La soumission finale (`PublicRequestController` → `submitPublic`) et les étapes n'ont aucun limiteur. [lot-03-schema.md](lot-03-schema.md) §11 prévoit pourtant « limitation de débit par IP et par événement ».
- **Risque** : un script anonyme peut inonder la file d'instruction d'une commune et remplir la base (demandes valides en la forme). CSRF, idempotence et validation serveur protègent l'intégrité, pas le volume.
- **Correction recommandée** : limiteur par IP et par événement sur la soumission finale ; plafond par événement et alerte ; seuils à fixer avec la décision S6 du plan (dépôt public) ; éventuellement difficulté supplémentaire (jeton à durée courte, pas de captcha tiers : aucun script tiers autorisé).

### SEC-004 — Sauvegardes non chiffrées avant la copie hors serveur — **Moyenne**

- **Preuve** : `ops/backup/backup.sh` (`umask 077`, `db.sql.gz`, `storage.tar.gz`, copies du registre) : aucune étape de chiffrement ; `docs/runbooks/sauvegarde.md` demande une copie hors serveur sans exiger de chiffrement (aucune occurrence de « chiffr » dans la procédure de copie).
- **Risque** : une copie externalisée contient demandes, plaques, pièces jointes et données purgées jusqu'à rotation (35 jours) ; sa fuite équivaut à celle de la base.
- **Correction recommandée** : chiffrer avant la sortie du serveur (clé hors serveur, par exemple `age`), documenter la gestion de clé et un essai de restauration depuis une copie chiffrée ; à intégrer au kit d'installation.

### SEC-005 — Contrôle de déploiement incomplet — **Moyenne**

- **Preuve** : `.env` versionné : `APP_SECRET=` (vide), `MAILER_DSN=null://null`, `NOTIFICATION_KEYRING=` et `NOTIFICATION_ACTIVE_KEY_ID=` vides, `DATABASE_URL` de développement (identifiants, valeur non reproduite ici). `ops/nginx/bin/check-deployment.sh` vérifie `APP_ENV`, `APP_DEBUG`, `DEFAULT_URI`, proxys de confiance, mais ni `APP_SECRET` non vide, ni identifiants de base différents des valeurs par défaut, ni `MAILER_DSN` réel, ni présence du trousseau de chiffrement. Historique Git : aucune valeur réelle de ces variables n'a jamais été validée (`git log -p`).
- **Risque** : une mise en production qui oublierait de surcharger une de ces variables démarrerait avec un secret vide ou une configuration de développement sans alerte. Comportement exact d'un `APP_SECRET` vide en production non vérifié ici.
- **Correction recommandée** : étendre `check-deployment.sh` (refus si `APP_SECRET` vide ou court, `DATABASE_URL` par défaut, `MAILER_DSN` en `null://`, trousseau absent) ; `app:notifications:check-crypto` au GO / NO-GO.

### SEC-006 — Pas de second facteur pour les super-administrateurs CWS — **Moyenne** (décision S3 en attente)

- **Preuve** : aucune occurrence de 2FA/TOTP dans `src` ; mot de passe seul (12 caractères minimum, `PlainPasswordPolicy::MIN_LENGTH`, aucune liste de mots de passe compromis) ; le compte `ROLE_SUPER_ADMIN` accède à Sonata et aux actions `/admin/cws/*` (ouverture de commune, accès, clôture exceptionnelle). Atténuations existantes : authentification complète exigée, session CWS de 15 min d'inactivité / 8 h, limiteur de connexion, Sonata en lecture seule.
- **Correction recommandée** : trancher S3 ; deuxième facteur au moins pour CWS avant le pilote ; contrôle contre les mots de passe compromis ; ces deux points sont des évolutions fonctionnelles : corrections soumises à validation.

### SEC-007 — `Cache-Control: no-store` absent du formulaire public et des pages d'instruction — **Faible**

- **Preuve** : `/demandes/{réf}/identite` → `Cache-Control: max-age=0, must-revalidate, private` ; `no-store` n'est posé que sur certains contrôleurs (liste `grep no-store src`) et `PublicRequestController` et `MunicipalRequestController` n'y figurent pas (lecture du code ; à confirmer par mesure en 11.2 pour les pages d'instruction).
- **Risque** : retour arrière du navigateur ou cache d'historique sur un poste partagé (mairie, bibliothèque) après abandon du brouillon ou déconnexion de l'agent.
- **Correction recommandée** : `no-store` sur toutes les pages portant des données personnelles ; couvrir par un test d'en-têtes.

### SEC-008 — Jeton CSRF vérifié avant l'autorisation sur `app_municipal_refusal_informed` — **Faible**

- **Preuve** : `RefusalNoticeController.php:55` : jeton invalide → message et redirection 303 sans contrôle d'accès préalable ; les autres contrôleurs (droits, statistiques) appliquent « droit avant jeton ». Constaté par le parcours : un agent d'une autre commune reçoit 303 (message de formulaire expiré) au lieu du 404 uniforme. Aucune écriture, aucune donnée ; avec un jeton valide, le service refuse (`OperationNotAllowed` → 404).
- **Correction recommandée** : aligner sur la convention (autorisation avant jeton). Le test `RouteAccessCrawlTest` nomme cette exception (`KNOWN_FLASH_REDIRECTS`) et devra la retirer à la correction.

### SEC-009 — Route d'annulation d'un code d'activation sans test fonctionnel dédié — **Faible**

- **Preuve** : sur 115 routes sensibles, 114 sont nommées ou référencées dans les tests existants ; `app_control_code_revoke` (`/communes/{municipalityId}/evenements/{eventId}/controle/codes/{activationId}/annuler`) ne l'est pas. L'opération est testée au niveau du service (`ControlActivationServiceTest`, `ControlDeviceLifecycleTest`) et le nouveau parcours confirme le refus aux profils non autorisés.
- **Correction recommandée** : test HTTP du parcours complet (GET de confirmation, POST avec jeton, refus inter-communes).

### SEC-010 — Règle d'accès `/communes` en « mémorisé » alors que « se souvenir de moi » est supprimé — **Info**

- **Preuve** : `security.yaml` : `^/communes(?:/|$)` → `IS_AUTHENTICATED_REMEMBERED` ; remember-me supprimé (DM-138). Les deux niveaux sont équivalents aujourd'hui. Risque si le remember-me était réintroduit : les lectures resteraient accessibles en session mémorisée.
- **Correction recommandée** : passer la règle en `IS_AUTHENTICATED_FULLY` lors d'un lot de durcissement.

### SEC-011 — Dépendances : mises à jour disponibles, jsQR non maintenu — **Info**

- **Preuve** : `composer audit` → « No security vulnerability advisories found » (verrouillé et non verrouillé) ; `importmap:audit` → « No vulnerabilities found » ; `composer outdated --direct` : 18 paquets avec correctif ou version mineure (Symfony 8.1.10 → 8.1.11, Sonata 4.43 → 4.44, Doctrine ORM 3.7.6 → 3.8.0, etc.). jsQR 1.4.0 est une copie vendorisée (`assets/vendor/jsqr/`) que ces audits ne couvrent pas (veille manuelle, DT-C5).
- **Correction recommandée** : passer les correctifs Symfony avant la mise en service (changement de dépendances : accord requis, règle 10) ; revue annuelle de jsQR.

### SEC-012 — Sauvegardes de développement lisibles par tous les comptes du poste — **Info**

- **Preuve** : `ls -la var/backup-local` → fichiers `-rw-r--r--` (dumps du 17 et 18 novembre) ; le dossier est ignoré par Git. Le script de production, lui, applique `umask 077`.
- **Correction recommandée** : `chmod 600` sur le poste ; aucune action sur le produit.

## 3. Résultats par axe

### 3.1 Matrice routes → permissions

Outil : `tools/audit/route-matrix.php` (lecture seule, noyau `dev`, aucune requête). Répartition des 146 routes :

| Surface | Routes | Contrôle |
|---|---|---|
| Agent `/communes/**` | 84 | Pare-feu `main` (`access_control` : `IS_AUTHENTICATED_REMEMBERED`, ou `FULLY` pour les écritures de l'équipe, des événements, des zones et du contrôle) puis politique applicative (lecteurs et services recevant l'utilisateur et la commune) |
| Agent, session | 2 | `/session/etat` : 401 anonyme ; `/session/prolonger` : 403 anonyme |
| CWS `/admin/cws/**` | 11 | `ROLE_SUPER_ADMIN` + `IS_AUTHENTICATED_FULLY` (`access_control`) |
| Sonata `/admin/**` | 14 | idem ; toute écriture hors `/admin/cws` refusée (`allow_if "false"`), routes techniques fermées |
| Public (accueil, connexion, mot de passe) | 8 | voulu ; CSRF et limiteurs |
| Public à secret en URL | 12 | `/v/{jeton}` (pare-feu sans sécurité), `/laissez-passer/{secret}`, `/invitation/*`, `/demandes/*` : voulu |
| Contrôle `/controle/**` | 8 | pare-feu sans session ; secret d'appareil en `Authorization` |
| Développement `/dev/ui` | 7 | `#[Route(env: 'dev')]` + `#[When('dev')]` + garde d'exécution ; absentes hors `dev` |

L'heuristique d'analyse du code des contrôleurs signale 30 routes « sans marqueur » (agent : 22, CWS : 8). Ce ne sont pas des trous : les 8 routes CWS sont couvertes par `access_control` ; les 22 routes agent déléguent l'autorisation à un service ou à un lecteur qui reçoit `$user` et `$municipalityId` (lecture de six contrôleurs : `MunicipalRequestAttachmentController`, `RightsController`, `StatisticsCoordinationController`, `RefusalNoticeController`, `ControlDevicesController`, `EventShowController`). Elles sont couvertes de façon **dynamique**, sans dépendre de la lecture du code, par le parcours du §3.2. Limite : la lecture manuelle n'a pas porté sur chacune des 22 routes ; l'heuristique prouve la présence d'un marqueur, pas sa justesse.

### 3.2 Isolation et identifiants forgés

Nouveau test `tests/Security/RouteAccessCrawlTest.php` (4 tests, 437 assertions, vert) : génère les URL concrètes de **chaque route** de `/communes/**` et `/admin/**` (GET et POST avec jeton invalide, valeurs conformes aux exigences de chaque route) et vérifie les réponses :

| Profil | Surface visée | Attendu | Résultat |
|---|---|---|---|
| Anonyme | `/communes/**`, `/admin/**` | redirection vers `/login` | conforme |
| Administrateur communal | `/admin/**` | 403 / 404 | conforme |
| Administrateur d'une autre commune | `/communes/{commune de l'organisatrice}/**` avec le vrai événement | 403 / 404 | conforme |
| Administrateur de l'organisatrice | événement forgé ; commune étrangère avec le vrai événement | pas de fuite, 403 / 404 pour la commune étrangère | conforme (une exception nommée, SEC-008) |

Rejeu ciblé des suites existantes d'isolation et de permissions (`RightsAccessTest`, `ArchiveAccessTest`, `ControlHttpTest`, `AdminAccessTest`, `TeamPagesTest`, `InvalidLinkResponsesTest`, `ClosedEventBarrierTest`, `EventPermissionMatrixTest`) : elles font partie du passage complet vert du jour (3 695 tests). 114 routes sensibles sur 115 sont référencées par au moins un test (SEC-009). Limite : le parcours n'utilise pas de jeton CSRF valide ; les refus après jeton valide restent couverts par les suites métier.

### 3.3 En-têtes de sécurité

Mesures sur le serveur local HTTP (écart de configuration possible en production : voir 11.4).

| Surface | CSP | X-Frame | nosniff | Referrer | Cache-Control | Cookie |
|---|---|---|---|---|---|---|
| `/v/{jeton}` | stricte (`default-src 'none'`, `frame-ancestors 'none'`) | DENY | oui | no-referrer | no-store | aucun |
| `/laissez-passer/{secret}` | stricte | DENY | oui | no-referrer | no-store | aucun |
| `/controle/**` | stricte, scripts `self` seulement | DENY | oui | no-referrer | no-store | aucun |
| `/login`, `/forgot-password` | **absente** | **absent** | **absent** | `/forgot-password` seul | no-store | `PHPSESSID` HttpOnly, SameSite=Lax (Secure automatique en HTTPS) |
| Pages agent et Sonata | **absente** | **absent** | **absent** | **absent** | `private` (no-store selon les pages) | idem |
| Dépôt public `/demandes/**` | **absente** | **absent** | **absent** | **absent** | `private, must-revalidate` | idem |

HSTS : posé par le gabarit Nginx de production (`max-age=31536000`), absent du serveur local HTTP, à vérifier en 11.4. Test ajouté : `tests/Security/SecretSurfaceHeadersTest.php` (2 tests, 18 assertions) fige les en-têtes des surfaces à secret. Pages d'erreur du serveur local : en-têtes `X-Debug-Exception` en mode `dev` uniquement ; `check-deployment.sh` refuse `APP_DEBUG` actif.

### 3.4 Dépendances

`composer audit` (verrouillé et non verrouillé, paquets abandonnés inclus) et `bin/console importmap:audit` : aucun avis. `composer validate` : valide. Les paquets de développement (PHPUnit 13.4.6, PHPStan 2.3.4, CS-Fixer) ne sont pas déployés. jsQR : voir SEC-011. PHP 8.4.27, MySQL 8.4 (versions de la période de support).

### 3.5 Secrets et données exposées

- Dépôt (HEAD, hors `vendor`) et historique complet (137 commits) : aucun motif de clé privée, jeton AWS, GitHub, Slack, Stripe, Google. `gitleaks` et `trufflehog` ne sont pas installés : recherche par motifs (`git grep`, `git log -G`), moins complète qu'un outil dédié (limite).
- `.env` et `.env.test` sont versionnés ; `.env.local`, `.env.*.local` et `var/` sont ignorés. `.env` : secrets vides ou valeurs de développement (SEC-005). `.env.test` : `APP_SECRET` de test.
- `public/` ne contient que `index.php` suivi (`bundles/` non suivi) ; aucun fichier sensible, sauvegarde ni journal dans `public/`.
- Adresses de courriel dans les jeux de test : domaine `exemple.fr`, aucune donnée réelle repérée.
- `var/` (hors dépôt) : sauvegardes de développement lisibles par tous les comptes du poste (SEC-012).
- Sonata : liste et fiches limitées à `email`, noms, dates, statut, rôles ; aucun hachage de mot de passe ni donnée de dossier citoyen.

## 4. Anomalies de documentation relevées

- [lot-03-schema.md](lot-03-schema.md) §11 annonce une limitation de débit par IP et par événement pour le dépôt public ; le code n'en pose que sur les téléversements (SEC-003).
- [lot-03-schema.md](lot-03-schema.md) §11 annonce une référence d'événement « non prédictible » ; la référence publique est séquentielle (`EVT-2026-000001` dans la recette). À vérifier en 11.2 : réponse uniforme pour un événement inconnu, brouillon ou clos.

## 5. Ce que cet audit ne couvre pas

Revue manuelle (sessions, pièces jointes, journaux, modèle de menace du contrôle hors connexion : LOT 11.2) ; mesures sur serveur HTTPS réel (HSTS, TLS, SEC-001 : LOT 11.4) ; test d'intrusion (audit externe) ; qualité du CSRF avec jeton valide sur chaque route ; sécurité du stockage des pièces jointes au repos.

## 6. Décisions et actions recommandées (aucune engagée)

1. Cadrer un sous-lot de correction « en-têtes de l'origine de gestion » (SEC-002), à rattacher au kit Nginx.
2. Décider des seuils du dépôt public (S6) puis cadrer SEC-003.
3. Décider du chiffrement et de la clé des sauvegardes externalisées (SEC-004).
4. Étendre `check-deployment.sh` (SEC-005) et l'intégrer à la liste GO / NO-GO.
5. Décision S3 : second facteur CWS (SEC-006).
6. Toute mise à jour de dépendance : accord préalable (règle 10 de CLAUDE.md).

## 7. Outils et tests ajoutés (lecture seule)

| Fichier | Rôle | Exécution |
|---|---|---|
| `tools/audit/route-matrix.php` | Inventaire des routes et du contrôle d'accès (`--format=md` ou `tsv`) | `docker exec saas_civipass_php-fpm php tools/audit/route-matrix.php` (hors périmètre PHPStan et CS-Fixer, qui visent `src/` et `tests/`) |
| `tests/Security/RouteAccessCrawlTest.php` | Parcours de toutes les routes de gestion avec identifiants forgés | `bin/qa run RouteAccessCrawl` ; inclus dans `bin/qa full` |
| `tests/Security/SecretSurfaceHeadersTest.php` | En-têtes des surfaces à secret en URL | `bin/qa run SecretSurfaceHeaders` ; inclus dans `bin/qa full` |
