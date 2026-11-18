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

KEYS="CIVIPASS_DOMAIN CONTROL_DOMAIN TLS_CONTROL_CERTIFICATE TLS_CONTROL_CERTIFICATE_KEY TLS_CERTIFICATE TLS_CERTIFICATE_KEY DOCROOT PHP_FPM_UPSTREAM LOG_DIR ACCESS_LOG_RETENTION_DAYS ERROR_LOG_RETENTION_HOURS NGINX_USER ADMIN_GROUP NGINX_PID_FILE SANITIZER_PATH HOOK_PATH LOGROTATE_STATE LOGROTATE_CONF"
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
# Origine dédiée au contrôle (facultative) : domaine propre, DISTINCT de celui de la gestion, et certificat propre à ce sous-domaine.
if [ -n "$V_CONTROL_DOMAIN" ]; then
    [[ "$V_CONTROL_DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] && [[ "$V_CONTROL_DOMAIN" == *.* ]] || die "CONTROL_DOMAIN (nom de domaine en minuscules)"
    [ "$V_CONTROL_DOMAIN" != "$V_CIVIPASS_DOMAIN" ] || die "CONTROL_DOMAIN doit être différent de CIVIPASS_DOMAIN (origines distinctes : stockage local et cookies séparés)"
    if [ "$ALLOW_EXAMPLE" -ne 1 ] && [[ "$V_CONTROL_DOMAIN" =~ \.(example|test|invalid|localhost)$ || "$V_CONTROL_DOMAIN" == example.* ]]; then
        die "CONTROL_DOMAIN=$V_CONTROL_DOMAIN est un domaine d'EXEMPLE : renseigner le vrai domaine avant de déployer"
    fi
    for k in TLS_CONTROL_CERTIFICATE TLS_CONTROL_CERTIFICATE_KEY; do
        [ -n "$(get "$k")" ] || die "$k est obligatoire avec CONTROL_DOMAIN (un certificat par sous-domaine)"
        [[ "$(get "$k")" =~ ^/[A-Za-z0-9_./-]+$ ]] || die "$k (chemin absolu, caractères A-Za-z0-9_./- seulement)"
    done
    [ "$V_TLS_CONTROL_CERTIFICATE" != "$V_TLS_CERTIFICATE" ] || die "TLS_CONTROL_CERTIFICATE doit être distinct de TLS_CERTIFICATE (un certificat par sous-domaine)"
else
    [ -z "$V_TLS_CONTROL_CERTIFICATE$V_TLS_CONTROL_CERTIFICATE_KEY" ] || die "TLS_CONTROL_CERTIFICATE* renseigné sans CONTROL_DOMAIN"
fi
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
# Gabarit de gestion : le repère CONTROL_GUARD est remplacé par le garde-fou complémentaire quand CONTROL_DOMAIN est renseigné, et SUPPRIMÉ sinon (le fichier généré
# est alors strictement identique à celui d'avant : contrôlé par comparaison à un fichier de référence).
SITE_TMP=$(mktemp); GUARD_TMP=""; DEFAULT_GUARD_TMP=""; trap 'rm -f "$SITE_TMP" "$GUARD_TMP" "$DEFAULT_GUARD_TMP"' EXIT
if [ -n "$V_CONTROL_DOMAIN" ]; then
    GUARD_TMP=$(mktemp); render "$T/10-control-guard.snippet.template" "$GUARD_TMP"
    DEFAULT_GUARD_TMP=$(mktemp); render "$T/10-control-default-guard.snippet.template" "$DEFAULT_GUARD_TMP"
    awk -v guard="$GUARD_TMP" -v dguard="$DEFAULT_GUARD_TMP" '
        /^    # CONTROL_GUARD$/ { while ((getline line < guard) > 0) print line; next }
        /^    # CONTROL_DEFAULT_GUARD$/ { while ((getline line < dguard) > 0) print line; next }
        { print }' "$T/10-civipass-site.conf.template" > "$SITE_TMP"
else
    grep -v -e '^    # CONTROL_GUARD$' -e '^    # CONTROL_DEFAULT_GUARD$' "$T/10-civipass-site.conf.template" | cat -s > "$SITE_TMP"
fi
render "$SITE_TMP" "$OUT/conf.d/10-civipass-site.conf"

[ -z "$V_CONTROL_DOMAIN" ] || render "$T/05-civipass-control-maps.conf.template" "$OUT/conf.d/05-civipass-control-maps.conf"
[ -z "$V_CONTROL_DOMAIN" ] || render "$T/20-civipass-control.conf.template" "$OUT/conf.d/20-civipass-control.conf"
render "$T/logrotate-civipass-nginx.conf.template" "$OUT/logrotate.conf"
render "$HERE/systemd/civipass-nginx-logrotate.service.template" "$OUT/systemd/civipass-nginx-logrotate.service"
render "$HERE/systemd/civipass-nginx-logrotate.timer.template" "$OUT/systemd/civipass-nginx-logrotate.timer"
echo "Fichiers générés dans $OUT (domaine $V_CIVIPASS_DOMAIN${V_CONTROL_DOMAIN:+, contrôle $V_CONTROL_DOMAIN}). Voir ops/nginx/README.md pour l'installation."
