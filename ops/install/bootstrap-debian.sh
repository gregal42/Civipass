#!/usr/bin/env bash
# Préparation d'un serveur VIERGE Debian 12/13 ou Ubuntu 22.04/24.04 pour Civipass (kit V1.0). À exécuter EN ROOT, sur le serveur.
#
#   bootstrap-debian.sh [--apply] [--add-third-party-repos] [--app-dir /opt/civipass]
#
# Sans --apply : AFFICHE seulement ce qui serait fait (aucune écriture). Idempotent : peut être rejoué.
# Ce script ne fait PAS : installation de MySQL (voir docs/exploitation/installation.md §4), certificats TLS, génération de la configuration nginx,
# déploiement du code, migrations, activation des minuteurs, pare-feu. Aucune dépendance à Docker.
#
# Ce que le script fait : paquets (nginx, PHP 8.4 et extensions, outils), Composer vérifié, utilisateur « civipass », répertoires et droits,
# réglages PHP de production, pool PHP-FPM dédié, scripts de journalisation nginx, unités systemd (copiées, NON activées), squelette
# /etc/civipass/civipass.env (créé seulement s'il est absent ; secrets générés ; valeurs à remplacer marquées CHANGE_ME).
set -euo pipefail

APPLY=0; THIRD=0; APP_DIR=/opt/civipass
while [ $# -gt 0 ]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --add-third-party-repos) THIRD=1; shift ;;
    --app-dir) APP_DIR="${2:?}"; shift 2 ;;
    *) echo "argument inconnu : $1" >&2; exit 64 ;;
  esac
done
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"   # racine du dépôt ou de l'archive de version contenant ops/

say() { echo "==> $*"; }
run() { if [ "$APPLY" -eq 1 ]; then "$@"; else echo "[dry-run] $*"; fi; }
die() { echo "ERREUR : $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "à exécuter en root"
[ -r /etc/os-release ] || die "/etc/os-release absent"
# shellcheck disable=SC1091
. /etc/os-release
case "${ID}:${VERSION_ID}" in
  debian:12|debian:13|ubuntu:22.04|ubuntu:24.04) say "système pris en charge : ${PRETTY_NAME}" ;;
  *) die "distribution non prise en charge : ${PRETTY_NAME} (Debian 12/13, Ubuntu 22.04/24.04)" ;;
esac
export DEBIAN_FRONTEND=noninteractive

# --- Dépôts : PHP 8.4 et nginx stable >= 1.25.1 ---------------------------------------------------------------------------------------------
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }   # version_ge A B : A >= B

