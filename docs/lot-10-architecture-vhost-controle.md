# LOT 10 / 09.7 — Architecture du vhost Nginx dédié au contrôle (décision I4)

Statut : **conception, aucun déploiement ni travail d'infrastructure distante**. Décision du commanditaire (18 novembre 2026) : second vhost Nginx HTTPS dédié au contrôle, **sur le même serveur et la même application Symfony**, avec liste explicite des routes, séparation des cookies, des sessions et des autorisations, et en-têtes de sécurité vérifiés. S'appuie sur DT-056 (point 4 : origine dédiée pour isoler le stockage local), DM-129, `ops/nginx/` et le protocole [lot-10-protocole-09-7.md](lot-10-protocole-09-7.md).

## 1. Principe

```
                    ┌────────────── même serveur ───────────────┐
 Navigateur  ──443──►  nginx                                      │
 (agents)           │   vhost A  civipass.example         ───┐    │
                    │   vhost B  controle.civipass.example ──┼──► PHP-FPM (même pool, même
 PWA (iPhone,       │   (liste blanche de routes)            │    socket, même application)
 Android)  ──443────►                                        ┘    │
                    └───────────────────────────────────────────┘
```

Deux **origines** (`https://civipass.example`, `https://controle.civipass.example`) : le stockage local de la PWA (IndexedDB, Cache Storage, Service Worker) est séparé de celui de l'application de gestion ; aucun cookie n'est partagé ; l'application de contrôle n'expose qu'une liste fermée de routes.

## 2. Routes accessibles depuis l'origine de contrôle (liste explicite)

Source : routes `app_control_*` de l'application (`debug:router`) et ressources du shell relevées sur `/controle/` et dans la liste de précache du Service Worker.

| Chemin | Méthode | Rôle | Authentification |
|---|---|---|---|
| `/controle/` | GET | Coquille de l'application | aucune (aucune donnée) |
| `/controle/manifest.webmanifest` | GET | Manifeste (`start_url` et `scope` = `/controle/`) | aucune |
| `/controle/sw.js` | GET | Service Worker (`Service-Worker-Allowed: /controle/`, `no-cache`) | aucune |
| `/controle/js/{nom}.js` | GET | Modules de l'application (15 fichiers) | aucune |
| `/controle/api/ping` | GET | Test de connectivité | aucune |
| `/controle/api/activation` | POST | Activation avec code (limiteur, réponse uniforme) | code d'activation à usage limité |
| `/controle/api/device` | GET | État de l'appareil | secret d'appareil (`Authorization`) |
| `/controle/api/sync` | POST | Synchronisation du jeu de données | secret d'appareil (`Authorization`) |
| `/assets/styles/…`, `/assets/vendor/jsqr/…`, `/assets/images/control-icon-…`, `/assets/fonts/…` | GET | Ressources statiques compilées (CSS, lecteur de QR, icônes, polices) | aucune (fichiers publics) |

**Tout le reste répond 404** sur cet hôte : `/login`, `/communes/…`, `/admin/…`, `/demandes/…`, `/v/…`, `/laissez-passer/…`, `/invitation/…`, `/dev/…`. Les pages d'administration du contrôle (création de codes, approbation, désactivation) restent **sur l'origine de gestion** (`/communes/{id}/evenements/{id}/controle…`, authentification complète).

À vérifier avant la mise en œuvre : les polices de `/assets/fonts/` ne figurent pas dans le précache du Service Worker (observation pour 09.7 : rendu hors connexion de la première ouverture).

## 3. Vhost Nginx (esquisse du futur gabarit `ops/nginx/templates/20-civipass-control.conf.template`)

À réaliser **seulement après autorisation** ; variables `__CIVIPASS_CONTROL_DOMAIN__`, certificat propre ou SAN.

