# nginx de production — Civipass (V1, une seule instance)

Fichiers d'exploitation pour un **VPS ou serveur dédié administré** : nginx natif, PHP-FPM, Symfony, MySQL. Pas de Docker, de Traefik ni de Redis en production. Le nginx du
dossier `docker/` reste celui du **développement** (non modifié).

Objectif : **qu'aucun secret ne soit écrit dans les journaux de nginx** — jeton de vérification `/v/{jeton}`, lien bénéficiaire `/laissez-passer/{secret}`, invitation
`/invitation/{jeton}`, réinitialisation `/reset-password/{jeton}` — sans dégrader le diagnostic. Rien de ce dossier n'est appliqué automatiquement : tout est installé à la main.

## 1. Contenu

| Fichier | Rôle |
|---|---|
| `civipass.env.example` | Paramètres de déploiement (domaine, certificats, socket PHP-FPM, rétentions…). **Le domaine y est un exemple à remplacer.** |
| `bin/render.sh` | Génère les fichiers de configuration à partir des modèles et des paramètres (valide chaque valeur ; refuse un domaine d'exemple). |
| `templates/` | `nginx.conf`, `00-civipass-logging.conf` (journaux masqués), `10-civipass-site.conf` (HTTP, HTTPS, TLS, HSTS), `logrotate-…conf`. |
| `bin/civipass-sanitize-nginx-error` | Filtre (Perl) du journal d'erreur brut : masque les secrets et les requêtes. |
| `bin/civipass-nginx-log-hook` | Crochet de rotation : réouverture, filtrage, replis et alertes. |
| `bin/check-rotation.sh` | Supervision : la rotation horaire tourne-t-elle ? |
| `bin/check-deployment.sh` | Contrôle de l'environnement de l'application (`DEFAULT_URI` en https, `APP_ENV=prod`, aucun proxy de confiance…). |
| `systemd/` | Service et minuteur de la rotation **horaire**. |
| `php-fpm/civipass-pool.conf.example` | Pool PHP-FPM : utilisateur distinct, aucun journal d'accès, journal d'erreur dédié. |
| `tests/` | Contrôles automatisés (Docker) et jeu de requêtes ; les tests PHPUnit sont dans `tests/Unit/Ops` et `tests/Integration/Ops`. |

## 2. Prérequis

- Debian 12/13 ou Ubuntu 22.04/24.04, systemd, **nginx stable ≥ 1.25.1** (dépôt nginx.org : stable 1.30.x ; la directive `http2 on;` exige ≥ 1.25.1), PHP-FPM 8.4, `logrotate`, `perl`.
- Testé avec nginx **1.30.6** (stable) et **1.31.8** (mainline), logrotate 3.22.0 (Debian 13), PHP 8.4. Les paquets de distribution plus anciens n'ont pas été testés.
- Un nom de domaine réel et un certificat TLS.

## 3. Installation (à faire à la main, sur le serveur)

```bash
cp ops/nginx/civipass.env.example civipass.env       # adapter : domaine, certificats, socket PHP-FPM…
ops/nginx/bin/render.sh --env civipass.env --out /tmp/civipass-nginx
ops/nginx/bin/check-deployment.sh --domain <domaine> /chemin/.env /chemin/.env.local   # DEFAULT_URI en https, APP_ENV=prod…
```

1. **Utilisateurs.** nginx tourne sous `www-data` (Debian) ; le pool PHP-FPM sous un utilisateur **distinct** (`civipass`, voir `php-fpm/`) : un PHP compromis ne doit pas pouvoir lire le
   journal d'erreur brut de nginx.
2. **Dossier et fichiers des journaux — AVANT de démarrer nginx.** Le dossier doit appartenir à l'utilisateur nginx : à chaque réouverture, chaque processus de travail rouvre ses
   journaux *par leur nom, avec son propre utilisateur* ; dans un dossier inaccessible, il perd son fichier et **ne journalise plus rien, sans erreur visible** (reproduit lors des essais).
   ```bash
   install -d -o www-data -g adm -m 0750 /var/log/civipass/nginx /var/log/civipass/php
   install -d -m 0755 /var/lib/civipass /etc/civipass/nginx
   install -m 0640 -o www-data -g adm /dev/null /var/log/civipass/nginx/civipass-access.log
   install -m 0640 -o www-data -g adm /dev/null /var/log/civipass/nginx/civipass-error.log
   ```
3. **Scripts et configuration.**
   ```bash
   install -m 0755 ops/nginx/bin/civipass-sanitize-nginx-error /usr/local/sbin/
   install -m 0755 ops/nginx/bin/civipass-nginx-log-hook /usr/local/sbin/
   install -m 0644 /tmp/civipass-nginx/nginx.conf /etc/nginx/nginx.conf             # remplace celui de la distribution (qui journalise $request en clair)
   install -m 0644 /tmp/civipass-nginx/conf.d/*.conf /etc/nginx/conf.d/           # supprimer les autres .conf (default.conf…)
   install -m 0644 /tmp/civipass-nginx/logrotate.conf /etc/civipass/nginx/logrotate.conf
   install -m 0644 /tmp/civipass-nginx/systemd/* /etc/systemd/system/
   nginx -t && systemctl reload nginx
   systemctl daemon-reload && systemctl enable --now civipass-nginx-logrotate.timer
   ```
   **Ne pas** copier le fichier logrotate dans `/etc/logrotate.d/` (la rotation quotidienne de la distribution le lancerait sans le rythme horaire). Le fichier
   `/etc/logrotate.d/nginx` du paquet (glob `/var/log/nginx/*.log`) ne touche pas `/var/log/civipass/nginx`.
4. **Pool PHP-FPM** : adapter `php-fpm/civipass-pool.conf.example` vers `/etc/php/8.4/fpm/pool.d/civipass.conf`, créer l'utilisateur `civipass`, redémarrer PHP-FPM.
5. **Contrôles** : `ops/nginx/tests/verify-nginx.sh` et `ops/nginx/tests/verify-rotation.sh` (Docker) avant la mise en service ; `ops/nginx/bin/check-rotation.sh` branché sur la supervision.
6. **Exclure de toute sauvegarde, instantané ou expédition de journaux** le fichier ACTIF `civipass-error.log` (voir § 5).

## 4. Journal d'accès

Format `civipass` (`00-civipass-logging.conf`) : adresse, date, méthode, **chemin masqué**, protocole, statut, taille, Referer réduit à son **origine**, agent, durée. **Pas de `X-Forwarded-For`**
(nginx est le premier saut ; un tel en-tête ne peut venir que d'un client) et **jamais la requête** (`?q=Dupont` — recherche d'une demande par nom, téléphone, e-mail ou plaque — est une donnée
personnelle) : `/communes/<uuid>/demandes?[requete-omise]`.

Masquage : le segment qui suit `/v/`, `/laissez-passer/`, `/invitation/` ou `/reset-password/` est remplacé par `***`, **quelle que soit sa longueur** (un secret tronqué reste un secret), sauf les
routes fixes (liste blanche) `/invitation/delegation`, `/invitation/rejoindre` et `/reset-password/done`. Le chemin est lu après décodage (`$uri` de la première passe), donc `/%76/<jeton>`, `//v/…`,
`/./v/…`, `/x/../v/…`, `/index.php/v/…`, `/V/…`, `;param`, `%2F`… sont masqués.

Formats réels des secrets (vérifiés dans le code) : `AccessSecret`, `InvitationToken` et `PasswordResetToken` = `rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')` soit **43 caractères
base64url** (256 bits) ; `VerificationToken` = **32 caractères base32** minuscules (160 bits). Les routes imposent 43 caractères pour l'invitation et la réinitialisation.

**Une nouvelle route fixe sous un de ces préfixes** serait masquée par excès de prudence (jamais exposée). Le test `NginxSecretRoutesTest` la détecte et impose de l'ajouter à la liste blanche, dans nginx
**et** dans le filtre ; il détecte aussi toute route dont le chemin porte un `{token}` ou un `{secret}` hors des quatre préfixes (secret non protégé).

## 5. Journal d'erreur : RISQUE ET STRATÉGIE

**nginx ne peut ni masquer ni normaliser les erreurs de requête** : elles citent `request: "GET /v/<jeton> …"` et `referrer: "…"` (amont PHP-FPM indisponible, délai dépassé, corps trop gros…). En
fonctionnement sain ce journal reste vide ; pendant une panne d'amont, **chaque requête y écrit son secret en clair**. Stratégie retenue (option A) :

1. journal **dédié** `civipass-error.log`, niveau `error` (jamais `crit` : les vraies erreurs restent visibles), propriétaire de l'utilisateur nginx, groupe d'administration, **mode 0640** ;
2. **rotation HORAIRE** (minuteur systemd) ; à chaque rotation le fichier tourné est **filtré** (secrets et requêtes masqués ; heure, erreur, client, amont et hôte conservés) **avant** toute
   compression ou consultation ; archives compressées seulement après filtrage ; aucun `copytruncate`, `olddir`, `dateext` ni envoi par courriel ;
3. rétention des archives filtrées : **7 jours** (`ERROR_LOG_RETENTION_HOURS=168`), configurable.

> **RISQUE EXPLICITE — le filtrage après rotation ne supprime pas l'exposition dans le fichier ACTIF.** Pendant l'heure en cours, `civipass-error.log` contient les secrets en clair, lisibles par root, l'utilisateur
> nginx et le groupe `adm`. La rotation **borne** cette exposition à une heure (si elle ne tourne plus, l'exposition se prolonge : d'où `check-rotation.sh`). Mesures : permissions 0640 ; utilisateur PHP-FPM
> distinct de celui de nginx ; **fichier actif exclu des sauvegardes, instantanés et expéditeurs de journaux** ; aucun collecteur ne doit lire ce fichier avant la rotation ; ne jamais activer `error_log … debug`
> (il consigne tout) ; accès au serveur réservé à l'équipe d'administration. Un secret exposé dans ce fichier pendant une panne doit être considéré comme **potentiellement lu par un administrateur**.

### Comportement exact de la rotation (testé avec un vrai nginx et un vrai logrotate)

Séquence horaire (`logrotate -s … logrotate.conf`) : `prerotate` → rattrapage (refiltre un fichier tourné resté brut) ; logrotate renomme le fichier actif en `.1` et crée un fichier neuf (`0640`) ;
`postrotate` → `kill -USR1` (réouverture), pause d'une seconde, **vérification que l'utilisateur nginx peut écrire le fichier neuf**, attente que plus aucun processus ne tienne l'ancien (lecture de `/proc`,
effective pour root sur un serveur), **filtrage** de `.1` (vérification : mêmes nombre de lignes, aucun segment non masqué), propriétaire `root:adm`, mode `0640`. Le `.1` est compressé à la rotation
suivante (`delaycompress`).

