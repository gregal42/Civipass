# Runbook — vhost HTTPS dédié au contrôle (LOT 10, V5)

**Statut : préparé et vérifié en local ; AUCUN déploiement, AUCUN changement DNS, AUCUN certificat réel.** Mise en service soumise à l'autorisation d'infrastructure du protocole [09.7](../lot-10-protocole-09-7.md) (I1). Architecture : [lot-10-architecture-vhost-controle.md](../lot-10-architecture-vhost-controle.md) (second vhost HTTPS, même serveur, même application, origine dédiée, liste blanche de routes, aucun cookie de session sur les routes de contrôle). Décision : DT-056 point 4.

## 1. Prérequis (rien n'est fait par le dépôt)

| # | Prérequis | Détail |
|---|---|---|
| 1 | Serveur autorisé | nginx stable ≥ 1.25.1, PHP-FPM 8.4, kit [`ops/nginx/`](../../ops/nginx/README.md) déjà installé pour la gestion |
| 2 | DNS | Un enregistrement pour le sous-domaine de contrôle (convention provisoire `controle.<domaine-civipass>`), **distinct** du domaine de gestion |
| 3 | Certificat | **Un certificat par sous-domaine** (préférence retenue), à renouveler séparément (échéance à surveiller) |
| 4 | Version | Référence Git de `develop` à déployer (jamais `main` sans accord) |
| 5 | Accord d'activation | `OFFLINE_CONTROL_ENABLED=1` (et `PUBLIC_VERIFICATION_ENABLED=1` pour la vérification en ligne) uniquement avec l'accord horodaté du responsable du projet |

**Attention HSTS** : le vhost de contrôle émet `Strict-Transport-Security: max-age=31536000` (un an, sans sous-domaines ni préchargement). Une fois vu par un navigateur, le domaine de contrôle ne se sert plus en HTTP : le retour arrière ne peut pas être « repasser en HTTP ».

## 2. Paramètres

**Fichier de déploiement nginx** (`civipass.env`, voir `civipass.env.example`) :
```
CONTROL_DOMAIN=controle.<domaine-civipass>
TLS_CONTROL_CERTIFICATE=/etc/letsencrypt/live/controle.<domaine-civipass>/fullchain.pem
TLS_CONTROL_CERTIFICATE_KEY=/etc/letsencrypt/live/controle.<domaine-civipass>/privkey.pem
```
`render.sh` refuse : un domaine de contrôle identique à celui de la gestion, un certificat identique à celui de la gestion, un certificat ou une clé manquants, un domaine d'exemple (hors tests).

**Environnement de l'application** (`.env.local` du serveur) :
```
DEFAULT_URI=https://<domaine-gestion>                      # inchangé : liens e-mail, QR de laissez-passer, vérification en ligne
CONTROL_HOST=controle.<domaine-civipass>                   # active G1 (liste blanche) et G3 (aucun /controle sur la gestion)
TRUSTED_HOSTS=^(<domaine-gestion-échappé>|controle\.<domaine-civipass-échappé>)$     # active G2 (hôtes de confiance)
CONTROL_PWA_BASE_URL=https://controle.<domaine-civipass>/controle/    # QR d'activation et lien de la PWA sur l'origine de contrôle
OFFLINE_CONTROL_ENABLED=0                                  # ne passer à 1 qu'après l'accord et les vérifications du § 4
```
Les points du `.` dans `TRUSTED_HOSTS` sont échappés (`\.`).

## 3. Installation (à faire à la main, dans cet ordre ; rien n'est automatique)

1. **Contrôles avant installation (sur un poste, sans serveur)** :
   ```
   ops/nginx/tests/verify-control-vhost.sh        # Docker : 86 contrôles du vhost (liste blanche, 404, en-têtes, cookies, statiques, certificats, cache)
   bin/qa e2e-origins                              # navigateur réel, deux origines, G1/G2/G3 actifs (11 étapes)
   ```
