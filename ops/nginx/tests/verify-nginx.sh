#!/bin/bash
# Contrôle automatisé de la configuration nginx de Civipass ET du journal d'accès réellement produit, dans des conteneurs JETABLES (Docker requis ; aucun fichier du
# projet n'est modifié). Étapes : génération des fichiers (render.sh) ; `nginx -t` ; contrôle de `nginx -T` ; amont PHP minimal (stub) ; requêtes réelles et lecture du
# journal d'accès (python3, access_log_cases.py) ; en-têtes HSTS sur chaque type de réponse ; journal d'erreur brut puis filtré.
#   ops/nginx/tests/verify-nginx.sh [image-nginx]      (défaut : nginx:stable)
# Variables : VERIFY_HTTPS_PORT (18643), VERIFY_HTTP_PORT (18680), VERIFY_PHP_IMAGE (php:8.4-fpm-alpine), KEEP=1 pour conserver les conteneurs.
set -u
HERE=$(cd "$(dirname "$0")" && pwd); OPS=$(cd "$HERE/.." && pwd)
NGX_IMAGE=${1:-nginx:stable}; PHP_IMAGE=${VERIFY_PHP_IMAGE:-php:8.4-fpm-alpine}
HTTPS_PORT=${VERIFY_HTTPS_PORT:-18643}; HTTP_PORT=${VERIFY_HTTP_PORT:-18680}
W=$(mktemp -d); NET=civipass-verify-net; NX=civipass-verify-nginx; PHP=civipass-verify-php
fail=0
pass() { echo "ok  $*"; }
bad()  { echo "KO  $*"; fail=$((fail + 1)); }
cleanup() { [ "${KEEP:-0}" = 1 ] || { docker rm -f "$NX" "$PHP" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; rm -rf "$W"; }; }
trap cleanup EXIT
command -v docker >/dev/null || { echo "Docker est requis"; exit 2; }
command -v python3 >/dev/null || { echo "python3 est requis"; exit 2; }

# --- 1. génération (domaine d'exemple autorisé ICI seulement) ---
cat > "$W/test.env" <<ENVEOF
CIVIPASS_DOMAIN=civipass.test
TLS_CERTIFICATE=/etc/nginx/tls/cert.pem
TLS_CERTIFICATE_KEY=/etc/nginx/tls/key.pem
DOCROOT=/var/www/html/public
PHP_FPM_UPSTREAM=$PHP:9000
LOG_DIR=/var/log/civipass/nginx
NGINX_USER=nginx
ENVEOF
"$OPS/bin/render.sh" --env "$W/test.env" --out "$W/out" --allow-example >/dev/null && pass "render.sh : fichiers générés" || { bad "render.sh a échoué"; exit 1; }
"$OPS/bin/render.sh" --env "$OPS/civipass.env.example" --out "$W/refused" >/dev/null 2>&1 && bad "render.sh a accepté le domaine d'exemple" || pass "render.sh refuse le domaine d'exemple"
mkdir -p "$W/tls" "$W/logs"; chmod 777 "$W/logs"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=civipass.test" -keyout "$W/tls/key.pem" -out "$W/tls/cert.pem" >/dev/null 2>&1 || { bad "openssl indisponible"; exit 1; }

# --- 2. conteneurs ---
docker rm -f "$NX" "$PHP" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; docker network create "$NET" >/dev/null
docker run -d --name "$PHP" --network "$NET" -v "$HERE/stub/public":/var/www/html/public:ro "$PHP_IMAGE" >/dev/null || { bad "démarrage PHP"; exit 1; }
docker run -d --name "$NX" --network "$NET" -p "$HTTPS_PORT:443" -p "$HTTP_PORT:80" \
    -v "$W/out/nginx.conf":/etc/nginx/nginx.conf:ro -v "$W/out/conf.d":/etc/nginx/conf.d:ro -v "$W/tls":/etc/nginx/tls:ro \
    -v "$HERE/stub/public":/var/www/html/public:ro -v "$W/logs":/var/log/civipass/nginx "$NGX_IMAGE" nginx -g 'daemon off;' >/dev/null || { bad "démarrage nginx"; exit 1; }