| Situation | Comportement | Visible comment |
|---|---|---|
| Écritures **pendant** la rotation | Aucune ligne perdue (5 695 requêtes envoyées pendant 6 rotations : 5 696 lignes retrouvées) ; aucun secret dans les archives | — |
| Filtre principal (Perl) en échec ou qui ne masque rien | **Repli grossier** (`sed`) : `request:` et `referrer:` remplacés par `[masque]`, le reste de la ligne conservé | Alerte `daemon.err` (journal système, étiquette `civipass-nginx-log-hook`), code de sortie ≠ 0, service systemd en échec |
| Repli en échec aussi | Le contenu est **remplacé par une seule ligne** qui indique le nombre de lignes perdues ; à défaut, le fichier est supprimé. **Jamais de brut conservé** | Alerte `daemon.crit` avec le nombre de lignes perdues, code ≠ 0 |
| Rotation interrompue (fichier tourné resté brut) | Le `prerotate` suivant le refiltre **avant** de le compresser | — |
| Dossier ou fichier inaccessible à l'utilisateur nginx (déploiement) | nginx ne journalise **plus rien** après la réouverture | Alerte `daemon.crit` à la rotation, code ≠ 0 ; reprise après `chown` et `kill -USR1` |
| Minuteur arrêté | Le fichier actif grossit, brut, au-delà d'une heure | `check-rotation.sh` (âge de la plus ancienne ligne) ; `systemctl --failed` |

