#!/usr/bin/env bash
# Vérifications de préparation d'un serveur Civipass — LECTURE SEULE (aucune écriture, aucun service modifié).
#
#   preflight.sh [--stage system|app] [--app-dir /opt/civipass] [--domain D] [--control-domain C]
#
#   system : système, paquets, versions, utilisateur, droits, horloge, espace disque, certificats, nginx.
#   app    : application déployée (env, base, migrations, interrupteurs, battements, clés). À lancer APRÈS le déploiement du code.
# Sortie : « ok » / « KO » / « !! » (à examiner). Code de sortie non nul s'il y a au moins un KO.
set -u
STAGE=system; APP_DIR=/opt/civipass; DOMAIN=""; CONTROL=""
while [ $# -gt 0 ]; do
  case "$1" in
    --stage) STAGE="${2:?}"; shift 2 ;;
    --app-dir) APP_DIR="${2:?}"; shift 2 ;;
    --domain) DOMAIN="${2:?}"; shift 2 ;;
    --control-domain) CONTROL="${2:?}"; shift 2 ;;
    *) echo "argument inconnu : $1" >&2; exit 64 ;;
  esac
done
ENV_FILE=/etc/civipass/civipass.env
fail=0
ok() { echo "ok  $*"; }
ko() { echo "KO  $*"; fail=$((fail + 1)); }
warn() { echo "!!  $*"; }
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }
envval() { [ -r "$ENV_FILE" ] || return 0; sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | sed -e "s/^'//" -e "s/'$//" -e 's/^"//' -e 's/"$//'; }