2. **DNS puis certificat** du sous-domaine de contrôle (certificat propre).
3. **Génération et contrôle** :
   ```
   ops/nginx/bin/render.sh --env civipass.env --out /tmp/civipass-nginx
   ops/nginx/bin/check-deployment.sh --domain <domaine-gestion> --control-domain controle.<domaine-civipass> /chemin/.env /chemin/.env.local
   ```
   `check-deployment.sh` vérifie : `DEFAULT_URI` sur la gestion en https, `CONTROL_HOST` = domaine de contrôle, `TRUSTED_HOSTS` limité **exactement** aux deux domaines, `CONTROL_PWA_BASE_URL` = `https://<contrôle>/controle/`, `APP_ENV=prod`, `APP_DEBUG` inactif, aucun proxy de confiance.
4. **Installer le vhost** puis recharger :
   ```
   install -m 0644 /tmp/civipass-nginx/conf.d/20-civipass-control.conf /etc/nginx/conf.d/
   nginx -t && systemctl reload nginx
   ```
   (Le vhost de gestion, les journaux et la rotation ne changent pas : voir `ops/nginx/README.md`.)
5. **Activer les garde-fous applicatifs** : renseigner `CONTROL_HOST`, `TRUSTED_HOSTS`, `CONTROL_PWA_BASE_URL`, vider et réchauffer le cache (`cache:clear` puis `cache:warmup` en prod), recharger PHP-FPM.
   *Ordre* : nginx d'abord, garde-fous ensuite ; en sens inverse, la PWA ne serait servie nulle part pendant l'intervalle (G3 refuserait `/controle` sur la gestion alors que le vhost n'existe pas encore).
6. **Ouvrir le contrôle** seulement ensuite : `OFFLINE_CONTROL_ENABLED=1` avec l'accord horodaté, puis protocole 09.7.

## 4. Vérification après installation

| Contrôle | Commande ou action | Attendu |
|---|---|---|
| Routes autorisées | `curl -sI https://controle.<d>/controle/` ; `/controle/sw.js` ; `/controle/manifest.webmanifest` ; `/controle/api/ping` | 200 ; `Service-Worker-Allowed: /controle/` ; `Cache-Control: no-cache` (Service Worker) ; `no-store` (API) |
| Refus génériques | `curl -si https://controle.<d>/login` ; `/communes` ; `/v/AAAA…` ; `/admin/dashboard` ; `POST /controle/` | 404, mêmes en-têtes de sécurité, aucun cookie, aucune version nginx |
| Méthodes | `curl -si -X PUT https://controle.<d>/controle/api/sync` | 404 (jamais 403 ni 405) |
| Hôtes inconnus ou falsifiés | `curl -k -H 'Host: x.invalid' http://<IP>/` ; TLS sans SNI | connexion fermée ; `Host` forgé vers l'application : 400 (G2) |
| Origine de gestion | `curl -si https://<gestion>/controle/` | 404 (G3), pas la coquille de la PWA |
| Cookies | Inspecter toute réponse de l'origine de contrôle | aucun `Set-Cookie` ; les cookies de la gestion ne sont jamais envoyés (cookies « host-only ») |
| Certificats | `openssl s_client -connect <IP>:443 -servername controle.<d>` puis `-servername <gestion>` | deux certificats distincts, un par sous-domaine |
| Vérification en ligne | Scanner un QR de laissez-passer avec le réseau | ouverture de `https://<gestion>/v/{jeton}` (origine de gestion) |
| HSTS | `curl -sI https://controle.<d>/controle/` | `max-age=31536000`, sans `includeSubDomains` ni `preload` |
| Journal d'accès | Lire `civipass-access.log` | ni requête (`?q=`), ni secret d'appareil, ni jeton |

## 5. Mise à jour de version et cache obsolète

- Le Service Worker (`/controle/sw.js`) et les modules `/controle/js/*.js` sont servis avec `Cache-Control: no-cache` : le navigateur les **revalide** à chaque contrôle de mise à jour ; les ressources de `/assets/` portent une **empreinte dans leur nom** et un cache d'un mois : un nouveau déploiement change leurs noms, jamais leur contenu sous un nom existant.
- Après un déploiement : les téléphones récupèrent la nouvelle coquille à leur prochaine ouverture avec réseau (installation du nouveau Service Worker, puis activation) ; la liste de précache est celle du Service Worker lui-même (`PRECACHE`). Contrôler sur un appareil de test : ouvrir la PWA avec réseau, fermer, rouvrir ; l'ancienne version ne doit plus être servie.
- Si un appareil reste sur une version périmée : synchroniser avec réseau ; en dernier recours, désinstaller la PWA puis la réinstaller (l'appareil devra être **réactivé** : les données locales sont effacées).
- Fichier statique modifié sous un nom existant : revalidé par `ETag` (test automatique : 304 inchangé, 200 avec le nouveau contenu), jamais servi périmé.

## 6. Retour arrière

| Situation | Action | Effet |
|---|---|---|
| Un défaut sur le vhost de contrôle | Retirer `conf.d/20-civipass-control.conf`, `nginx -t && systemctl reload nginx` | L'origine de contrôle disparaît ; les appareils activés sur cette origine ne peuvent plus se synchroniser (leurs données locales restent lisibles dans la limite de la durée de confiance, 12 h au pilote), puis passent en « indéterminé » |
| Revenir à la PWA sur l'origine de gestion (comportement V1) | Vider `CONTROL_HOST`, `TRUSTED_HOSTS` et `CONTROL_PWA_BASE_URL`, recharger PHP-FPM | G1 et G3 inactifs, `/controle/` de nouveau servi par la gestion ; les appareils doivent être **réactivés** (stockage local propre à chaque origine) |
| Un garde-fou applicatif trop strict (ressource de la PWA bloquée) | Corriger la liste blanche (`ControlRouteAllowlist` et gabarit Nginx, qui doivent rester identiques : test `ControlVhostTemplateTest`) ou, en urgence, vider `CONTROL_HOST` | Retour au comportement sans garde-fou applicatif ; la liste blanche Nginx reste active |
| Interrupteur du contrôle | `OFFLINE_CONTROL_ENABLED=0` | Toutes les routes `/controle/*` répondent 404 uniforme (inchangé) |

Le retour arrière du **HTTPS** n'existe pas : voir l'avertissement HSTS du § 1.

## 7. Surveillance et incidents

- **Certificat** : deux échéances (gestion et contrôle) à suivre séparément ; un certificat expiré du contrôle bloque la synchronisation des téléphones, pas la gestion.
- **Pics de 404** sur l'origine de contrôle : sondage de routes (attendu et sans effet) ; ne pas rouvrir la liste blanche.
- **Hôte forgé accepté** (G2 absent) : vérifier `TRUSTED_HOSTS` et `check-deployment.sh`.
- **Cookie vu sur l'origine de contrôle** : défaut à traiter comme anomalie de sécurité (aucun cookie n'est attendu) ; contrôler que le pare-feu `control_pwa` est inchangé.

## 8. Limites connues

- **SNI et `Host` discordants** : une requête dont le SNI désigne le contrôle mais dont l'en-tête `Host` désigne la gestion est servie par le vhost de gestion (nginx choisit le serveur d'après `Host`). Aucun navigateur n'émet une telle requête ; la parade est applicative (G3 : `/controle` refusé sur l'hôte de gestion). Un blocage Nginx complémentaire (`location ^~ /controle { return 404; }` dans le vhost de gestion) est possible quand le vhost de contrôle est en service ; il n'est pas généré par défaut afin de ne pas casser la V1 sans vhost dédié.
- Les polices de `/assets/fonts/` ne figurent pas dans le précache du Service Worker : à observer au 09.7 (première ouverture hors connexion).
- Le 404 produit par nginx lui-même ne porte pas de `Cache-Control` ; il porte tous les autres en-têtes de sécurité.
- Rien ne remplace la recette sur appareils réels (09.7).
