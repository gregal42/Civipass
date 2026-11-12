#!/usr/bin/env bash
# Vérification de la dernière sauvegarde (LOT 05.6.7) : somme de contrôle, restauration dans une base TEMPORAIRE, comparaison de la liste des tables et du
# volume, suppression de la base temporaire. Ne touche jamais la base en service.
#
#   verify-backup.sh [--dry-run]
# Variables : BACKUP_DIR, DB_NAME (base en service, lecture seule), MYSQL_DEFAULTS_FILE.
set -euo pipefail
umask 077

dry=0
[ "${1:-}" = "--dry-run" ] && dry=1
: "${BACKUP_DIR:?BACKUP_DIR est obligatoire}"
: "${DB_NAME:?DB_NAME est obligatoire}"
defaults=()
[ -n "${MYSQL_DEFAULTS_FILE:-}" ] && defaults=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE")

latest="$(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' | sort | tail -n 1)"
[ -n "$latest" ] || { echo "aucune sauvegarde dans $BACKUP_DIR" >&2; exit 1; }
tmp="civipass_verify_$$"
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] sha256sum -c $latest/SHA256SUMS ; restauration de $latest/db.sql.gz dans $tmp ; comparaison avec $DB_NAME ; DROP DATABASE $tmp"
  exit 0
fi
(cd "$latest" && sha256sum -c SHA256SUMS)
cleanup() { mysql "${defaults[@]}" -e "DROP DATABASE IF EXISTS \`$tmp\`" >/dev/null 2>&1 || true; }
trap cleanup EXIT
mysql "${defaults[@]}" -e "CREATE DATABASE \`$tmp\` CHARACTER SET utf8mb4"
gunzip -c "$latest/db.sql.gz" | mysql "${defaults[@]}" "$tmp"
count() { mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }
restored="$(count "$tmp")"; live="$(count "$DB_NAME")"
if [ "$restored" != "$live" ]; then echo "liste des tables différente : sauvegarde $restored, base en service $live" >&2; exit 1; fi
for table in event_event identity_user audit_log request_permit_request; do
  r="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM \`$tmp\`.\`$table\`")"
  l="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$table\`")"
  echo "$table : sauvegarde $r, base en service $l (écart normal si des écritures ont eu lieu depuis)"
done
echo "sauvegarde $(basename "$latest") vérifiée"
