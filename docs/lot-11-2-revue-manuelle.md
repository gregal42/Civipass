# LOT 11.2 — Revue manuelle de sécurité

**Date** : 19 novembre 2026 · **Branche** : `develop` · **Nature** : constats d'audit uniquement. **Aucun code, aucune configuration, aucun test ni aucune migration n'a été modifié ; aucune correction n'est engagée** (les corrections font l'objet de sous-lots cadrés à part). Les identifiants `11.2-Fn` sont provisoires : ils seront intégrés au registre ([registre-securite.md](registre-securite.md)) par le pilote du lot.

Périmètre (plan [lot-11-plan-audit-securite-rgpd.md](lot-11-plan-audit-securite-rgpd.md), §5) : authentification et sessions ; dépôt public et pièces jointes ; journalisation et exposition des données ; modèle de menace du contrôle hors connexion ; mécanismes de révocation et d'expiration.

## 0. Méthode et limites

- **Lecture ciblée du code** (configuration de sécurité, écouteurs, contrôleurs, services, JavaScript de la PWA, gabarits Nginx et PHP-FPM, kit `ops/`) et des documents de référence. Pas de relecture ligne à ligne de tout le dépôt.
- **Essais locaux en lecture seule** : `curl` sur la page de connexion de l'environnement de développement (en-têtes, cookie) ; `debug:config` / `debug:container` et `ini_get` dans le conteneur PHP. **Aucun test PHPUnit relancé** (la matrice d'isolation et d'autorisation est l'objet de 11.1). Aucun essai n'a été fait sur un serveur HTTPS réel.
- **Les constats « validé en local » ne valent pas validation sur la cible** : les points qui dépendent du serveur réel sont signalés « à rejouer en 11.4 ».
- Criticité : **Critique** (exploitation directe, données ou comptes), **Élevée** (compromission d'un compte à fort pouvoir ou fuite plausible), **Moyenne** (défaut de défense en profondeur, abus ou erreur d'exploitation plausible), **Faible** (durcissement), **Info** (constat neutre ou positif). Aucun constat Critique.

## 1. Synthèse des constats

| ID | Criticité | Constat | Priorité de correction proposée |
|---|---|---|---|
| 11.2-F1 | **Élevée** | Compte super-administrateur CWS protégé par mot de passe seul (pas de second facteur ni de restriction d'accès), alors qu'il peut désigner l'administrateur d'une commune | P1 (décision S3) |
| 11.2-F2 | Moyenne | Dépôt public : aucune limite ni anti-automatisation sur la **soumission** ; l'accusé de réception part vers n'importe quelle adresse saisie | P1 |
| 11.2-F3 | Moyenne | Origine de gestion sans en-têtes de sécurité globaux (pas de `X-Frame-Options`/`frame-ancestors`, CSP, `nosniff`, `Referrer-Policy`) — recoupe 11.1 | P1 |
| 11.2-F4 | Moyenne | Garde-fous G1–G3 inactifs par défaut et non liés à l'activation du contrôle hors connexion : activer la PWA sans `CONTROL_HOST` la place sur l'origine des sessions | P1 |
| 11.2-F5 | Moyenne | Limiteurs de connexion, de réinitialisation, d'invitation et de téléversement sur un stockage qui **ignore silencieusement** un échec d'écriture | P1 |
| 11.2-F6 | Moyenne | Appareil de contrôle volé : le secret est utilisable depuis n'importe quelle machine ; durée de vie par défaut 72 h (jusqu'à 14 jours configurables) pour une confiance de 12 h | P1 (configuration + consigne) |
| 11.2-F7 | Moyenne | Lien d'invitation « contact » : prise de contrôle possible d'une commune encore sans administrateur (risque déjà documenté, non arbitré) | décision métier |
| 11.2-F8 | Faible | Références d'événement séquentielles et pages publiques non limitées : énumération des événements ouverts | P2 |
| 11.2-F9 | Faible | Justificatifs : aucun antivirus ; format DOC (OLE, macros possibles) accepté | P2 (décision) |
| 11.2-F10 | Faible | « Mot de passe oublié » : l'écart de temps résiduel (écritures en base pour un compte existant) n'a pas été mesuré comme l'a été SEC-001 | P2 (mesure en 11.4) |
| 11.2-F11 | Faible | Journalisation : le masquage ne traite pas les objets `Stringable` du contexte ; la rotation du journal PHP n'est pas dans le kit Nginx | P3 |
| 11.2-F12 | Faible | Cookie de session : `Secure` en mode `auto`, nom `PHPSESSID`, pas de préfixe `__Host-` | P3 |
| 11.2-F13 | Faible | `check-deployment.sh` ne contrôle ni `APP_SECRET`, ni `DATABASE_URL`, ni le propriétaire de `var/cache`, ni `PRIVATE_STORAGE_DIR` | P2 |
| 11.2-F14 | Info | Réglages PHP de téléversement absents de l'ancien exemple de pool ; le nouveau kit d'installation les pose | à confirmer en recette |

Aucune faille d'isolation entre communes n'a été recherchée ici (périmètre de 11.1, matrices avec identifiants forgés). Aucun contournement d'authentification n'a été constaté.

## 2. Points vérifiés et conformes

