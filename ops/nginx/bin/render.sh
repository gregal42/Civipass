#!/bin/bash
# Génère les fichiers de déploiement nginx de Civipass à partir des modèles de ops/nginx/templates et d'un fichier de paramètres.
#   render.sh --env civipass.env --out DOSSIER [--allow-example]
# Le fichier de paramètres est LU (jamais exécuté) : CLE=valeur. Les valeurs sont validées ; le domaine d'exemple est refusé sauf --allow-example (tests).
set -eu
HERE=$(cd "$(dirname "$0")/.." && pwd)
ENV_FILE=""; OUT=""; ALLOW_EXAMPLE=0
while [ $# -gt 0 ]; do
    case "$1" in
        --env) ENV_FILE=${2:?}; shift 2;;
        --out) OUT=${2:?}; shift 2;;
        --allow-example) ALLOW_EXAMPLE=1; shift;;
        *) echo "argument inconnu : $1" >&2; exit 2;;
    esac
done
[ -n "$ENV_FILE" ] && [ -f "$ENV_FILE" ] && [ -n "$OUT" ] || { echo "usage: $0 --env FICHIER --out DOSSIER [--allow-example]" >&2; exit 2; }

KEYS="CIVIPASS_DOMAIN TLS_CERTIFICATE TLS_CERTIFICATE_KEY DOCROOT PHP_FPM_UPSTREAM LOG_DIR ACCESS_LOG_RETENTION_DAYS ERROR_LOG_RETENTION_HOURS NGINX_USER ADMIN_GROUP NGINX_PID_FILE SANITIZER_PATH HOOK_PATH LOGROTATE_STATE LOGROTATE_CONF"
for k in $KEYS; do printf -v "V_$k" '%s' ""; done
V_LOG_DIR=/var/log/civipass/nginx; V_ACCESS_LOG_RETENTION_DAYS=30; V_ERROR_LOG_RETENTION_HOURS=168
V_NGINX_USER=www-data; V_ADMIN_GROUP=adm; V_NGINX_PID_FILE=/run/nginx.pid
V_SANITIZER_PATH=/usr/local/sbin/civipass-sanitize-nginx-error; V_HOOK_PATH=/usr/local/sbin/civipass-nginx-log-hook
V_LOGROTATE_STATE=/var/lib/civipass/logrotate-nginx.status; V_LOGROTATE_CONF=/etc/civipass/nginx/logrotate.conf
get() { local n="V_$1"; printf '%s' "${!n}"; }
while IFS= read -r line || [ -n "$line" ]; do
    case "$line" in ''|'#'*) continue;; esac
    case "$line" in *=*) ;; *) echo "ligne invalide : $line" >&2; exit 2;; esac
    key=${line%%=*}; val=${line#*=}
    case " $KEYS " in *" $key "*) ;; *) echo "paramètre inconnu : $key" >&2; exit 2;; esac
    printf -v "V_$key" '%s' "$val"
done < "$ENV_FILE"

die() { echo "paramètre invalide : $*" >&2; exit 2; }
for k in CIVIPASS_DOMAIN TLS_CERTIFICATE TLS_CERTIFICATE_KEY DOCROOT PHP_FPM_UPSTREAM; do [ -n "$(get "$k")" ] || die "$k est obligatoire"; done
[[ "$V_CIVIPASS_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] && [[ "$V_CIVIPASS_DOMAIN" == *.* ]] || die "CIVIPASS_DOMAIN (nom de domaine en minuscules)"
if [ "$ALLOW_EXAMPLE" -ne 1 ] && [[ "$V_CIVIPASS_DOMAIN" =~ \.(example|test|invalid|localhost)$ || "$V_CIVIPASS_DOMAIN" == example.* ]]; then
    die "CIVIPASS_DOMAIN=$V_CIVIPASS_DOMAIN est un domaine d'EXEMPLE : renseigner le vrai domaine avant de déployer"
fi
for k in ACCESS_LOG_RETENTION_DAYS ERROR_LOG_RETENTION_HOURS; do [[ "$(get "$k")" =~ ^[1-9][0-9]{0,4}$ ]] || die "$k (entier positif)"; done
for k in TLS_CERTIFICATE TLS_CERTIFICATE_KEY DOCROOT LOG_DIR NGINX_PID_FILE SANITIZER_PATH HOOK_PATH LOGROTATE_STATE LOGROTATE_CONF; do
    [[ "$(get "$k")" =~ ^/[A-Za-z0-9_./-]+$ ]] || die "$k (chemin absolu, caractères A-Za-z0-9_./- seulement)"
done
[[ "$V_PHP_FPM_UPSTREAM" =~ ^(unix:/[A-Za-z0-9_./-]+|[A-Za-z0-9_.-]+:[0-9]{1,5})$ ]] || die "PHP_FPM_UPSTREAM (unix:/chemin ou hôte:port)"
for k in NGINX_USER ADMIN_GROUP; do [[ "$(get "$k")" =~ ^[a-z_][a-z0-9_-]*$ ]] || die "$k"; done

render() { # $1 = modèle, $2 = sortie
    local in=$1 out=$2 expr=() k
    for k in $KEYS; do expr+=(-e "s|__${k}__|$(get "$k")|g"); done
    mkdir -p "$(dirname "$out")"
    sed "${expr[@]}" "$in" > "$out"
    if grep -nE '__[A-Z][A-Z0-9_]*__' "$out" >&2; then echo "marqueur non remplacé dans $out" >&2; exit 1; fi
}
T="$HERE/templates"
render "$T/nginx.conf.template" "$OUT/nginx.conf"
render "$T/00-civipass-logging.conf.template" "$OUT/conf.d/00-civipass-logging.conf"
render "$T/10-civipass-site.conf.template" "$OUT/conf.d/10-civipass-site.conf"
render "$T/logrotate-civipass-nginx.conf.template" "$OUT/logrotate.conf"
render "$HERE/systemd/civipass-nginx-logrotate.service.template" "$OUT/systemd/civipass-nginx-logrotate.service"
render "$HERE/systemd/civipass-nginx-logrotate.timer.template" "$OUT/systemd/civipass-nginx-logrotate.timer"
echo "Fichiers générés dans $OUT (domaine $V_CIVIPASS_DOMAIN). Voir ops/nginx/README.md pour l'installation."
