#!/usr/bin/env bash
# Sauvegarde Civipass (LOT 05.6.7) : base MySQL, stockage privé, COPIES du registre de purge et des points d'ancrage.
#
#   backup.sh [--dry-run]
#
# Variables (fichier d'environnement du service, jamais dans le dépôt) :
#   BACKUP_DIR (obligatoire, chemin absolu)   DB_NAME (obligatoire)   MYSQL_DEFAULTS_FILE (identifiants, mode 600)
#   STORAGE_DIR   REGISTER_PATH   ANCHOR_PATH   BACKUP_RETENTION_DAYS (35 : hypothèse à valider, politique A1)
#
# IMPORTANT : les copies du registre et des ancrages servent à l'ARCHIVE et à l'analyse ; elles ne sont JAMAIS restaurées par-dessus
# les fichiers courants (le registre est postérieur à toute sauvegarde). Les copies de sauvegarde contiennent des données purgées
# jusqu'à rotation : d'où la durée bornée et le rejeu obligatoire après restauration.
set -euo pipefail
umask 077

dry=0
[ "${1:-}" = "--dry-run" ] && dry=1
run() { if [ "$dry" -eq 1 ]; then echo "[dry-run] $*"; else "$@"; fi; }

: "${BACKUP_DIR:?BACKUP_DIR est obligatoire}"
: "${DB_NAME:?DB_NAME est obligatoire}"
case "$BACKUP_DIR" in /*) ;; *) echo "BACKUP_DIR doit être un chemin absolu" >&2; exit 64 ;; esac
[ "$BACKUP_DIR" != "/" ] || { echo "BACKUP_DIR invalide" >&2; exit 64; }
retention="${BACKUP_RETENTION_DAYS:-35}"
[[ "$retention" =~ ^[0-9]{1,4}$ ]] && [ "$retention" -ge 1 ] || { echo "BACKUP_RETENTION_DAYS invalide" >&2; exit 64; }

ts="$(date -u +%Y%m%dT%H%M%SZ)"
dest="$BACKUP_DIR/$ts"
run mkdir -p "$dest"

defaults=()
[ -n "${MYSQL_DEFAULTS_FILE:-}" ] && defaults=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE")
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] mysqldump --single-transaction --no-tablespaces $DB_NAME | gzip > $dest/db.sql.gz"
else
  mysqldump "${defaults[@]}" --single-transaction --no-tablespaces --routines "$DB_NAME" | gzip > "$dest/db.sql.gz"
fi
[ -n "${STORAGE_DIR:-}" ] && [ -d "${STORAGE_DIR:-}" ] && run tar -C "$STORAGE_DIR" -czf "$dest/storage.tar.gz" .
[ -n "${REGISTER_PATH:-}" ] && [ -f "${REGISTER_PATH:-}" ] && run cp -p "$REGISTER_PATH" "$dest/purge-register.jsonl.copy"
[ -n "${ANCHOR_PATH:-}" ] && [ -f "${ANCHOR_PATH:-}" ] && run cp -p "$ANCHOR_PATH" "$dest/anchors.jsonl.copy"
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] (cd $dest && sha256sum * > SHA256SUMS)"
else
  (cd "$dest" && sha256sum -- * > SHA256SUMS)
fi

# Rotation : uniquement les répertoires horodatés de CE répertoire de sauvegarde, plus anciens que la durée retenue.
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] rotation > $retention jours dans $BACKUP_DIR (répertoires AAAAMMJJTHHMMSSZ)"
else
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' -mtime +"$retention" -exec rm -rf -- {} +
fi
echo "sauvegarde ${ts} terminée"