```nginx
server {                                   # redirection HTTP → HTTPS (et défi ACME)
    listen 80; listen [::]:80;
    server_name __CIVIPASS_CONTROL_DOMAIN__;
    location /.well-known/acme-challenge/ { root __ACME_WEBROOT__; }
    location / { return 301 https://__CIVIPASS_CONTROL_DOMAIN__$request_uri; }
}
server {
    listen 443 ssl; listen [::]:443 ssl; http2 on;
    server_name __CIVIPASS_CONTROL_DOMAIN__;
    # TLS identique au vhost de gestion (TLS 1.2/1.3, mêmes suites, pas de tickets)
    add_header Strict-Transport-Security "max-age=31536000" always;   # sans includeSubDomains ni préchargement
    root __DOCROOT__;

    location ~ /\.(?!well-known/) { return 404; }

    # Liste blanche exacte (méthodes limitées) → contrôleur frontal
    location = /controle/                        { limit_except GET { deny all; } include snippets/civipass-front.conf; }
    location = /controle/manifest.webmanifest    { limit_except GET { deny all; } include snippets/civipass-front.conf; }
    location = /controle/sw.js                   { limit_except GET { deny all; } include snippets/civipass-front.conf; }
    location ~ ^/controle/js/[a-z-]+\.js$        { limit_except GET { deny all; } include snippets/civipass-front.conf; }
    location = /controle/api/ping                { limit_except GET  { deny all; } include snippets/civipass-front.conf; }
    location = /controle/api/device              { limit_except GET  { deny all; } include snippets/civipass-front.conf; }
    location = /controle/api/activation          { limit_except POST { deny all; } include snippets/civipass-front.conf; }
    location = /controle/api/sync                { limit_except POST { deny all; } include snippets/civipass-front.conf; }

    # Ressources statiques : fichiers existants seulement, jamais le contrôleur frontal
    location ~ ^/assets/(styles|vendor/jsqr|images/control-icon-|fonts)/ {
        try_files $uri =404; expires 1M; access_log off;
        add_header X-Content-Type-Options nosniff always;
        add_header Strict-Transport-Security "max-age=31536000" always;   # un add_header de location annule l'héritage
    }
    location / { return 404; }             # tout le reste
}
```

`civipass-front.conf` reprend le bloc `fastcgi_pass` du vhost de gestion, y compris l'effacement des en-têtes `X-Forwarded-*`/`Forwarded`/`X-Real-IP` (l'adresse du client est `REMOTE_ADDR`, nginx est le premier saut), le même pool PHP-FPM et le même hook de journaux assainis. La même contrainte reste vraie : `SYMFONY_TRUSTED_PROXIES` vide.

## 4. Séparation des cookies, des sessions et des autorisations

| Sujet | État actuel (vérifié sur la recette) | Garantie sur le vhost dédié | Vérification prévue |
|---|---|---|---|
| Cookies | `control_pwa` est un pare-feu `security: false` : **aucun `Set-Cookie`** sur `/controle/…`, ni sur le manifeste, le Service Worker, l'API | Cookies de session de la gestion = `host-only` (aucun attribut `Domain`, `cookie_domain` non défini) : **jamais envoyés** à `controle.…` ; l'origine de contrôle n'en émet aucun | Test : aucune en-tête `Set-Cookie` sur toute route de la liste ; configuration sans `cookie_domain` |
| Sessions | Aucune session créée par le contrôle | Idem ; l'authentification d'appareil se fait par secret en en-tête `Authorization` (empreinte stockée), sans session | Test : appel avec cookie de gestion ignoré ; réponse 403 sans `Authorization` |
| Autorisations | Création de codes, approbation, désactivation : pages de gestion, `IS_AUTHENTICATED_FULLY`, droit instructeur/admin de la commune ; l'API appareil est limitée au périmètre commune/événement de l'appareil | Ces pages ne sont **pas** routées sur l'origine de contrôle (404 par la liste blanche) ; l'API de contrôle n'est pas appelable avec un compte agent | Tests de routes (§ 6) ; test d'isolation commune A / B de l'appareil (déjà présent) |
| Stockage local | `localhost` en développement | Origine distincte : IndexedDB, Cache Storage et Service Worker propres (objectif DT-056) | E2E sur deux origines (par exemple `localhost` et `127.0.0.1`) : le stockage de l'une n'est pas lisible par l'autre |
| CORS | Aucun en-tête `Access-Control-*` | Aucun : la PWA appelle sa propre origine ; l'origine de gestion ne peut pas appeler l'API de contrôle depuis un navigateur | Test d'absence des en-têtes CORS |
| Adresse de la PWA affichée à l'administrateur (QR d'activation, lien) | Paramètre **`CONTROL_PWA_BASE_URL`** déjà prévu (vide : `DEFAULT_URI` + `/controle/`) | `CONTROL_PWA_BASE_URL=https://controle.civipass.example/controle/` | Test : QR et lien construits depuis ce paramètre, jamais depuis l'en-tête `Host` (déjà éprouvé pour les invitations) |

## 5. En-têtes de sécurité (origine de contrôle)