sleep 3
V=$(docker exec "$NX" nginx -v 2>&1); echo "    $V"
docker exec "$NX" nginx -t >/dev/null 2>&1 && pass "nginx -t" || { bad "nginx -t"; docker exec "$NX" nginx -t; exit 1; }

# --- 3. contrôle de la configuration effective ---
CONF=$(docker exec "$NX" nginx -T 2>/dev/null)
echo "$CONF" | grep -E '^\s*access_log ' | grep -v 'civipass-access.log civipass;\|access_log off;' | grep -q . && bad "access_log hors format civipass" || pass "tous les access_log utilisent le format masqué (ou off)"
echo "$CONF" | grep -qE 'http_x_forwarded_for|\$request[ "]' && bad "journal d'accès avec \$request ou X-Forwarded-For" || pass "aucun format n'utilise \$request ni X-Forwarded-For"
echo "$CONF" | grep -q 'limit_req' && bad "limit_req présent (non retenu pour la V1)" || pass "pas de limit_req (protections applicatives)"
echo "$CONF" | grep -E 'return 30[1278]' | grep -q '\$host' && bad "redirection construite avec \$host" || pass "aucune redirection construite avec \$host"
echo "$CONF" | grep -q 'max-age=31536000"' && ! echo "$CONF" | grep -q 'includeSubDomains\|preload' && pass "HSTS : max-age=31536000 sans sous-domaines ni préchargement" || bad "HSTS non conforme"
echo "$CONF" | grep -E '^\s*(location|add_header)' | awk '/location/{loc=$0} /add_header/{print loc" => "$0}' | grep -v 'Strict-Transport' | grep -q . && bad "add_header dans un location (casse l'héritage HSTS)" || pass "aucun add_header dans un location"
echo "$CONF" | grep -q 'ssl_protocols TLSv1.2 TLSv1.3;' && pass "TLS 1.2 et 1.3 seulement" || bad "ssl_protocols"

# --- 4. requêtes réelles, journal d'accès ---
RES1="civipass.test:$HTTPS_PORT:127.0.0.1"; RES2="civipass.test:$HTTP_PORT:127.0.0.1"
python3 "$HERE/access_log_cases.py" --url "https://civipass.test:$HTTPS_PORT" --http-url "http://civipass.test:$HTTP_PORT" --resolve "$RES1" --resolve "$RES2" --log "$W/logs/civipass-access.log" > "$W/cases.out" 2>&1; rc=$?
tail -4 "$W/cases.out" | sed 's/^/    /'; grep -c '^ok' "$W/cases.out" | sed 's/^/    cas conformes : /'
[ $rc -eq 0 ] && pass "journal d'accès : tous les cas conformes" || { bad "journal d'accès : non-conformités"; grep '^KO' "$W/cases.out" | head -20; }

# --- 5. HSTS et en-têtes selon le type de réponse ---
for spec in "GET /login 200" "GET /secret-inexistant 404" "POST /v/abcdefghijklmnopqrstuvwxyz234567 405" "GET /communes 302" "GET /assets/test.css 200" "GET /.env 404" "GET /invitation/abc 404" "HEAD /login 200"; do
    set -- $spec; m=$1; p=$2; code=$3
    if [ "$m" = HEAD ]; then h=$(curl -sk --resolve "$RES1" -I "https://civipass.test:$HTTPS_PORT$p"); else h=$(curl -sk --resolve "$RES1" -X "$m" -D - -o /dev/null "https://civipass.test:$HTTPS_PORT$p"); fi
    echo "$h" | head -1 | grep -q " $code" && echo "$h" | grep -qi '^strict-transport-security: max-age=31536000\s*$' && pass "HSTS présent : $m $p ($code)" || { bad "HSTS ou statut inattendu : $m $p"; echo "$h" | head -3 | sed 's/^/      /'; }