| Point | Constat | Preuve |
|---|---|---|
| Fixation de session | Stratégie `migrate` (par défaut), mode strict de PHP activé par Symfony ; déconnexion avec `invalidate_session` | `debug:config security` : `session_fixation_strategy: migrate`, `invalidate_session: true` ; `NativeSessionStorage.php:82` (`use_strict_mode => 1`) |
| Cookie de session | `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS (relevé local : sans `Secure` en HTTP, comme attendu) | `config/packages/framework.yaml` (section `session`) ; `curl` local : `Set-Cookie: PHPSESSID=… path=/; httponly; samesite=lax` ; gabarit Nginx : `include fastcgi_params` transmet `HTTPS` |
| Expiration côté serveur | Inactivité 30 min (agents) / 15 min (CWS) ; durée absolue 12 h / 8 h ; bornes 5 min–2 h et 24 h ; la sonde ne prolonge pas ; retour post-connexion limité aux chemins internes GET | `SessionLifetime.php:20-57`, `SessionLifetimeListener.php:63-117` |
| Pas de session sur `/v/*` ni `/controle/*` | Pare-feu `security: false` ; « se souvenir de moi » supprimé | `security.yaml:15-25` ; absence de section `remember_me` |
| Invalidation des sessions existantes | `isEqualTo` compare e-mail, **hash du mot de passe**, **état actif** et rôle super-admin : un changement de mot de passe ou une désactivation coupe toute session à la requête suivante | `User.php:129-136` ; `UserProvider::refreshUser` relit l'utilisateur en base |
| Réinitialisation de mot de passe | Jeton 256 bits, empreinte SHA-256 seule en base, 30 min, usage unique, demandes précédentes invalidées, jeton retiré de l'URL (session), trois limiteurs, réponse identique, lien construit depuis `DEFAULT_URI` (jamais `Host`), e-mail envoyé après la réponse | `PasswordResetService.php:46-138`, `PasswordResetController.php`, `DeferredMailQueue.php`, `QueuedPasswordResetNotifier.php` |
| Invitations | Jeton 256 bits, empreinte SHA-256 seule, quitte l'URL (session), limiteurs par IP et par lien ; durées 14 j (contact), 3 j (administrateur, usage unique), 7 j (CWS) | `InvitationToken.php`, `InvitationLink.php:29-30`, `InvitationLinkController.php:60-150` |
| Énumération de comptes à la connexion | Corrigée (SEC-001, local) ; message de compte désactivé seulement après le bon mot de passe | [registre-securite.md](registre-securite.md) ; `UserChecker.php` |
| CSRF | Jetons de session pour connexion, déconnexion et formulaires ; contrôleurs d'écriture analysés : tous valident un jeton (l'API d'appareil n'a pas de cookie : secret en `Authorization`) | recherche `isCsrfTokenValid` sur les contrôleurs à méthode POST ; `ControlApiController.php:155-166` |
| Téléversement | Contrôle du **contenu** (type détecté par libmagic, structure PDF/JPG/PNG/OLE, taille réelle), nom d'origine jamais utilisé dans le chemin, clés aléatoires 128 bits, fichiers 0600 / dossiers 0700 hors `public/`, liens symboliques refusés, 5 pièces maximum, jetons de pièces rattachés à la session (un identifiant forgé de `remove` est ignoré) | `UploadedDocumentInspector.php`, `TemporaryUploadService.php`, `LocalFileStorage.php`, `AbstractRequestFormController.php:254-285` |
| Téléchargement par l'agent | Rôle INSTRUCTOR, commune et appartenance revérifiés à chaque appel ; `attachment`, `nosniff`, `no-store` | `MunicipalRequestAttachmentController.php:36-62` |
| Brouillon public | Effacé après 30 min d'inactivité et sur demande, pièces temporaires supprimées ; purge serveur planifiée | `PublicDraftKeeper.php` ; M6 : [lot-10-m6-sessions-php.md](lot-10-m6-sessions-php.md) |
| Fuite par le journal applicatif | Jetons `/v/`, `/laissez-passer/`, `/invitation/`, `/reset-password/` masqués, y compris variantes encodées et message « No route found » ; aucune donnée ni jeton dans les messages d'erreur d'e-mail | `SecretScrubbingLogger.php` ; `DeferredMailQueue.php:42-47` |
| Pages d'erreur | Erreurs métier traduites en 404/403/409/410 neutres ; PHP : `display_errors off`, `zend.exception_ignore_args` | `ErrorResponseListener.php` ; `civipass-pool.conf.example` |
| Pas d'URL dépendante de `Host` | Aucun `ABSOLUTE_URL`, `getHost()` ni `getSchemeAndHttpHost()` dans `src/` et `templates/` | recherche exhaustive |
| Échappement | Twig à échappement automatique ; les `|raw` des composants portent sur des blocs `{% set %}` capturés (déjà échappés) ou des attributs littéraux ; aucune donnée citoyenne dans `innerHTML` côté PWA (tout passe par `textContent`) | `request_list.html.twig:11-14`, `field.html.twig:17`, `assets/controle/app.js:2,31` |
| PDF | dompdf : ressources distantes, PHP et JavaScript désactivés, `chroot` sur les gabarits, QR en data-URI, jamais stocké | `DompdfPassPdfRenderer.php` |
| Secrets en base | Secrets bénéficiaires chiffrés (XChaCha20-Poly1305, données associées liant le message, le type et le laissez-passer) ; jetons de lien, d'invitation, de réinitialisation, d'appareil et d'activation : empreintes SHA-256 seules | `SecretCipher.php` ; `AccessSecret.php`, `DeviceSecret.php`, `ActivationCode.php` |

## 3. Constats détaillés

### 11.2-F1 — Compte super-administrateur CWS sans second facteur (Élevée)

- **Constat.** L'accès à `/admin` exige l'authentification complète et `ROLE_SUPER_ADMIN`, mais rien d'autre : pas de second facteur, pas de restriction d'adresse, politique de mot de passe de 12 caractères sans autre règle ni contrôle de mots de passe compromis. Aucune bibliothèque de second facteur n'est installée.
- **Pourquoi c'est élevé.** Le super-administrateur n'a pas de droit communal implicite, mais les actions `/admin/cws/…` lui permettent d'ouvrir une commune, d'**inviter son administrateur**, de modifier l'accès commercial et d'exécuter une clôture exceptionnelle. Un compte CWS compromis permet donc de se désigner administrateur d'une commune en attente puis d'accéder à ses dossiers : c'est le compte dont la compromission a le plus grand rayon d'effet. Atténuants existants : inactivité 15 min, durée absolue 8 h, limiteur de connexion, actions CWS avec jeton CSRF propre et audit.
- **Preuves.** `config/packages/security.yaml` (règle `^/admin(?:/|$)`) ; `CwsAdministrationController.php:93-196` (`admin_cws_open_do`, `admin_cws_invite`) et `:217` (`admin_cws_execute`) ; `PlainPasswordPolicy.php` ; `composer.json` (aucun paquet de second facteur).
- **Correction recommandée.** (1) Avant le pilote : second facteur (TOTP ou WebAuthn) **obligatoire pour le super-administrateur**, et si possible pour les administrateurs communaux ; **c'est une décision S3 du LOT 11, et un petit chantier fonctionnel** (hors périmètre « corrections P0/P1 » si le commanditaire ne l'arbitre pas comme P1). (2) Mesure sans code, dès la recette : restreindre `/admin` par liste d'adresses ou par VPN au niveau Nginx ; comptes nominatifs distincts ; alerte e-mail à chaque connexion super-administrateur. (3) Rejeu possible de SEC-001 et du limiteur sur la cible en 11.4.

### 11.2-F2 — Dépôt public : soumission non limitée, accusé de réception vers n'importe quelle adresse (Moyenne)

- **Constat.** Seul le **téléversement** est limité (`public_request_upload_ip`, 40 fichiers / 15 min / IP). Aucune limite ne s'applique au dépôt d'une demande ni à l'envoi de l'accusé de réception, et il n'existe ni délai minimal de remplissage, ni champ piège, ni dispositif anti-automatisation. L'accusé part vers l'adresse saisie par le citoyen.
- **Impact.** (a) Un script peut déposer un grand nombre de demandes (pollution de la file d'instruction, saturation des listes et des purges). (b) Il peut les déposer avec l'adresse d'un tiers : **envoi d'e-mails non sollicités depuis le domaine de la plateforme** (réputation SMTP, SPF/DKIM, quotas du relais). Le contenu de l'accusé est minimal (référence, nom de l'événement) : pas de fuite de données.
- **Preuves.** `framework.yaml` (limiteurs : aucun pour le dépôt) ; `RequestFormServices.php:36-37` ; `AbstractRequestFormController.php:224` (`sendReceipt` sans limite) ; recherche `captcha|honeypot|turnstile` sans résultat dans `src/`, `templates/` et `docs/`.
- **Correction recommandée.** Limiteurs `public_request_submit` par IP (ex. 10 dépôts / heure) et par adresse destinataire (ex. 3 accusés / 24 h, au-delà : dépôt accepté sans accusé, message neutre) ; champ piège et délai minimal de saisie ; **pas de CAPTCHA tiers** (la CSP et la minimisation RGPD l'excluent) sans décision. Stockage strict (voir F5). Mesure de trafic en recette (`ops/recette/charge-verification.sh` à étendre).

### 11.2-F3 — Origine de gestion sans en-têtes de sécurité globaux (Moyenne) — recoupe 11.1

- **Constat.** Relevé local de `/login` : `Cache-Control: no-store`, `X-Robots-Tag: noindex` et le cookie, **mais ni `X-Frame-Options` ni `frame-ancestors`, ni CSP, ni `X-Content-Type-Options`, ni `Referrer-Policy`**. Le gabarit Nginx de production n'ajoute que HSTS ; ces en-têtes n'existent que sur les pages du contrôle public, du lien bénéficiaire, de l'origine de contrôle et de la réinitialisation. L'espace municipal, Sonata, l'invitation et le formulaire public sont donc encadrables (clickjacking) et sans CSP.
- **Atténuant.** `SameSite=Lax` empêche l'envoi du cookie dans un cadre inter-sites : le clickjacking d'une action authentifiée n'est pas exploitable depuis un site tiers dans les navigateurs actuels (il le reste depuis un sous-domaine du même site : d'où l'importance de ne pas héberger d'autre contenu sous le domaine). Le formulaire public (non authentifié) reste encadrable.
- **Preuves.** `curl` local (en-têtes ci-dessus) ; `grep` : `X-Frame-Options`/`frame-ancestors` seulement dans `PublicVerificationHeadersListener.php`, `BeneficiaryPassController.php`, `ControlHeadersListener.php` et `20-civipass-control.conf.template` ; `10-civipass-site.conf.template:48-50` (HSTS seul).
- **Correction recommandée.** En-têtes communs sur l'origine de gestion (Nginx `add_header … always`, **à ne pas placer dans un `location`** : voir la note de `10-civipass-site.conf.template`) : `X-Frame-Options: DENY` + `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` ou `no-referrer`, `Permissions-Policy` ; CSP progressive (`default-src 'self'` à valider avec Stimulus/Turbo). À consolider avec le constat correspondant de 11.1.

### 11.2-F4 — Garde-fous G1–G3 non liés à l'activation du contrôle hors connexion (Moyenne)

- **Constat.** `CONTROL_HOST` vaut vide par défaut ; G1 (liste blanche de routes) et G3 (refus de `/controle` sur la gestion) sont alors inactifs, sans lien avec `OFFLINE_CONTROL_ENABLED`. Avec `OFFLINE_CONTROL_ENABLED=1` et `CONTROL_HOST` vide, la PWA, son Service Worker, son IndexedDB (nom, prénom, plaques, secret d'appareil) et le cookie de session des agents partagent **la même origine** : toute faille de script de l'application de gestion exposerait le stockage local, ce que l'AIPD technique pose comme condition (§6 : « origine dédiée et CSP stricte »). `check-deployment.sh` ne contrôle ces réglages que si l'option `--control-domain` est passée, et ne fait qu'**informer** de l'activation du contrôle.
- **Preuves.** `ControlOriginListener.php:35-38` ; `services.yaml:27,48` ; `check-deployment.sh:7-8,41,53` ; `docs/aipd-controle-hors-connexion-technique.md` §6 et §10.
- **Correction recommandée.** Échec fermé : `ControlSettings` (ou un contrôle au démarrage) refuse d'activer le contrôle hors connexion en `APP_ENV=prod` si `CONTROL_HOST` est vide (réponse 404 uniforme + journal), et `check-deployment.sh` doit échouer si `OFFLINE_CONTROL_ENABLED` est actif sans `--control-domain`. À rejouer sur la cible avec la recette « deux origines » (11.4).

### 11.2-F5 — Limiteurs sur un stockage qui ignore l'échec d'écriture (Moyenne)

- **Constat.** Les limiteurs du contrôle (public et hors connexion) utilisent `StrictCacheStorage` : une écriture impossible lève une erreur visible (503). Ceux de la **connexion**, de la **réinitialisation**, des **invitations**, du **téléversement public** et du **lien bénéficiaire** utilisent le stockage standard de Symfony, dont le docblock de `StrictCacheStorage` rappelle qu'il « ignore le résultat de `save()` » : sur un disque plein, un système de fichiers en lecture seule ou un répertoire de cache devenu inaccessible, la limitation **cesse sans aucun signal**.
- **Scénario plausible en exploitation.** Une mise à jour exécutée en `root` (`cache:clear`, `cache:warmup`) rend `var/cache/prod` inaccessible en écriture à l'utilisateur du pool PHP-FPM : l'application continue de servir (le conteneur compilé est lu), mais le limiteur de connexion n'enregistre plus rien (essais illimités).
- **Preuves.** `framework.yaml` (limiteurs `login_*`, `password_reset_*`, `invitation_*`, `pass_*`, `public_request_upload_ip` sans `storage_service`) ; `StrictCacheStorage.php:12-18` (docblock).
- **Correction recommandée.** `storage_service: StrictCacheStorage` sur ces limiteurs (échec visible plutôt que silence) ; contrôle post-installation : sixième échec de connexion → 429, et propriétaire de `var/cache` et `var/storage` vérifié par `check-deployment.sh` ; procédure de mise à jour exécutée sous l'utilisateur applicatif (à reporter dans le guide de mise à jour). Noter que `cache:clear` remet aussi les compteurs à zéro (Faible).

### 11.2-F6 — Appareil de contrôle volé : secret utilisable à distance, durée de vie de 72 h (Moyenne)

- **Constat.** Le secret d'appareil (256 bits) est stocké en clair dans IndexedDB (assumé, DM-132 §6) et authentifie par `Authorization: Bearer` sans lien avec l'appareil, l'adresse ou le réseau. Un attaquant qui l'extrait d'un téléphone **déverrouillé** peut appeler `/controle/api/sync` **depuis n'importe quelle machine** et récupérer un jeu **frais** (noms et plaques de la commune, fenêtres jusqu'à `hard`) tant que l'appareil n'est pas désactivé ou expiré. La durée de vie par défaut est de **72 h** (`CONTROL_DEVICE_LIFETIME_HOURS`), configurable jusqu'à **14 jours**, alors que la confiance sur les données est de 12 h : la fenêtre d'extraction est six fois plus longue que celle de l'usage. Aucune désactivation automatique ne découle du retrait de l'agent qui a approuvé l'appareil, ni d'un changement de droits de cet agent. La désactivation (« perdu », « compromis ») est manuelle et ne vaut qu'à la reconnexion de l'appareil.
- **Atténuants.** Approbation manuelle avec code d'appariement ; jeu limité à la commune et à l'événement de l'activation ; événement clos → jeu vide ; limiteurs par appareil ; aucune donnée de contact ; accès de l'attaquant borné par l'expiration ; désactivation immédiate côté serveur (le secret cesse de fonctionner dès la désactivation, y compris hors de l'appareil volé).
- **Preuves.** `services.yaml` (`CONTROL_DEVICE_LIFETIME_HOURS: 72`) ; `ControlSettings.php` (`min(24 * 14, …)`) ; `ControlApiController.php:137-157` ; `DeviceSyncService.php:50-65` ; `idb-store.js` (secret dans `kv/credential`) ; `DeactivationReason.php` ; [aipd-controle-hors-connexion-technique.md](aipd-controle-hors-connexion-technique.md) §6 (limite reconnue) ; [ecarts-techniques-connus.md](ecarts-techniques-connus.md) DT-C6.
- **Correction recommandée.** (1) Au pilote : `CONTROL_DEVICE_LIFETIME_HOURS` aligné sur la durée de l'événement (24 à 48 h) et plafond de configuration abaissé (ex. 72 h) ; (2) consigne et runbook « appareil perdu » : désactivation immédiate, avec revue des appareils actifs à chaque fin de poste ; (3) alerte sur une synchronisation depuis une adresse très différente de la précédente (évolution, hors V1 si le commanditaire le juge disproportionné) ; (4) si possible, désactivation des appareils approuvés par un agent dont le rôle est retiré. Le risque résiduel est à inscrire dans l'acceptation écrite de R-OFF-01.

### 11.2-F7 — Prise de contrôle d'une commune sans administrateur par le lien « contact » (Moyenne)

- **Constat.** L'adresse de contact d'une commune invitée est saisie **librement** par la commune organisatrice. Le titulaire de cette boîte peut, si la commune n'a pas encore d'administrateur, désigner l'administrateur communal ; l'habilitation à représenter la mairie n'est pas vérifiée (attestation par case à cocher). Une adresse erronée ou malveillante peut donc conférer l'administration d'une commune encore « en attente d'administrateur ».
- **Preuves.** [invitations-intercommunales.md](invitations-intercommunales.md) §2-3 (risque résiduel déjà reconnu) ; `InvitationLinkController.php:96-130,140-175`.
- **Correction recommandée.** Décision métier, pas un défaut de code : (1) imposer que la délégation passe par une commune **ouverte par CWS avec premier administrateur désigné** (pas d'auto-désignation) ; ou (2) validation par CWS de toute première désignation ; ou (3) accepter le risque avec journalisation de la désignation et notification à CWS. Compte tenu de F1, privilégier (1) ou (2) pour le pilote.

### 11.2-F8 — Énumération des événements ouverts (Faible)

- **Constat.** La référence publique `EVT-AAAA-NNNNNN` est séquentielle ; `/demandes/{référence}/…` répond pour tout événement hors brouillon sans limite de débit : un parcours des numéros révèle les noms des événements et les communes concernées de toute la plateforme.
- **Preuves.** `PublicRequestController.php:34,65,100-105` ; `framework.yaml` (aucun limiteur sur ces routes) ; DT-025 (génération de la référence).
- **Correction recommandée.** Confirmer par décision que la liste des événements non brouillon est publique par conception ; sinon limiteur par IP sur les routes `/demandes/*` et réponse uniforme pour les événements clos ou suspendus. Priorité P2.

### 11.2-F9 — Justificatifs : pas d'antivirus, format DOC accepté (Faible)

- **Constat.** Le contrôle porte sur la structure, pas sur le contenu actif : un PDF peut embarquer du JavaScript ou des fichiers, un DOC (OLE) peut contenir des macros. Les fichiers sont servis en pièce jointe avec `nosniff` et jamais interprétés par l'application ; le risque se matérialise **à l'ouverture sur le poste de l'agent**.
- **Preuves.** `UploadedDocumentInspector.php:42-58` ; `MunicipalRequestAttachmentController.php:52-56` ; aucune référence à un moteur antivirus.
- **Correction recommandée.** Décision : retirer le format DOC (OLE) de la liste, ou analyser avec ClamAV (dépendance d'infrastructure, à autoriser), ou consigne aux agents (ouvrir dans un lecteur sans macros) et mention dans le dossier RGPD. P2.

### 11.2-F10 — « Mot de passe oublié » : écart de temps non mesuré (Faible)

- **Constat.** La réponse est identique et l'e-mail part après la réponse. Pour un compte existant et actif, le serveur exécute en plus une transaction (verrou utilisateur, invalidation des demandes, insertion). SEC-001 a mesuré et corrigé le seul chemin de connexion ; ce chemin-ci n'a pas été mesuré. Les limiteurs (3 demandes / h / adresse, 10 / h / IP) bornent l'exploitation.
- **Preuves.** `PasswordResetService.php:46-70` ; `DeferredMailQueue.php:12-14` ; [registre-securite.md](registre-securite.md) (SEC-001).
- **Correction recommandée.** Mesurer en 11.4 selon le protocole de SEC-001 (distributions entrelacées) ; si un écart systématique apparaît, exécuter un travail factice équivalent pour les adresses inconnues. P2.

### 11.2-F11 — Journalisation : contexte `Stringable` et rotation du journal PHP (Faible)

- **Constat.** `SecretScrubbingLogger::scrubValue` ne traite que chaînes et tableaux ; un objet `Stringable` (une exception, par exemple) dans le contexte n'est pas masqué. Sans impact aujourd'hui : le journal du framework n'imprime pas ce contexte (`Logger::format` ne remplace que les `{…}` du message) et aucun gestionnaire Monolog n'est installé. À reprendre si un gestionnaire de journaux est ajouté. Par ailleurs la rotation de `/var/log/civipass/php/php-error.log` n'est pas dans `logrotate-civipass-nginx.conf.template` ; elle peut être traitée par le kit d'installation en préparation (à vérifier).
- **Preuves.** `SecretScrubbingLogger.php:27-69` ; `vendor/symfony/http-kernel/Log/Logger.php` (`format`) ; `composer.json` (pas de Monolog) ; `logrotate-civipass-nginx.conf.template`.
- **Correction recommandée.** Test de non-régression (« aucun jeton dans le journal de bout en bout », y compris exception) ; rotation du journal PHP ; surveillance de la taille (une rafale de 404 écrit une ligne `error` par requête).

### 11.2-F12 — Cookie de session : `Secure` automatique et nom par défaut (Faible)

- **Constat.** `cookie_secure: auto` repose sur le paramètre FastCGI `HTTPS` (correct dans le gabarit) ; un défaut de configuration le désactiverait sans signal. Le nom `PHPSESSID` et l'absence de préfixe `__Host-` n'ont pas d'impact direct.
- **Correction recommandée.** `cookie_secure: true` en production ; nom `__Host-…` après vérification du chemin et du domaine ; contrôle post-installation : `Set-Cookie` de `/login` porte `Secure; HttpOnly; SameSite=Lax`. P3.

### 11.2-F13 — `check-deployment.sh` incomplet (Faible)

- **Constat.** Il contrôle `APP_ENV=prod`, `APP_DEBUG`, `TRUSTED_HOSTS`, `DEFAULT_URI` en HTTPS, l'absence de proxy de confiance et, avec `--control-domain`, `CONTROL_HOST` et `CONTROL_PWA_BASE_URL`. Il ne contrôle pas : `APP_SECRET` (présent, au moins 32 caractères, non exemple) ; `DATABASE_URL` (≠ valeur d'exemple, mot de passe non vide) ; `PRIVATE_STORAGE_DIR` (hors `public/`, propriétaire, droits 0700) ; propriétaire de `var/cache`, `var/log`, `var/storage` (voir F5) ; les trois interrupteurs `*_ENABLED`. Le `.env` du dépôt porte `APP_ENV=dev`, `APP_SECRET=` vide et `DATABASE_URL` avec `!ChangeMe!` : seul le contrôle empêche un oubli.
- **Preuves.** `ops/nginx/bin/check-deployment.sh:35-53` ; `.env:18-19,33`. Le script `ops/install/bootstrap-debian.sh` (kit en préparation) génère `APP_SECRET` (`openssl rand -hex 32`) : à confirmer à la recette.
- **Correction recommandée.** Ajouter ces contrôles au script (ou à `ops/install/preflight.sh`). P2.

### 11.2-F14 — Réglages PHP de téléversement (Info)

L'exemple de pool PHP-FPM existant (`civipass-pool.conf.example`) et l'exemple de sessions ne fixent ni `upload_max_filesize`, ni `post_max_size`, ni `max_file_uploads` ; avec les valeurs par défaut de PHP (2 Mo / 8 Mo), les justificatifs de 10 Mo (5 pièces) seraient refusés. Nginx (`client_max_body_size 64m`) et le conteneur de développement (10 M / 64 M / 20) sont corrects. Le script du kit en préparation (`ops/install/bootstrap-debian.sh:130-132`) pose 10 M / 64 M / 20 : **à vérifier à la recette** (dépôt d'un fichier de 10 Mo et de cinq pièces).

## 4. Modèle de menace — contrôle hors connexion (STRIDE)

Référence : [lot-09-controle-hors-connexion-cadrage.md](lot-09-controle-hors-connexion-cadrage.md), [aipd-controle-hors-connexion-technique.md](aipd-controle-hors-connexion-technique.md), DM-129 à DM-132, DT-056/057.

### 4.1 Actifs, acteurs et frontières de confiance

- **Actifs** : copie locale (noms, prénoms, plaques, zones, fenêtres) ; secret d'appareil ; code d'activation et code d'appariement ; jeu de données serveur ; intégrité du verdict.
- **Frontières** : (1) téléphone de l'agent (hors maîtrise de Civipass) ; (2) origine de contrôle (idéalement dédiée) ; (3) API d'appareil sans session ; (4) origine de gestion (cookies de session) ; (5) page `/v/{jeton}` en ligne.
- **Acteurs** : agent légitime ; tiers qui trouve ou vole un téléphone ; contrôleur malveillant ; attaquant réseau (TLS) ; attaquant applicatif (script injecté) ; administrateur communal négligent.

### 4.2 Analyse

| Menace | Scénario | Mesures en place (preuves) | Résiduel | Statut |
|---|---|---|---|---|
| **S**poofing — appareil | Usurper un appareil approuvé | Secret 256 bits en empreinte SHA-256 ; approbation manuelle par code d'appariement ; code d'activation 12 caractères (60 bits), 30 min, usage atomique ; 10 échecs / 15 min / IP ; réponse 401/403 uniforme (`ControlApiController.php:155-166`, `DeviceSecret.php`, `ActivationCode.php`) | Secret volé réutilisable à distance (F6) | **F6** |
| S — QR | Forger un QR | Jeton 160 bits aléatoire, empreinte SHA-256 dans le jeu, URL de l'origine configurée exigée, nom d'utilisateur/mot de passe et chemin hors forme refusés (`scan.js:17-35`) ; jeton jamais conservé | QR copié = droits copiés (DM-115 §7, accepté) | Accepté |
| S — origine | Faux domaine de contrôle | HTTPS, HSTS, `TRUSTED_HOSTS` (G2), 421 pour SNI/Host non reconnu (kit Nginx) | Dépend de la cible | 11.4 |
| **T**ampering — jeu | Altérer ou rejouer une réponse de synchronisation | Condensat racine et condensats de compartiments vérifiés avant remplacement, atomicité IndexedDB, réponse altérée → ancien jeu conservé et âge croissant (`sync.js:60-79`) ; HTTPS | Intégrité non signée : protège contre la corruption, pas contre un terminal compromis ni un attaquant TLS ; un ancien jeu rejoué est borné par l'âge | Résiduel documenté |
| T — stockage local | Modifier IndexedDB (outils de développement, malware) | Aucune confiance dans l'appareil : verdict toujours qualifié « selon la dernière synchronisation », jamais vert, jamais « vérifié » (`verdict.js:1-8,100-110`) | L'utilisateur du téléphone maîtrise sa propre copie | Accepté |
| T — horloge | Reculer l'horloge pour prolonger la validité | Écart à la synchronisation, recul entre deux usages, dérive en session détectés (`clock.js`) → « indéterminé » | **Avance** de l'horloge application fermée non détectable (horizon ≤ 12 h) ; reculer ne sert qu'au contrôleur lui-même | Résiduel documenté (DT-C4) |
| **R**epudiation | « Je n'ai pas contrôlé » / « on a validé à tort » | Aucun journal de scan par décision (DM-127, DM-132) ; audit des activations, approbations et désactivations ; refus de synchronisation audité au plus une fois par heure | Aucune preuve de contrôle côté serveur ; à assumer | Décision de principe |
| **I**nformation disclosure — appareil perdu | Lecture des noms et plaques sur un téléphone déverrouillé | Minimisation (commune et événement), horizon, purge à l'ouverture après `hard`, purge à la désactivation/expiration/clôture, pas de chiffrement annoncé (`purge.js`, DM-132 §6) | Aucune purge sur un appareil jamais rouvert ; sauvegardes de l'appareil non vérifiées ; chiffrement du téléphone = consigne | Résiduel, à accepter (AIPD) |
| I — origine partagée | Script injecté lisant IndexedDB | CSP `script-src 'self'`, sans `unsafe-inline` ni `unsafe-eval`, pas de WebAssembly, `connect-src 'self'`, `frame-ancestors`, aucune ressource tierce ; aucun `innerHTML` ; jsQR local avec empreinte vérifiée par test (`ControlHeadersListener.php`, `assets/controle/*.js`) | Origine **non dédiée par défaut** | **F4** |
| I — fuite du jeton / code | Code d'activation dans les journaux ou l'historique | Code dans le **fragment** (jamais transmis), retiré de l'adresse par `replaceState` ; jeton `/v/` masqué dans les journaux Nginx et applicatifs ; `Referrer-Policy: no-referrer` | Historique du navigateur du contrôleur (accepté) | Accepté |
| I — Service Worker | Mise en cache de données personnelles | Seule la coquille (identique pour tous) est conservée ; l'API n'est jamais interceptée ; versions anciennes supprimées (`sw.js.twig`) | — | Conforme |
| **D**enial of service | Saturer l'API d'appareil ou la synchronisation | Limiteurs stricts par IP (/64 en IPv6), par commune (10 codes / h), par appareil (60 synchronisations / 10 min) ; stockage strict (503 visible) ; corps JSON borné à 8 Ko ; calcul du jeu mesuré à 4,8 s pour 10 000 laissez-passer et non mis en cache (DT-C1/C2) | Plusieurs appareils d'une même commune synchronisant en même temps sur un très gros volume ; IP partagée | À mesurer en charge (11.4) |
| **E**levation of privilege | Un appareil lit les données d'une autre commune, ou d'un autre événement | La commune et l'événement viennent de l'**activation**, jamais du client (`DatasetBuilder.php:48-52`) ; aucun jeu pour un appareil non approuvé, désactivé ou expiré (`DeviceSyncService.php:50-57`) ; événement clos → jeu vide | Matrice d'isolation à rejouer avec identifiants forgés (11.1) | 11.1 |
| E — gestion | Un administrateur approuve un appareil frauduleux | Code d'appariement montré sur l'appareil, approbation par administrateur de la commune authentifié en **authentification complète** (`security.yaml`, règle `…/controle/.+`) | Vigilance humaine | Accepté |

### 4.3 Garde-fous G1 à G3

| Garde-fou | Rôle | État | Réserve |
|---|---|---|---|
| **G1** — liste blanche de routes sur l'hôte de contrôle | 404 générique avant routage, sécurité et base de données | Implémenté (`ControlRouteAllowlist.php`, `ControlOriginListener.php:42-46`), synchronisé avec le gabarit Nginx par un test | Inactif si `CONTROL_HOST` est vide (F4) |
| **G2** — hôtes de confiance | Refus (400) d'un en-tête `Host` inconnu | Implémenté (`framework.yaml`, `trusted_hosts`) | `^.*$` par défaut ; contrôlé par `check-deployment.sh` seulement avec `--control-domain` |
| **G3** — refus de `/controle` sur l'origine de gestion | La PWA ne s'installe jamais là où vivent les cookies de session | Implémenté (`ControlOriginListener.php:48-50`) | Même réserve que G1 |

Tous les trois n'ont été validés **qu'avec tests et gabarits** : la preuve sur un serveur réel (deux origines, certificat par sous-domaine, 421) relève de 11.4.

### 4.4 Risque R-OFF-01 (révocation invisible hors ligne)

- **Définition.** Une révocation, suspension, remplacement de QR ou clôture anticipée postérieure à la dernière synchronisation d'un appareil est invisible de cet appareil hors ligne.
- **Borne technique relevée dans le code.** Le verdict devient « indéterminé » (jamais « valide ») quand l'âge du jeu dépasse le seuil dur : 12 h au pilote, plafond de configuration 12 h, plafond technique 24 h ; plancher de configuration d'une commune 2 h ; bande « données anciennes » après l'avertissement (6 h). La synchronisation applicative est de 15 min lorsque le réseau est disponible. Le mode STRICT supprime tout verdict positif. Les empreintes de laissez-passer révoqués ou remplacés restent dans le jeu pendant la période où le QR pourrait encore être présenté (`verdict.js:85-97`, `DatasetBuilder.php`, `ControlSettings.php`).
- **Pire cas** : un laissez-passer révoqué juste après une synchronisation reste accepté jusqu'à 12 h sur un appareil qui n'a plus de réseau, avec une qualification d'âge affichée à l'agent (« selon des données anciennes » après 6 h).
- **Limite non couverte.** Une **prolongation ou un ajout de droits** postérieurs entraînent à l'inverse un verdict « non valide » ou « inconnu » (décision erronée inverse) : l'agent doit contrôler en ligne (consigne).
- **Statut.** Risque inhérent à la décision du 17 novembre 2026 ; son acceptation **écrite** (annexe A de l'AIPD technique) reste une décision en attente du commanditaire.

## 5. Révocation et expiration — matrice de vérification

| Mécanisme | Durée / délai d'effet | Où est-ce appliqué ? | Preuve | Réserve |
|---|---|---|---|---|
| Session agent / CWS | Inactivité 30 min / 15 min ; absolue 12 h / 8 h (bornes 5 min–2 h, 24 h) | Serveur, chaque requête authentifiée du pare-feu `main` | `SessionLifetimeListener.php`, `SessionLifetime.php` | Un compte peut avoir plusieurs sessions (DM-138, décision) ; aucune liste de sessions |
| Désactivation d'un compte, changement de mot de passe, perte du rôle super-admin | À la requête suivante | `isEqualTo` + `refreshUser` | `User.php:129-136`, `UserProvider.php:46-57` | Test d'intégration dédié non repéré pendant cette revue ; à couvrir en 11.1 |
| Retrait d'un rôle communal | À la requête suivante | Droits relus par requête depuis le membership (règle d'architecture n° 4) | non vérifié ligne à ligne ici | À confirmer par la matrice d'autorisation 11.1 |
| Jeton de réinitialisation | 30 min, usage unique, demandes précédentes invalidées | Serveur | `PasswordResetService.php` | — |
| Invitation (contact / administrateur / CWS) | 14 j réutilisable / 3 j usage unique / 7 j | Serveur, au contrôle du lien | `InvitationLink.php:29-30`, `MunicipalityAdminInvitation.php:32` | Contact réutilisable jusqu'à l'expiration (F7) |
| Lien bénéficiaire | Fin planifiée + 30 j ; fermé à la clôture ; révocation prioritaire ; sans fin planifiée, valable jusqu'à clôture | Calculé à l'usage depuis l'événement | `AccessExpiry.php`, `PermitAccessToken.php` | Événement sans fin planifiée : valable indéfiniment jusqu'à clôture (alerte de dormance existante) |
| QR / `/v/{jeton}` en ligne | Immédiat : droits évalués à chaque requête, `no-store` | Serveur | `PublicVerificationHeadersListener.php`, moteur de droits | — |
| Remplacement du QR | Immédiat en ligne ; hors ligne : à la synchronisation | Serveur / jeu de données | `DatasetBuilder.php` (empreintes remplacées) | R-OFF-01 |
| Code d'activation | 10–60 min (30 par défaut), usage atomique, 10 appareils au plus | Serveur | `ControlSettings.php`, `ActivationCode.php` | — |
| Appareil en attente | 60 min (5–240) | Serveur | `ControlSettings.php` | — |
| Appareil actif | 72 h par défaut (1 h–14 j) | Serveur, à la synchronisation | `services.yaml`, `ControlDevice.php:226-229` | **F6** |
| Désactivation d'un appareil | Côté serveur immédiat (le secret cesse de fonctionner) ; côté téléphone : à la prochaine connexion | Serveur / PWA | `ControlApiController.php:178-188`, `sync.js:44-55` | Téléphone jamais reconnecté : données jusqu'à purge à l'ouverture |
| Données locales | Seuil dur 12 h (plafond 24 h) → purge à l'ouverture de l'application | PWA | `purge.js`, `sync.js:17-27` | iOS : pas d'exécution en arrière-plan (DT-C3) |

## 6. Décisions et arbitrages nécessaires

1. **F1** — second facteur obligatoire pour le super-administrateur CWS avant le pilote (décision S3), ou restriction d'accès à `/admin` en attendant ; périmètre : super-administrateur seul ou aussi administrateurs communaux.
2. **F7** — qui peut désigner le premier administrateur d'une commune : CWS seulement, ou le contact avec validation CWS.
3. **F6** — durée de vie d'un appareil au pilote et plafond de configuration ; ajout d'une alerte de changement d'adresse (oui/non).
4. **F2** — ampleur de l'anti-automatisation acceptée au dépôt public (limiteurs seuls, ou aussi champ piège et délai minimal) ; refus d'un CAPTCHA tiers à confirmer.
5. **F9** — maintien du format DOC ; antivirus (infrastructure) ou consigne.
6. **F8** — confirmation que la liste des événements ouverts est publique par conception.
7. Acceptation écrite de R-OFF-01 (déjà au plan, §6 décision 5).

## 7. Renvois hors périmètre

- **11.1** : matrices d'isolation et d'autorisation avec identifiants forgés (y compris retrait d'un rôle communal, test d'invalidation de session), en-têtes de sécurité complets (F3), audit des dépendances (dompdf, jsQR, Sonata), recherche de secrets.
- **11.3** : durées de conservation des journaux, de l'outbox et des pièces ; information des personnes sur la copie locale du contrôle hors connexion.
- **11.4** (serveur cible) : SEC-001, F10, F12, deux origines et 421, charge de la synchronisation, rotation des journaux, test 429 de connexion (F5).
- **Kit d'installation** (flux en cours) : F5 (propriétaires), F13 (contrôles), F14 (réglages PHP), F11 (rotation du journal PHP).
