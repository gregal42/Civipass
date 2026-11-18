#!/bin/bash
# Contrôle automatisé du VHOST DÉDIÉ AU CONTRÔLE (LOT 10, V2) dans des conteneurs JETABLES (Docker requis ; aucun fichier du projet n'est modifié, rien n'est déployé, aucun
# DNS ni certificat réel : certificats auto-signés générés à la volée). Vérifie : génération (render.sh) et `nginx -t` ; liste blanche de méthodes et de chemins ; refus génériques
# (404) avec en-têtes de sécurité ; hôtes inconnus ou falsifiés ; absence de cookie ; une seule instance de chaque en-tête de sécurité ; ressources statiques strictement limitées
# (traversée de chemin comprise) ; certificats distincts par sous-domaine ; redirection HTTP ; revalidation du Service Worker et des statiques (cache obsolète).
#   ops/nginx/tests/verify-control-vhost.sh [image-nginx]      (défaut : nginx:stable)
# Variables : VERIFY_HTTPS_PORT (18743), VERIFY_HTTP_PORT (18780), VERIFY_PHP_IMAGE (php:8.4-fpm-alpine), KEEP=1 pour conserver les conteneurs.
set -u
HERE=$(cd "$(dirname "$0")" && pwd); OPS=$(cd "$HERE/.." && pwd)
NGX_IMAGE=${1:-nginx:stable}; PHP_IMAGE=${VERIFY_PHP_IMAGE:-php:8.4-fpm-alpine}
HTTPS_PORT=${VERIFY_HTTPS_PORT:-18743}; HTTP_PORT=${VERIFY_HTTP_PORT:-18780}
W=$(mktemp -d); NET=civipass-verify-ctl-net; NX=civipass-verify-ctl-nginx; PHP=civipass-verify-ctl-php
MGT=civipass.test; CTL=controle.civipass.test
fail=0
pass() { echo "ok  $*"; }
bad()  { echo "KO  $*"; fail=$((fail + 1)); }
cleanup() { [ "${KEEP:-0}" = 1 ] || { docker rm -f "$NX" "$PHP" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; rm -rf "$W"; }; }
trap cleanup EXIT
command -v docker >/dev/null || { echo "Docker est requis"; exit 2; }

# --- 1. génération (domaines d'exemple autorisés ICI seulement) ---
cp -R "$HERE/stub/public" "$W/public"      # copie inscriptible : sert au test de cache obsolète
cat > "$W/test.env" <<ENVEOF
CIVIPASS_DOMAIN=$MGT
CONTROL_DOMAIN=$CTL
TLS_CERTIFICATE=/etc/nginx/tls/cert.pem
TLS_CERTIFICATE_KEY=/etc/nginx/tls/key.pem
TLS_CONTROL_CERTIFICATE=/etc/nginx/tls/ctl-cert.pem
TLS_CONTROL_CERTIFICATE_KEY=/etc/nginx/tls/ctl-key.pem
DOCROOT=/var/www/html/public
PHP_FPM_UPSTREAM=$PHP:9000
LOG_DIR=/var/log/civipass/nginx
NGINX_USER=nginx
ENVEOF
"$OPS/bin/render.sh" --env "$W/test.env" --out "$W/out" --allow-example >/dev/null && pass "render.sh : fichiers générés avec le vhost de contrôle" || { bad "render.sh a échoué"; exit 1; }
[ -f "$W/out/conf.d/20-civipass-control.conf" ] && pass "conf.d/20-civipass-control.conf généré" || bad "fichier du vhost de contrôle absent"
sed '/^CONTROL_DOMAIN=/d;/^TLS_CONTROL/d' "$W/test.env" > "$W/noctl.env"; "$OPS/bin/render.sh" --env "$W/noctl.env" --out "$W/noctl" --allow-example >/dev/null && [ ! -e "$W/noctl/conf.d/20-civipass-control.conf" ] && pass "sans CONTROL_DOMAIN : aucun vhost de contrôle généré" || bad "vhost de contrôle généré sans CONTROL_DOMAIN"
mkdir -p "$W/tls" "$W/logs"; chmod 777 "$W/logs"
for n in "key:cert:$MGT" "ctl-key:ctl-cert:$CTL"; do IFS=: read -r k c cn <<< "$n"; openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=$cn" -addext "subjectAltName=DNS:$cn" -keyout "$W/tls/$k.pem" -out "$W/tls/$c.pem" >/dev/null 2>&1 || { bad "openssl indisponible"; exit 1; }; done