check_system() {
  . /etc/os-release 2>/dev/null || true
  case "${ID:-?}:${VERSION_ID:-?}" in
    debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) ok "système ${PRETTY_NAME}" ;;
    *) ko "distribution non prise en charge : ${PRETTY_NAME:-inconnue}" ;;
  esac
  [ "$(uname -m)" = x86_64 ] || [ "$(uname -m)" = aarch64 ] && ok "architecture $(uname -m)" || warn "architecture $(uname -m) non éprouvée"
  [ "$(nproc)" -ge 2 ] && ok "$(nproc) vCPU" || ko "au moins 2 vCPU requis ($(nproc))"
  mem_mb=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
  [ "$mem_mb" -ge 3500 ] && ok "mémoire ${mem_mb} Mo" || warn "mémoire ${mem_mb} Mo : 4 Go recommandés pour le pilote"
  free_gb=$(df -P / | awk 'NR==2 {print int($4/1024/1024)}')
  [ "$free_gb" -ge 20 ] && ok "espace libre sur / : ${free_gb} Go" || ko "moins de 20 Go libres sur / (${free_gb} Go)"
  if command -v timedatectl >/dev/null 2>&1 && [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ]; then ok "horloge synchronisée (NTP)"; else ko "horloge non synchronisée (NTP) : requis (expiration des liens, des appareils, des sessions)"; fi
  command -v docker >/dev/null 2>&1 && warn "Docker présent : non requis ni utilisé par Civipass en production"

  if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then ok "PHP $(php -r 'echo PHP_VERSION;')"; else ko "PHP >= 8.4 requis"; fi
  for ext in ctype iconv pdo_mysql intl mbstring dom xml gd zip sodium fileinfo curl Zend\ OPcache; do
    php -m 2>/dev/null | grep -qix "$ext" && ok "extension $ext" || ko "extension PHP manquante : $ext"
  done
  [ "$(php -r 'echo ini_get("upload_max_filesize");' 2>/dev/null)" = "10M" ] && ok "upload_max_filesize = 10M (CLI)" || warn "upload_max_filesize CLI != 10M (vérifier aussi FPM : /etc/php/8.4/fpm/conf.d/90-civipass.ini)"
  command -v composer >/dev/null 2>&1 && ok "composer présent" || ko "composer absent"
  if command -v nginx >/dev/null 2>&1; then
    v="$(nginx -v 2>&1 | sed -E 's/.*nginx\/([0-9.]+).*/\1/')"
    version_ge "$v" 1.25.1 && ok "nginx $v (>= 1.25.1)" || ko "nginx $v trop ancien (>= 1.25.1 requis)"
  else ko "nginx absent"; fi
  command -v mysql >/dev/null 2>&1 && ok "client mysql : $(mysql --version | cut -c1-80)" || warn "client mysql absent (sauvegardes impossibles)"
  command -v mysqldump >/dev/null 2>&1 && ok "mysqldump présent" || ko "mysqldump absent (sauvegarde)"
  command -v flock >/dev/null 2>&1 && command -v timeout >/dev/null 2>&1 && ok "flock et timeout (ops/bin/run-job)" || ko "flock/timeout manquants"

  id civipass >/dev/null 2>&1 && ok "utilisateur civipass" || ko "utilisateur civipass absent"
  id -nG civipass 2>/dev/null | tr ' ' '\n' | grep -qx adm && ko "civipass ne doit PAS appartenir au groupe adm (journaux nginx bruts)"
  for d in /var/log/civipass/nginx /var/log/civipass/php /etc/civipass /var/backups/civipass; do [ -d "$d" ] && ok "répertoire $d" || ko "répertoire manquant : $d"; done
  if [ -e "$ENV_FILE" ]; then
    perm="$(stat -c '%a %U:%G' "$ENV_FILE")"
    [ "$perm" = "640 root:civipass" ] && ok "$ENV_FILE ($perm)" || ko "$ENV_FILE : droits $perm (attendu 640 root:civipass)"
    grep -q 'CHANGE_ME' "$ENV_FILE" && ko "valeurs CHANGE_ME restantes dans $ENV_FILE"
  else ko "$ENV_FILE absent"; fi
  command -v age >/dev/null 2>&1 && ok "age installé (chiffrement des sauvegardes)" || ko "age absent (apt install age) : les sauvegardes ne peuvent pas être chiffrées (SEC-004)"
  if grep -rIl 'AGE-SECRET-KEY-' /etc/civipass "$APP_DIR" 2>/dev/null | grep -q .; then ko "une clé PRIVÉE age se trouve sur ce serveur : la retirer et la conserver hors serveur (SEC-004)"; else ok "aucune clé privée age sur le serveur"; fi
  systemctl is-active --quiet php8.4-fpm && ok "php8.4-fpm actif" || ko "php8.4-fpm inactif"
  [ -S /run/php/php8.4-fpm-civipass.sock ] && ok "socket du pool civipass" || ko "socket /run/php/php8.4-fpm-civipass.sock absente"
  for f in /etc/php/8.4/fpm/pool.d/www.conf; do [ -e "$f" ] && warn "pool « www » encore actif ($f)"; done
  for u in /usr/local/sbin/civipass-sanitize-nginx-error /usr/local/sbin/civipass-nginx-log-hook; do [ -x "$u" ] && ok "$u" || ko "$u manquant"; done
  if [ -n "$DOMAIN" ]; then
    for d in "$DOMAIN" ${CONTROL:+"$CONTROL"}; do
      c="/etc/letsencrypt/live/$d/fullchain.pem"
      if [ -r "$c" ]; then
        openssl x509 -checkend $((21*86400)) -noout -in "$c" >/dev/null && ok "certificat $d valide > 21 jours" || ko "certificat $d expire dans moins de 21 jours"
      else ko "certificat absent : $c"; fi
      getent hosts "$d" >/dev/null && ok "DNS $d résolu" || ko "DNS $d non résolu"
    done
  fi
  ss -ltn 2>/dev/null | grep -qE ':(3306|9000)\b' && ss -ltn | grep -E ':(3306|9000)\b' | grep -vq '127.0.0.1\|\[::1\]' && ko "MySQL (3306) ou PHP-FPM TCP (9000) à l'écoute hors boucle locale"
  ss -ltn 2>/dev/null | grep -q ':25 ' && warn "port 25 à l'écoute"
  if command -v nginx >/dev/null 2>&1 && [ -r /etc/nginx/nginx.conf ]; then
    nginx -t >/dev/null 2>&1 && ok "nginx -t" || ko "nginx -t en échec"
    nginx -T 2>/dev/null | grep -E '^\s*access_log' | grep -v civipass | grep -v 'off' | grep -q . && ko "un access_log non Civipass est actif (URL avec secrets en clair)"
  fi
}