**Une perte de lignes de diagnostic n'est jamais silencieuse** : elle est annoncée dans le journal système et par un code de sortie non nul. Supprimer un journal brut n'est qu'un dernier recours.

## 6. Rétentions (configurables dans `civipass.env`)

| Journal | Défaut | Paramètre |
|---|---|---|
| Accès (déjà masqué) | 30 jours (rotation quotidienne) | `ACCESS_LOG_RETENTION_DAYS` |
| Erreur assaini | 7 jours (rotation horaire) | `ERROR_LOG_RETENTION_HOURS` (168) |
| Erreur brut (actif) | ≤ 1 heure | rythme du minuteur |

L'adresse IP est une donnée personnelle : la durée définitive relève de la politique de conservation (DM-117).

## 7. HTTPS, HSTS, hôtes

- TLS **1.2 et 1.3** seulement ; chiffrements AEAD à confidentialité persistante (les chiffrements CBC sans PFS de la liste par défaut d'OpenSSL, par exemple `AES128-SHA`, sont refusés) ; tickets de session désactivés.
- **HSTS** `max-age=31536000`, **sans** sous-domaines ni préchargement, sur toutes les réponses HTTPS (200, 302, 404, 405, statiques). Piège : un `add_header` dans un `location` supprime les en-têtes hérités du `server`
  (HSTS compris) ; il n'y en a aucun ; `verify-nginx.sh` contrôle l'en-tête sur huit types de réponses.
- Serveurs par défaut : hôte inconnu refusé (`return 444`, `ssl_reject_handshake on`) ; redirection HTTP→HTTPS vers le **domaine configuré** — **jamais `$host`** (un `Host: evil.example` produisait
  `Location: https://evil.example/v/<jeton>`).
- Les QR codes, liens bénéficiaires, invitations et réinitialisations sont bâtis à partir de **`DEFAULT_URI`** (jamais de l'hôte ni du schéma de la requête : aucun code n'utilise `ABSOLUTE_URL`, `getHost()`…,
  contrôlé par test). **`DEFAULT_URI` doit être `https://<domaine>`** en production : `check-deployment.sh`.
- Un secret qui arrive en HTTP clair a déjà circulé sur le réseau avant toute redirection : seuls les QR et liens émis en `https://` et HSTS réduisent ce risque.

## 8. Adresse du client, PHP-FPM et Symfony

- nginx est le premier saut : `REMOTE_ADDR` est l'adresse réelle ; **aucun en-tête de provenance du client n'est transmis à PHP** (`fastcgi_param HTTP_X_FORWARDED_FOR ""`, etc.). Reproduit : avec
  `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR` (erreur de configuration) et sans cette protection, 26 requêtes aux en-têtes forgés contournaient entièrement le limiteur ; avec elle, le limiteur tient. **Laisser
  `SYMFONY_TRUSTED_PROXIES` vide.** Les limiteurs du LOT 05.4.3 reçoivent l'adresse réelle.
- **PHP-FPM** : aucun journal d'accès (`access.log` non défini) ; sur l'image de développement il écrit `GET /index.php`, jamais l'URL d'origine. `catch_workers_output = yes`, `error_log` vers un fichier dédié
  (`0640`, groupe `adm`, rotation à ajouter), `display_errors = off`, `zend.exception_ignore_args = On`.
- **Symfony** : le journal du framework est écrit par `error_log()` ; `SecretScrubbingLogger` masque les quatre préfixes (insensible à la casse, slashs répétés, `/%76/`, segments tronqués), et, dans les messages
  « No route found for … », tout jeton opaque de 28 caractères ou plus. Essai sur la vraie application en mode production derrière cette configuration nginx : 0 secret dans le journal d'accès, dans les journaux
  PHP-FPM/Symfony (71 lignes, 22 messages de l'application) et dans la sortie de nginx. Deux fuites réelles trouvées et corrigées pendant ces essais (préfixe en majuscules et slashs répétés).
- **Requêtes** : les paramètres de requête ne sont journalisés nulle part en clair (nginx : omis ; Symfony : le routeur ne cite pas la requête).

## 9. Contrôles automatisés

```bash
ops/nginx/tests/verify-nginx.sh [image]      # config effective, 52 requêtes réelles, journal d'accès, HSTS, hôtes, journal d'erreur brut puis filtré (Docker)
ops/nginx/tests/verify-rotation.sh [image]   # vrai nginx + vrai logrotate + filtre : 7 scénarios (Docker, accès réseau pour apt)
bin/qa run 'Nginx'                           # tests PHPUnit : modèles, génération, filtre, crochet, routes, environnement
```

Ces contrôles ne modifient aucun fichier du dépôt ni du serveur. À rejouer à chaque changement de fichier, d'image ou de route sous un préfixe secret.

## 10. Limites et risques résiduels

- Fichier d'erreur actif brut (§ 5) ; le filtre masque ce qu'il reconnaît (préfixes, jetons opaques ≥ 28 caractères) : un secret sous un préfixe inconnu de moins de 28 caractères resterait visible.
- Le masquage du journal d'accès dépend de `$uri` à la première passe : toute directive de rewrite ajoutée devant `set $civ_path` peut le fausser (les tests le détectent).
- Un secret envoyé en HTTP clair, ou transmis à un lecteur de QR tiers, échappe à ces protections.
- Aucune limitation de débit nginx (décision V1) : seule la protection applicative des LOT 05.4.3 s'applique ; un déni de service volumique distribué n'est pas couvert.
- La rotation a été éprouvée avec les signaux de nginx dans un conteneur : la lecture de `/proc` (attente de libération de l'ancien fichier) y est restreinte ; sur un serveur, root peut la faire.

## 11. À contrôler sur le VPS natif avant mise en service

1. `nginx -v` (stable ≥ 1.25.1), `nginx -t`, `nginx -T | grep -E 'access_log|error_log'` : seuls les journaux Civipass ; aucun autre `access_log` (celui de la distribution) actif.
2. `verify-rotation.sh`-équivalent en réel : lancer `systemctl start civipass-nginx-logrotate.service`, vérifier propriétaires et modes, que nginx écrit dans le fichier neuf, `journalctl -u civipass-nginx-logrotate`.
3. Provoquer une panne d'amont (arrêt de PHP-FPM) avec de **faux** secrets : le fichier actif est brut, le fichier tourné est filtré.
4. Journaux PHP-FPM et Symfony réels : même chemin et mêmes droits que dans le pool ; rien n'écrit d'URL (`access.log` absent) ; rotation de ces fichiers.
5. Sauvegardes et expéditeurs de journaux : le fichier actif `civipass-error.log` en est exclu.
6. `check-deployment.sh` sur les vrais fichiers `.env` ; TLS (`openssl s_client`, ssllabs ou équivalent) ; HSTS ; redirection HTTP.
7. `logger` / journald : l'alerte du crochet apparaît bien (`logger -t civipass-nginx-log-hook test`) et est surveillée.
8. Le groupe `adm` ne contient que les administrateurs prévus ; l'utilisateur PHP-FPM n'en fait pas partie.