# --- 2. conteneurs ---
docker rm -f "$NX" "$PHP" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; docker network create "$NET" >/dev/null
docker run -d --name "$PHP" --network "$NET" -v "$W/public":/var/www/html/public:ro "$PHP_IMAGE" >/dev/null || { bad "démarrage PHP"; exit 1; }
docker run -d --name "$NX" --network "$NET" -p "$HTTPS_PORT:443" -p "$HTTP_PORT:80" \
    -v "$W/out/nginx.conf":/etc/nginx/nginx.conf:ro -v "$W/out/conf.d":/etc/nginx/conf.d:ro -v "$W/tls":/etc/nginx/tls:ro \
    -v "$W/public":/var/www/html/public:ro -v "$W/logs":/var/log/civipass/nginx "$NGX_IMAGE" nginx -g 'daemon off;' >/dev/null || { bad "démarrage nginx"; exit 1; }
sleep 3
docker exec "$NX" nginx -t >/dev/null 2>&1 && pass "nginx -t (gestion + contrôle)" || { bad "nginx -t"; docker exec "$NX" nginx -t; exit 1; }

# --- 3. configuration effective ---
CONF=$(docker exec "$NX" nginx -T 2>/dev/null)
CTLCONF=$(echo "$CONF" | awk '/# configuration file .*20-civipass-control.conf/{f=1;next} /# configuration file /{f=0} f')
echo "$CTLCONF" | grep -E '^\s*(server|location|add_header)' | awk '/^server/{loc=""} /^[ \t]*location[ \t]/{loc=$0} /^[ \t]*add_header/ && loc!=""{print loc" => "$0}' | grep -q . && bad "add_header dans un location du vhost de contrôle (casse l'héritage)" || pass "en-têtes de sécurité au niveau du server uniquement"
echo "$CONF" | grep -q 'includeSubDomains\|preload' && bad "HSTS avec sous-domaines ou préchargement" || pass "HSTS sans sous-domaines ni préchargement"
echo "$CTLCONF" | grep -E 'return 30[1278]' | grep -q '\$host' && bad "redirection construite avec \$host" || pass "redirection vers le domaine fixe (jamais \$host)"
echo "$CTLCONF" | grep -q 'limit_except' && bad "limit_except présent (renverrait 403, pas le 404 générique)" || pass "refus par la carte de liste blanche (404 générique)"

# --- 4. requêtes réelles ---
R1="$CTL:$HTTPS_PORT:127.0.0.1"; R2="$CTL:$HTTP_PORT:127.0.0.1"; RM="$MGT:$HTTPS_PORT:127.0.0.1"
req() { curl -sk --http1.1 --path-as-is --resolve "$R1" -X "$1" -D "$W/h.txt" -o "$W/b.txt" -w '%{http_code}' ${3:+-H "$3"} "https://$CTL:$HTTPS_PORT$2"; }
hdr() { grep -i "^$1:" "$W/h.txt" | head -1 | tr -d '\r' | cut -d' ' -f2-; }
count() { grep -ic "^$1:" "$W/h.txt"; }

# 4a. liste blanche : méthode ET chemin
for spec in "GET /controle/ 200" "HEAD /controle/ 200" "GET /controle/manifest.webmanifest 200" "GET /controle/sw.js 200" "GET /controle/js/app.js 200" "GET /controle/js/idb-store.js 200" \
            "GET /controle/api/ping 200" "GET /controle/api/device 401" "POST /controle/api/activation 400" "POST /controle/api/sync 401" "GET /controle/?x=1 200" \
            "GET /assets/styles/control.css 200" "GET /assets/vendor/jsqr/jsQR.js 200" "GET /assets/images/control-icon-192.png 200" "GET /assets/fonts/font.woff2 200"; do
    set -- $spec; c=$(req "$1" "$2"); [ "$c" = "$3" ] && pass "autorisé : $1 $2 → $3" || bad "autorisé : $1 $2 → $c (attendu $3)"
