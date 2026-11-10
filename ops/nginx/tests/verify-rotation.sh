#!/bin/bash
# Séquence RÉELLE nginx + logrotate + filtre dans un conteneur Debian jetable (Docker et accès réseau requis pour `apt-get install logrotate curl`). Aucun fichier du projet
# n'est modifié. Scénarios : rotation de base, écritures pendant les rotations, échec du filtre, échec du repli, rotation interrompue, copies résiduelles, permissions.
#   ops/nginx/tests/verify-rotation.sh [image-nginx]      (défaut : nginx:stable, Debian)
set -u
HERE=$(cd "$(dirname "$0")" && pwd); OPS=$(cd "$HERE/.." && pwd)
IMAGE=${1:-nginx:stable}; NAME=civipass-verify-rotation; W=$(mktemp -d)
cleanup() { [ "${KEEP:-0}" = 1 ] || { docker rm -f "$NAME" >/dev/null 2>&1; rm -rf "$W"; }; }
trap cleanup EXIT
command -v docker >/dev/null || { echo "Docker est requis"; exit 2; }
cat > "$W/test.env" <<ENVEOF
CIVIPASS_DOMAIN=civipass.test
TLS_CERTIFICATE=/etc/nginx/tls/cert.pem
TLS_CERTIFICATE_KEY=/etc/nginx/tls/key.pem
DOCROOT=/var/www/html/public
PHP_FPM_UPSTREAM=127.0.0.1:9
NGINX_USER=nginx
ENVEOF
"$OPS/bin/render.sh" --env "$W/test.env" --out "$W/out" --allow-example >/dev/null || exit 1
mkdir -p "$W/tls"; openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=civipass.test" -keyout "$W/tls/key.pem" -out "$W/tls/cert.pem" >/dev/null 2>&1
docker rm -f "$NAME" >/dev/null 2>&1
docker run -d --name "$NAME" --entrypoint sleep -v "$W":/work:ro -v "$OPS":/ops:ro -v "$HERE":/tests:ro "$IMAGE" infinity >/dev/null || exit 1
docker exec "$NAME" bash -c '
set -e
apt-get update >/dev/null 2>&1; DEBIAN_FRONTEND=noninteractive apt-get install -y logrotate curl >/dev/null 2>&1
echo "    $(nginx -v 2>&1) ; $(logrotate --version 2>&1 | head -1)"
mkdir -p /var/log/civipass/nginx /var/lib/civipass /etc/civipass/nginx /etc/nginx/tls /var/www/html/public /dev/shm
chown nginx:adm /var/log/civipass/nginx; chmod 0750 /var/log/civipass/nginx
for f in civipass-access.log civipass-error.log; do install -m 0640 -o nginx -g adm /dev/null /var/log/civipass/nginx/$f; done
install -m 0755 /ops/bin/civipass-sanitize-nginx-error /usr/local/sbin/civipass-sanitize-nginx-error
install -m 0755 /ops/bin/civipass-nginx-log-hook /usr/local/sbin/civipass-nginx-log-hook
install -m 0644 /work/out/nginx.conf /etc/nginx/nginx.conf
rm -f /etc/nginx/conf.d/*; install -m 0644 /work/out/conf.d/*.conf /etc/nginx/conf.d/
install -m 0644 /work/out/logrotate.conf /etc/civipass/nginx/logrotate.conf
install -m 0600 /work/tls/key.pem /etc/nginx/tls/key.pem; install -m 0644 /work/tls/cert.pem /etc/nginx/tls/cert.pem
nginx -t 2>&1 | tail -1
nginx
sleep 1
logrotate --debug /etc/civipass/nginx/logrotate.conf >/dev/null 2>&1 && echo "    configuration logrotate valide"
' || { echo "KO  préparation du conteneur"; exit 1; }
[ "${PREPARE_ONLY:-0}" = 1 ] && { echo "conteneur préparé : $NAME (KEEP=1 pour le conserver)"; exit 0; }
docker exec "$NAME" bash /tests/rotation-scenarios.sh
rc=$?
echo; [ $rc -eq 0 ] && echo "RESULTAT GLOBAL : CONFORME ($IMAGE)" || echo "RESULTAT GLOBAL : non conforme ($IMAGE)"
exit $rc