check_app() {
  [ -f "$APP_DIR/bin/console" ] && ok "application dans $APP_DIR" || { ko "application absente de $APP_DIR"; return; }
  [ -d "$APP_DIR/vendor" ] && ok "vendor présent" || ko "vendor absent (composer install --no-dev)"
  [ -f "$APP_DIR/assets/vendor/jsqr/jsQR.js" ] && ok "assets/vendor/jsqr/jsQR.js (scanneur hors ligne)" || ko "assets/vendor/jsqr/jsQR.js ABSENT : le scanneur du contrôle hors connexion ne fonctionnera pas"
  [ -d "$APP_DIR/public/assets" ] && ok "public/assets compilé" || ko "public/assets absent (php bin/console asset-map:compile)"
  [ -d "$APP_DIR/public/bundles" ] && ok "public/bundles (Sonata)" || ko "public/bundles absent (assets:install public)"
  [ -L "$APP_DIR/.env.local" ] && ok ".env.local → $(readlink "$APP_DIR/.env.local")" || warn ".env.local n'est pas le lien vers $ENV_FILE"
  for d in var/cache var/log var/storage var/ops; do
    [ -d "$APP_DIR/$d" ] && [ "$(stat -c '%U' "$APP_DIR/$d")" = civipass ] && ok "$d appartient à civipass" || ko "$d absent ou mal attribué"
  done
  if [ -x "$APP_DIR/ops/nginx/bin/check-deployment.sh" ] && [ -n "$DOMAIN" ]; then
    args=(--domain "$DOMAIN"); [ -n "$CONTROL" ] && args+=(--control-domain "$CONTROL")
    out="$("$APP_DIR/ops/nginx/bin/check-deployment.sh" "${args[@]}" "$APP_DIR/.env" "$ENV_FILE" 2>&1)" && ok "check-deployment.sh : tous les contrôles passent" || { echo "$out" | grep -E '^(KO|!!)'; ko "check-deployment.sh a échoué"; }
  else warn "check-deployment.sh non exécuté (passer --domain)"; fi
  for k in PUBLIC_VERIFICATION_ENABLED OFFLINE_CONTROL_ENABLED REFUSAL_NOTICE_ENABLED RETENTION_PURGE_ENABLED RIGHTS_ERASURE_EXECUTION_ENABLED; do
    v="$(envval "$k")"; [ "${v:-0}" = "0" ] && ok "$k=0" || warn "$k=${v} : activation soumise à l'accord horodaté du responsable du projet"
  done
  case "$(envval MAILER_DSN)" in null:*|"") ko "MAILER_DSN absent ou « null » : aucun e-mail ne partira" ;; *) ok "MAILER_DSN défini" ;; esac
  [ -n "$(envval OPS_ALERT_EMAIL)" ] && ok "OPS_ALERT_EMAIL défini" || ko "OPS_ALERT_EMAIL vide : aucune alerte d'exploitation"
  run_app() { (cd "$APP_DIR" && runuser -u civipass -- php bin/console "$@" 2>&1); }
  out="$(run_app doctrine:schema:validate --skip-mapping)" && ok "schéma de base synchronisé" || { echo "$out" | tail -3; ko "doctrine:schema:validate en échec"; }
  out="$(run_app doctrine:migrations:up-to-date)" && ok "migrations à jour" || ko "migrations en attente ou base injoignable"
  out="$(run_app app:notifications:check-crypto)" && ok "trousseau de chiffrement valide" || { echo "$out" | tail -2; ko "app:notifications:check-crypto en échec"; }
  out="$(run_app app:ops:status)"; rc=$?; echo "$out" | sed 's/^/    /'; [ $rc -eq 0 ] && ok "app:ops:status sans alerte" || warn "app:ops:status signale des tâches (normal juste après l'installation, avant le premier cycle)"
  systemctl list-timers 'civipass-*' --no-legend 2>/dev/null | grep -q . && ok "minuteurs civipass actifs" || warn "aucun minuteur civipass actif"
}

case "$STAGE" in system) check_system ;; app) check_app ;; *) echo "stage inconnu : $STAGE" >&2; exit 64 ;; esac
echo; [ "$fail" -eq 0 ] && echo "preflight ($STAGE) : aucun KO" || echo "preflight ($STAGE) : $fail KO"
exit $((fail > 0 ? 1 : 0))