done
req GET /controle/ >/dev/null; grep -q 'Contrôle' "$W/b.txt" && ! grep -q '<?php' "$W/b.txt" && pass "la coquille vient de PHP : aucune source PHP servie en statique" || bad "source PHP servie ou coquille absente"
for p in /controle/ /controle/sw.js /controle/api/ping /index.php /controle/index.php; do req GET "$p" >/dev/null; grep -q '<?php' "$W/b.txt" && bad "source PHP exposée sur $p"; done; pass "aucune source PHP exposée (/controle/, /controle/sw.js, API, /index.php)"
# 4b. tout le reste : 404 générique, sans donnée, avec les en-têtes de sécurité, sans cookie
for spec in "GET /login" "POST /login" "GET /communes" "GET /communes/x/evenements/y/controle" "GET /admin/dashboard" "GET /v/abcdefghijklmnopqrstuvwxyz234567" "GET /laissez-passer/abc" "GET /invitation/abc" \
            "GET /demandes/EVT-2026-000001/identite" "GET /forgot-password" "GET /" "GET /index.php" "GET /index.php/controle/" "GET /secret.txt" "GET /.env" "GET /.git/config" "GET /favicon.ico" "GET /robots.txt" \
            "GET /controle" "GET /controle/inconnu" "GET /controle/js/App.js" "GET /controle/js/../secret.txt" "GET /controle/js/a/b.js" "GET //controle/" "GET /controle//api/ping" "GET /%63ontrole/" "GET /controle/%2e%2e/login" \
            "POST /controle/" "POST /controle/api/ping" "GET /controle/api/activation" "GET /controle/api/sync" "PUT /controle/api/sync" "DELETE /controle/api/device" "OPTIONS /controle/api/ping" "PATCH /controle/api/sync" \
            "GET /assets/secret.txt" "GET /assets/app.js" "GET /assets/styles/../../secret.txt" "GET /assets/styles/%2e%2e/%2e%2e/secret.txt" "GET /assets/fonts/../../index.php" "GET /assets/" "GET /assets/styles/" "GET /assets/images/other.png" "POST /assets/styles/control.css"; do
    set -- $spec; c=$(req "$1" "$2")
    if [ "$c" = 404 ]; then
        h=$(hdr strict-transport-security); n=$(hdr x-content-type-options); r=$(hdr referrer-policy); f=$(hdr x-frame-options); s=$(hdr content-security-policy); k=$(grep -ic '^set-cookie:' "$W/h.txt")
        [ "$h" = "max-age=31536000" ] && [ "$n" = nosniff ] && [ "$r" = no-referrer ] && [ "$f" = DENY ] && echo "$s" | grep -q "default-src 'none'" && [ "$k" = 0 ] && ! grep -qi 'nginx/[0-9]' "$W/h.txt" \
            && pass "refusé 404 + en-têtes : $1 $2" || bad "refus sans en-têtes complets ou avec cookie/version : $1 $2"
    else
        bad "NON refusé : $1 $2 → $c"
    fi
