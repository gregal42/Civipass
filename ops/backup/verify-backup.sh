#!/usr/bin/env bash
# Vérification de la dernière sauvegarde CHIFFRÉE (LOT 05.6.7, SEC-004). Ne touche jamais la base en service.
#
#   verify-backup.sh [--dry-run]
# Variables : BACKUP_DIR, DB_NAME (base en service, lecture seule), MYSQL_DEFAULTS_FILE.
#
# Sur le SERVEUR (clé privée absente, voulu), ce contrôle vérifie l'INTÉGRITÉ : sommes de contrôle, aucun fichier en clair, chaque fichier est bien un
# flux age non vide. Il ne PROUVE PAS la restauration : celle-ci se prouve hors serveur, avec la clé privée, par ops/backup/restore-from-age.sh (exercice
# régulier, docs/runbooks/sauvegarde.md). Le script le dit explicitement. Seule une machine d'exercice qui définit BACKUP_AGE_IDENTITY (chemin de la clé
# privée) effectue en plus la restauration dans une base TEMPORAIRE et la comparaison avec la base en service ; le service systemd ne la définit jamais.
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
  echo "[dry-run] sha256sum -c $latest/SHA256SUMS ; fichiers .age uniquement ; en-tête age ; (avec BACKUP_AGE_IDENTITY : restauration de $latest/db.sql.gz.age dans $tmp ; comparaison avec $DB_NAME ; DROP DATABASE $tmp)"
  exit 0
fi

# 1. Intégrité (sans clé)
(cd "$latest" && sha256sum -c SHA256SUMS)
[ -s "$latest/db.sql.gz.age" ] || { echo "db.sql.gz.age absent ou vide" >&2; exit 1; }
while IFS= read -r f; do
  case "$(basename "$f")" in
    *.age|SHA256SUMS) ;;
    *) echo "fichier non chiffré dans la sauvegarde : $(basename "$f") (SEC-004)" >&2; exit 1 ;;
  esac
done < <(find "$latest" -mindepth 1 -maxdepth 1 -type f)
for f in "$latest"/*.age; do
  [ -s "$f" ] || { echo "fichier vide : $(basename "$f")" >&2; exit 1; }
  [ "$(head -c 21 "$f")" = "age-encryption.org/v1" ] || { echo "$(basename "$f") n'est pas un flux age" >&2; exit 1; }
done

# 2. Restauration : uniquement hors serveur, avec la clé privée.
if [ -z "${BACKUP_AGE_IDENTITY:-}" ]; then
  echo "sauvegarde $(basename "$latest") : intégrité vérifiée ; RESTAURATION NON VÉRIFIÉE sur ce serveur (clé privée absente, voulu) : exercice hors serveur avec ops/backup/restore-from-age.sh"
  exit 0
fi
[ -r "$BACKUP_AGE_IDENTITY" ] || { echo "BACKUP_AGE_IDENTITY illisible" >&2; exit 64; }
cleanup() { mysql "${defaults[@]}" -e "DROP DATABASE IF EXISTS \`$tmp\`" >/dev/null 2>&1 || true; }
trap cleanup EXIT
mysql "${defaults[@]}" -e "CREATE DATABASE \`$tmp\` CHARACTER SET utf8mb4"
age -d -i "$BACKUP_AGE_IDENTITY" "$latest/db.sql.gz.age" | gunzip -c | mysql "${defaults[@]}" "$tmp"
count() { mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }
restored="$(count "$tmp")"; live="$(count "$DB_NAME")"
if [ "$restored" != "$live" ]; then echo "liste des tables différente : sauvegarde $restored, base en service $live" >&2; exit 1; fi
for table in event_event identity_user audit_log request_permit_request; do
  r="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM \`$tmp\`.\`$table\`")"
  l="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$table\`")"
  echo "$table : sauvegarde $r, base en service $l (écart normal si des écritures ont eu lieu depuis)"
done
echo "sauvegarde $(basename "$latest") vérifiée et restaurée dans une base temporaire"