have_candidate() { apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ {print $2}' | grep -qv '(none)'; }

run apt-get update -y
run apt-get install -y ca-certificates curl gnupg lsb-release unzip git logrotate perl rsync openssl cron age

if [ "$APPLY" -eq 1 ]; then apt-get update -y >/dev/null; fi
if ! have_candidate php8.4-fpm; then
  [ "$THIRD" -eq 1 ] || die "php8.4-fpm absent des dépôts de la distribution. Relancer avec --add-third-party-repos (dépôt tiers Sury/Ondřej : décision de confiance à valider) ou installer PHP 8.4 autrement."
  say "ajout du dépôt PHP tiers (Sury / Ondřej)"
  if [ "$ID" = "debian" ]; then
    run curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    run dpkg -i /tmp/debsuryorg-archive-keyring.deb
    run sh -c "echo 'deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main' > /etc/apt/sources.list.d/php-sury.list"
  else
    run apt-get install -y software-properties-common
    run add-apt-repository -y ppa:ondrej/php
  fi
  run apt-get update -y
fi

NGINX_OK=0
if have_candidate nginx; then
  cand="$(apt-cache policy nginx | awk '/Candidate:/ {print $2}' | sed -E 's/[-+~].*//')"
  version_ge "${cand:-0}" 1.25.1 && NGINX_OK=1
fi
if [ "$NGINX_OK" -eq 0 ]; then
  [ "$THIRD" -eq 1 ] || die "nginx >= 1.25.1 (directive « http2 on; ») absent des dépôts de la distribution. Relancer avec --add-third-party-repos (dépôt nginx.org) ou installer nginx stable autrement."
  say "ajout du dépôt nginx.org (stable)"
  run install -d -m 0755 /usr/share/keyrings
  run sh -c "curl -fsSL https://nginx.org/keys/nginx_signing.key | gpg --dearmor -o /usr/share/keyrings/nginx-archive-keyring.gpg"
  if [ "$APPLY" -eq 1 ]; then
    fpr="$(gpg --show-keys --with-colons /usr/share/keyrings/nginx-archive-keyring.gpg | awk -F: '/^fpr/ {print $10; exit}')"
    echo "Empreinte de la clé nginx.org : ${fpr} — À COMPARER avec celle publiée sur https://nginx.org/en/linux_packages.html"
    if [ -n "${NGINX_KEY_FPR:-}" ] && [ "${NGINX_KEY_FPR}" != "${fpr}" ]; then die "empreinte nginx inattendue"; fi
  fi
  run sh -c "echo 'deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://nginx.org/packages/${ID} ${VERSION_CODENAME} nginx' > /etc/apt/sources.list.d/nginx.list"
  run sh -c "printf 'Package: *\nPin: origin nginx.org\nPin: release o=nginx\nPin-Priority: 900\n' > /etc/apt/preferences.d/99nginx"
  run apt-get update -y
fi

say "paquets nginx et PHP 8.4"
run apt-get install -y nginx php8.4-cli php8.4-fpm php8.4-mysql php8.4-intl php8.4-mbstring php8.4-xml php8.4-gd php8.4-zip php8.4-curl php8.4-opcache certbot

# --- Composer (installeur officiel, signature vérifiée) -------------------------------------------------------------------------------------
if ! command -v composer >/dev/null 2>&1; then
  say "installation de Composer (signature vérifiée)"
  if [ "$APPLY" -eq 1 ]; then
    tmp="$(mktemp -d)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSLo "$tmp/composer-setup.php" https://getcomposer.org/installer
    actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")"
    [ "$expected" = "$actual" ] || { rm -rf "$tmp"; die "signature de l'installeur Composer invalide"; }
    php "$tmp/composer-setup.php" --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
  else
    echo "[dry-run] installeur Composer avec vérification de signature"
  fi
fi

# --- Utilisateur, répertoires, droits --------------------------------------------------------------------------------------------------------
say "utilisateur et répertoires"
id civipass >/dev/null 2>&1 || run useradd --system --home-dir /var/lib/civipass --shell /usr/sbin/nologin --user-group civipass
run install -d -o root -g civipass -m 0755 "$APP_DIR"   # code lisible par nginx (public/) ; les données sont dans var/ (0750 civipass)
run install -d -o civipass -g civipass -m 0750 /var/lib/civipass /var/lib/civipass/composer
run install -d -o root -g civipass -m 0750 /etc/civipass
run install -d -o root -g root -m 0755 /etc/civipass/nginx
run install -d -o civipass -g civipass -m 0700 /var/backups/civipass
run install -d -o www-data -g adm -m 0750 /var/log/civipass/nginx
run install -d -o civipass -g adm -m 0750 /var/log/civipass/php
if [ "$APPLY" -eq 1 ]; then
  for f in /var/log/civipass/nginx/civipass-access.log /var/log/civipass/nginx/civipass-error.log; do
    [ -e "$f" ] || install -m 0640 -o www-data -g adm /dev/null "$f"
  done
  [ -e /var/log/civipass/php/php-error.log ] || install -m 0640 -o civipass -g adm /dev/null /var/log/civipass/php/php-error.log
fi

# --- Réglages PHP de production (CLI et FPM) -------------------------------------------------------------------------------------------------
say "réglages PHP de production"
ini_body() {
cat <<'INI'
; Civipass — réglages de production (kit V1.0). Fichier géré par ops/install/bootstrap-debian.sh.
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M
max_execution_time = 60
; Dépôt public : jusqu'à 5 pièces de 10 Mo (UploadedDocumentInspector) ; nginx autorise 64 Mo (client_max_body_size).
upload_max_filesize = 10M
post_max_size = 64M
max_file_uploads = 20
date.timezone = UTC
opcache.enable = 1
opcache.validate_timestamps = 0
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
; Sessions : voir ops/php/civipass-session.ini.example (LOT 10, M6)
session.gc_maxlifetime = 10800
INI
}
for sapi in fpm cli; do
  d="/etc/php/8.4/${sapi}/conf.d"
  if [ "$APPLY" -eq 1 ]; then
    [ -d "$d" ] || die "$d absent (PHP 8.4 ${sapi} installé ?)"
    ini_body > "$d/90-civipass.ini"
  else
    echo "[dry-run] écrire $d/90-civipass.ini"
  fi
done
# opcache.validate_timestamps = 0 : recharger PHP-FPM après chaque mise à jour (update.sh le fait). La CLI n'est pas concernée par opcache.

# --- Pool PHP-FPM dédié ----------------------------------------------------------------------------------------------------------------------
say "pool PHP-FPM « civipass »"
if [ "$APPLY" -eq 1 ]; then
  cp "$SRC/ops/nginx/php-fpm/civipass-pool.conf.example" /etc/php/8.4/fpm/pool.d/civipass.conf
  grep -q '^env\[APP_ENV\]' /etc/php/8.4/fpm/pool.d/civipass.conf || printf '\n; APP_ENV imposé (clear_env = yes) : la prod ne dépend pas du .env.local pour ce point.\nenv[APP_ENV] = prod\n' >> /etc/php/8.4/fpm/pool.d/civipass.conf
  [ -e /etc/php/8.4/fpm/pool.d/www.conf ] && mv /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled
  php-fpm8.4 -t
  systemctl enable --now php8.4-fpm
  systemctl reload php8.4-fpm
else
  echo "[dry-run] installer le pool, désactiver www.conf, php-fpm8.4 -t, recharger"
fi

# --- Scripts nginx (journaux masqués) et unités systemd --------------------------------------------------------------------------------------
say "scripts nginx et unités systemd (copiées, NON activées)"
run install -m 0755 "$SRC/ops/nginx/bin/civipass-sanitize-nginx-error" /usr/local/sbin/
run install -m 0755 "$SRC/ops/nginx/bin/civipass-nginx-log-hook" /usr/local/sbin/
if [ "$APPLY" -eq 1 ]; then
  for u in "$SRC"/ops/systemd/*.service "$SRC"/ops/systemd/*.timer; do install -m 0644 "$u" /etc/systemd/system/; done
  systemctl daemon-reload
else
  echo "[dry-run] copier ops/systemd/*.service et *.timer (PAS optional/) vers /etc/systemd/system/ ; daemon-reload"
fi

# --- Squelette de l'environnement (créé UNE fois) --------------------------------------------------------------------------------------------
ENV_FILE=/etc/civipass/civipass.env
if [ -e "$ENV_FILE" ]; then
  say "$ENV_FILE existe déjà : conservé tel quel"
elif [ "$APPLY" -eq 1 ]; then
  say "création du squelette $ENV_FILE (mode 0640 root:civipass)"
  secret="$(openssl rand -hex 32)"
  key="$(php -r 'echo base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen());')"
  umask 027
  cat > "$ENV_FILE" <<ENV
# Environnement Civipass (production). Format sûr pour systemd ET Symfony Dotenv : CLE='valeur' (guillemets simples, aucun \$ non protégé).
# Référence : docs/exploitation/configuration.md. Remplacer toute valeur CHANGE_ME. Ne JAMAIS versionner ce fichier.
APP_ENV='prod'
APP_DEBUG='0'
APP_SECRET='${secret}'
DEFAULT_URI='https://CHANGE_ME.example'
DATABASE_URL='mysql://civipass:CHANGE_ME@127.0.0.1:3306/civipass?serverVersion=8.4.0&charset=utf8mb4'
MAILER_DSN='smtp://CHANGE_ME:CHANGE_ME@smtp.example:587'
MAILER_FROM='CHANGE_ME@example'
OPS_ALERT_EMAIL='CHANGE_ME@example'
NOTIFICATION_KEYRING='k1:${key}'
NOTIFICATION_ACTIVE_KEY_ID='k1'
PUBLIC_VERIFICATION_ENABLED='0'
OFFLINE_CONTROL_ENABLED='0'
REFUSAL_NOTICE_ENABLED='0'
RETENTION_PURGE_ENABLED='0'
RIGHTS_ERASURE_EXECUTION_ENABLED='0'
# Sauvegarde (unités civipass-backup*) :
BACKUP_DIR='/var/backups/civipass'
# Chiffrement des sauvegardes (SEC-004) : CLÉS PUBLIQUES age1… seulement (deux recommandées). La clé PRIVÉE reste HORS serveur : jamais ici.
BACKUP_AGE_RECIPIENTS='CHANGE_ME'
DB_NAME='civipass'
MYSQL_DEFAULTS_FILE='/etc/civipass/mysql-backup.cnf'
STORAGE_DIR='${APP_DIR}/var/storage'
REGISTER_PATH='${APP_DIR}/var/retention/purge-register.jsonl'
ANCHOR_PATH='${APP_DIR}/var/retention/anchors.jsonl'
ENV
  chown root:civipass "$ENV_FILE"; chmod 0640 "$ENV_FILE"
else
  echo "[dry-run] créer $ENV_FILE (secrets générés, valeurs CHANGE_ME)"
fi

say "terminé$( [ "$APPLY" -eq 1 ] || echo ' (simulation : relancer avec --apply)')"
echo "Suite : docs/exploitation/installation.md §4 (MySQL), §3 (déploiement du code), puis ops/install/preflight.sh --stage system"