done
# 4c. une seule instance de chaque en-tête de sécurité sur une réponse de l'application, et aucun cookie
req GET /controle/ >/dev/null
dup=0; for hn in strict-transport-security x-content-type-options referrer-policy x-frame-options permissions-policy content-security-policy; do [ "$(count $hn)" = 1 ] || { dup=1; echo "    $hn : $(count $hn) instance(s)"; }; done
[ $dup -eq 0 ] && pass "une seule instance de chaque en-tête de sécurité (celle de nginx)" || bad "en-tête de sécurité dupliqué ou absent"
for p in /controle/ /controle/sw.js /controle/manifest.webmanifest /controle/js/app.js /controle/api/ping /assets/styles/control.css; do req GET "$p" >/dev/null; [ "$(grep -ic '^set-cookie:' "$W/h.txt")" = 0 ] || bad "cookie posé sur $p"; done; pass "aucun Set-Cookie sur les routes de la PWA et les statiques"
# 4d. cookie de gestion envoyé à l'origine de contrôle : ignoré (et jamais renvoyé)
c=$(req GET /controle/api/ping 'Cookie: PHPSESSID=abcdef0123456789'); [ "$c" = 200 ] && [ "$(grep -ic '^set-cookie:' "$W/h.txt")" = 0 ] && pass "un cookie de gestion envoyé au contrôle est sans effet et jamais renvoyé" || bad "cookie de gestion pris en compte"
# 4e. Service Worker : périmètre et revalidation
req GET /controle/sw.js >/dev/null; [ "$(hdr service-worker-allowed)" = "/controle/" ] && pass "Service-Worker-Allowed: /controle/ (périmètre limité)" || bad "Service-Worker-Allowed absent ou différent"
echo "$(hdr cache-control)" | grep -q 'no-cache' && pass "Service Worker : Cache-Control no-cache (revalidé à chaque contrôle de mise à jour)" || bad "Service Worker mis en cache"
req GET /controle/api/ping >/dev/null; echo "$(hdr cache-control)" | grep -q 'no-store' && pass "API : Cache-Control no-store" || bad "API mise en cache"
# 4f. statiques : mise en cache longue + revalidation correcte quand un fichier change (cache obsolète)
req GET /assets/styles/control.css >/dev/null; echo "$(hdr cache-control)" | grep -q 'max-age=' && pass "statiques : Cache-Control max-age (fichiers à empreinte)" || bad "statiques sans durée de cache"
et=$(hdr etag); [ -n "$et" ] && pass "statiques : ETag présent ($et)" || bad "statiques sans ETag"
c=$(curl -sk --http1.1 --resolve "$R1" -o /dev/null -w '%{http_code}' -H "If-None-Match: $et" "https://$CTL:$HTTPS_PORT/assets/styles/control.css"); [ "$c" = 304 ] && pass "revalidation : fichier inchangé → 304" || bad "revalidation inchangée : $c"
echo "body{color:red}" > "$W/public/assets/styles/control.css"; sleep 1
c=$(curl -sk --http1.1 --resolve "$R1" -o "$W/b2.txt" -w '%{http_code}' -H "If-None-Match: $et" "https://$CTL:$HTTPS_PORT/assets/styles/control.css"); grep -q 'color:red' "$W/b2.txt" && [ "$c" = 200 ] && pass "cache obsolète : fichier modifié → 200 avec le nouveau contenu (jamais un 304 périmé)" || bad "cache obsolète servi ($c)"
# 4g. hôtes inconnus ou falsifiés, certificats distincts, redirection HTTP
c=$(curl -sk -o /dev/null -w '%{http_code}' -H 'Host: evil.test' "http://127.0.0.1:$HTTP_PORT/controle/"); [ "$c" = 000 ] && pass "hôte inconnu refusé (HTTP, connexion fermée)" || bad "hôte inconnu non refusé (HTTP) : $c"
c=$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$HTTPS_PORT/controle/"); [ "$c" = 000 ] && pass "connexion TLS sans SNI valide refusée" || bad "TLS sans SNI accepté : $c"
c=$(curl -sk -o /dev/null -w '%{http_code}' --resolve "evil.test:$HTTPS_PORT:127.0.0.1" "https://evil.test:$HTTPS_PORT/controle/"); [ "$c" = 000 ] && pass "SNI inconnu refusé (TLS)" || bad "SNI inconnu accepté : $c"
CN1=$(echo | openssl s_client -connect "127.0.0.1:$HTTPS_PORT" -servername "$CTL" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null); CN2=$(echo | openssl s_client -connect "127.0.0.1:$HTTPS_PORT" -servername "$MGT" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null)
echo "$CN1" | grep -q "$CTL" && echo "$CN2" | grep -q "CN *= *$MGT" && ! echo "$CN2" | grep -q "$CTL" && pass "certificat distinct par sous-domaine (SNI)" || bad "certificats non distincts : [$CN1] [$CN2]"
curl -s -o /dev/null -w '%{redirect_url}' --resolve "$R2" "http://$CTL:$HTTP_PORT/controle/?a=1" | grep -q "^https://$CTL/controle/?a=1$" && pass "redirection HTTP vers le domaine fixe du contrôle" || bad "redirection HTTP du contrôle"
# SNI du contrôle avec en-tête Host de la gestion : nginx choisit le serveur d'après l'en-tête Host. Constat documenté : seul le garde-fou applicatif (G1/G3) isole alors les deux origines.
c=$(curl -sk --http1.1 --resolve "$R1" -o /dev/null -w '%{http_code}' -H "Host: $MGT" "https://$CTL:$HTTPS_PORT/controle/"); echo "    constat : SNI contrôle + Host gestion sur /controle/ → $c (servi par le vhost de gestion ; isolé par G3 côté application)"
curl -sk --http2 --resolve "$R1" -I "https://$CTL:$HTTPS_PORT/controle/" | head -1 | grep -q 'HTTP/2' && pass "HTTP/2 actif" || bad "HTTP/2 absent"
# 4h. journal d'accès : aucun secret d'appareil ni requête
curl -sk --http1.1 --resolve "$R1" -o /dev/null -H 'Authorization: Bearer SECRETAPPAREILxyz' "https://$CTL:$HTTPS_PORT/controle/api/device?q=DonneeSensible"; sleep 1
grep -q 'SECRETAPPAREILxyz\|DonneeSensible' "$W/logs/civipass-access.log" && bad "secret ou requête dans le journal d'accès" || pass "journal d'accès : ni en-tête d'autorisation ni requête"

echo; [ "$fail" -eq 0 ] && echo "RESULTAT : CONFORME ($NGX_IMAGE)" || echo "RESULTAT : $fail non-conformité(s) ($NGX_IMAGE)"
exit $((fail > 0))