Déjà émis par l'application sur `/controle/` : `Content-Security-Policy` (`default-src 'none'`, `script-src 'self'`, `style-src 'self'`, `img-src 'self' data:`, `font-src 'self'`, `connect-src 'self'`, `manifest-src 'self'`, `worker-src 'self'`, `base-uri 'none'`, `form-action 'none'`, `frame-ancestors 'none'`), `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()`, `Cache-Control: no-cache, private` (Service Worker et scripts), `X-Robots-Tag` (Service Worker).

À ajouter côté Nginx : `Strict-Transport-Security: max-age=31536000` (sans sous-domaines ni préchargement, comme le vhost de gestion), `X-Content-Type-Options: nosniff` sur les fichiers statiques servis directement. **Piège connu du kit** : un `add_header` dans un bloc `location` supprime les en-têtes hérités du bloc `server` ; chaque `location` doit répéter ses en-têtes ou passer par un fichier commun.

À contrôler : **CSP et HSTS sur chaque réponse de la liste**, y compris 404 et 405 ; aucune CSP trop large pour le manifeste ; `Content-Type` exacts (`application/manifest+json`, `text/javascript`).

## 6. Tests et vérifications prévus (tous locaux, sans déploiement)

1. **Test d'inventaire des routes** (PHPUnit) : toute route de l'application dont le chemin commence par `/controle` doit figurer dans la liste blanche ci-dessus ; l'ajout d'une route sans décision fait échouer le test. Inversement, la liste de nginx est générée depuis la même liste.
2. **Test de configuration Nginx** (`ops/nginx/tests/verify-nginx.sh`, Docker local) : sur le vhost de contrôle, chaque route de la liste répond avec le bon statut et la bonne méthode ; `/login`, `/communes/…`, `/admin/…`, `/v/…` répondent 404 ; méthodes interdites 405 ; hôte inconnu refusé par le serveur par défaut (444) ; en-têtes présents sur 200, 404 et 405 ; aucun `Set-Cookie`.
3. **Cookies et sessions** : requête avec le cookie `PHPSESSID` d'un agent vers l'origine de contrôle → ignoré ; aucune session démarrée ; réponse API sans `Authorization` refusée.
4. **E2E navigateur** (`bin/qa e2e`) étendu à deux origines locales distinctes : activation sur l'une, absence de données lisibles sur l'autre.
5. **Contrôle de déploiement** (`check-deployment.sh`) étendu au second domaine.

## 7. Garde-fous applicatifs optionnels (défense en profondeur, à valider avant tout code)

| Option | Effet | Coût |
|---|---|---|
| G1 | Écouteur de requêtes : si l'hôte est celui du contrôle et que le chemin n'est pas dans la liste, répondre 404 (corps identique au 404 générique) | S, nouveau paramètre `CONTROL_HOST` |
| G2 | `framework.trusted_hosts` listant exactement les deux domaines (aujourd'hui non configuré) | XS, nouveau paramètre |
| G3 | Sur l'hôte de gestion, refuser `/controle/…` en production (le contrôle ne se sert que depuis son origine) | XS, à garder actif seulement en production |

Recommandation : **G2 + G1** (cohérents avec la liste blanche Nginx, utiles si le vhost est mal réglé). G3 optionnel.

## 8. Prérequis et décisions restantes

| # | Décision ou prérequis |
|---|---|
| D1 | Noms de domaine (gestion et contrôle) et responsabilité du DNS |
| D2 | Certificat : un par vhost ou un certificat couvrant les deux noms |
| D3 | G1/G2/G3 : lesquels ? |
| D4 | Autorisation d'infrastructure du protocole 09.7 (I1) |
| D5 | Liste définitive des polices et ressources statiques autorisées (à confirmer après `asset-map:compile`) |

## 9. Travail par incréments (après validation, toujours en local)

| # | Incrément | Effort |
|---|---|---|
| V1 | Liste blanche des routes de contrôle (source unique), test d'inventaire | S |
| V2 | Gabarit Nginx du vhost de contrôle + extension de `verify-nginx.sh` et `check-deployment.sh` | M |
| V3 | Garde-fous applicatifs retenus (G1/G2/G3) avec tests | S |
| V4 | E2E à deux origines locales, tests cookies et sessions | S |
| V5 | Mise à jour du runbook de contrôle et du protocole 09.7 | XS |

Aucun de ces incréments n'est lancé : la présente note est la conception demandée.