done
curl -sk --http2 --resolve "$RES1" -I "https://civipass.test:$HTTPS_PORT/login" | head -1 | grep -q 'HTTP/2' && pass "HTTP/2 actif" || bad "HTTP/2 absent"
curl -sk -o /dev/null -w '%{http_code}' -H 'Host: evil.example' "http://127.0.0.1:$HTTP_PORT/v/x" | grep -q '^000$' && pass "hôte inconnu refusé (HTTP)" || bad "hôte inconnu non refusé (HTTP)"
curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1:$HTTPS_PORT/login" | grep -q '^000$' && pass "connexion TLS sans SNI valide refusée" || bad "connexion TLS sans SNI acceptée"
curl -s -o /dev/null -w '%{redirect_url}' --resolve "$RES2" "http://civipass.test:$HTTP_PORT/login" | grep -q '^https://civipass.test/login$' && pass "redirection HTTP vers le domaine fixe" || bad "redirection HTTP"

# --- 6. journal d'erreur : brut (résiduel connu) puis filtré ---
docker stop "$PHP" >/dev/null
for p in "/v/abcdefghijklmnopqrstuvwxyz234567" "/laissez-passer/SSSSsecretBeneficiaireSSSSSSSSSSSSSSSSSSSSSSS1?x=DupontMartinSentinelle" "/invitation/IIIIinvitationTokenIIIIIIIIIIIIIIIIIIIIIIIIII1" "/reset-password/RRRRresetPasswordTokRRRRRRRRRRRRRRRRRRRRRRRR1" "//v/abcdefghijklmnopqrstuvwxyz234567" "/%76/abcdefghijklmnopqrstuvwxyz234567" "/communes/0192f0a0-0000-7000-8000-000000000000/demandes?q=DupontMartinSentinelle"; do
    curl -sk --resolve "$RES1" -o /dev/null -H "Referer: https://civipass.test/v/abcdefghijklmnopqrstuvwxyz234567?r=1" --path-as-is "https://civipass.test:$HTTPS_PORT$p"
done
sleep 1
ERR="$W/logs/civipass-error.log"; total=$(wc -l < "$ERR"); raw=$(grep -c -E 'abcdefghijklmnopqrstuvwxyz234567|SSSSsecret|IIIIinvitation|RRRRreset|DupontMartin' "$ERR")
echo "    journal d'erreur brut : $total ligne(s), $raw avec secret ou donnée personnelle (RÉSIDUEL CONNU : nginx ne masque pas ce journal)"
[ "$total" -ge 7 ] && pass "le journal d'erreur est bien alimenté (le contrôle n'est pas vide)" || bad "journal d'erreur trop court"
"$OPS/bin/civipass-sanitize-nginx-error" < "$ERR" > "$W/error.clean"
clean=$(grep -c -E 'abcdefghijklmnopqrstuvwxyz234567|SSSSsecret|IIIIinvitation|RRRRreset|DupontMartin' "$W/error.clean")
[ "$clean" -eq 0 ] && [ "$(wc -l < "$W/error.clean")" -eq "$total" ] && pass "filtre : 0 secret restant, $total lignes conservées" || bad "filtre : $clean ligne(s) avec secret restante(s)"
grep -q 'connect() failed' "$W/error.clean" && grep -q 'upstream: "fastcgi://' "$W/error.clean" && grep -q 'client: ' "$W/error.clean" && pass "filtre : diagnostic conservé (erreur, amont, client)" || bad "filtre : diagnostic perdu"
grep -q '0192f0a0-0000-7000-8000-000000000000' "$W/error.clean" && pass "filtre : l'UUID (identifiant interne) est conservé" || bad "filtre : UUID masqué"

echo; [ "$fail" -eq 0 ] && echo "RESULTAT : CONFORME ($NGX_IMAGE)" || echo "RESULTAT : $fail non-conformité(s) ($NGX_IMAGE)"
exit $((fail > 0))
