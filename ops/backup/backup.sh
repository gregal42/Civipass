#!/usr/bin/env bash
# Sauvegarde Civipass (LOT 05.6.7, chiffrée : SEC-004) : base MySQL, stockage privé, COPIES du registre de purge et des points d'ancrage.
#
#   backup.sh [--dry-run]
#
# CHIFFREMENT OBLIGATOIRE (age, https://age-encryption.org) : aucune donnée n'est jamais écrite en clair dans $BACKUP_DIR (les flux passent par un tube,
# du dump au fichier `.age`). Le serveur ne détient que la/les CLÉ(S) PUBLIQUE(S) ; la clé PRIVÉE reste HORS serveur (voir docs/runbooks/sauvegarde.md).
# Sans destinataire valide, le script REFUSE de s'exécuter (code 64) : pas de repli en clair.
#
# Variables (fichier d'environnement du service, jamais dans le dépôt) :
#   BACKUP_DIR (obligatoire, chemin absolu)   DB_NAME (obligatoire)   MYSQL_DEFAULTS_FILE (identifiants, mode 600)
#   BACKUP_AGE_RECIPIENTS (obligatoire) : une ou plusieurs clés publiques `age1…` séparées par des espaces ou des virgules (recommandé : deux, la clé
#                                          d'exploitation et une clé de secours détenue par une autre personne)
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

# --- Chiffrement obligatoire (SEC-004) -------------------------------------------------------------------------------------------------------
[ -z "${BACKUP_AGE_IDENTITY:-}" ] || { echo "BACKUP_AGE_IDENTITY défini : la clé PRIVÉE ne doit jamais se trouver sur le serveur de sauvegarde" >&2; exit 64; }
age_args=()
for key in $(printf '%s' "${BACKUP_AGE_RECIPIENTS:-}" | tr ',' ' '); do
  [[ "$key" =~ ^age1[0-9a-z]{58}$ ]] || { echo "BACKUP_AGE_RECIPIENTS : clé publique age invalide (attendu : age1…, 62 caractères)" >&2; exit 64; }
  age_args+=(-r "$key")
done
[ "${#age_args[@]}" -gt 0 ] || { echo "BACKUP_AGE_RECIPIENTS est obligatoire : les sauvegardes sont chiffrées, jamais écrites en clair (SEC-004)" >&2; exit 64; }
command -v age >/dev/null 2>&1 || { echo "la commande age est introuvable (paquet « age »)" >&2; exit 69; }
enc() { age "${age_args[@]}"; }

ts="$(date -u +%Y%m%dT%H%M%SZ)"
dest="$BACKUP_DIR/$ts"
run mkdir -p "$dest"
# Un échec en cours de route ne laisse JAMAIS une sauvegarde partielle (elle serait prise pour la plus récente) : seul le répertoire créé ici est supprimé.
if [ "$dry" -eq 0 ]; then
  trap 'rc=$?; if [ "$rc" -ne 0 ]; then rm -rf -- "${dest:?}"; echo "sauvegarde ${ts} abandonnée (code $rc), répertoire supprimé" >&2; fi' EXIT
fi

defaults=()
[ -n "${MYSQL_DEFAULTS_FILE:-}" ] && defaults=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE")
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] mysqldump --single-transaction --no-tablespaces $DB_NAME | gzip | age -r <clé publique> > $dest/db.sql.gz.age"
else
  mysqldump "${defaults[@]}" --single-transaction --no-tablespaces --routines "$DB_NAME" | gzip | enc > "$dest/db.sql.gz.age"
fi
if [ -n "${STORAGE_DIR:-}" ] && [ -d "${STORAGE_DIR:-}" ]; then
  if [ "$dry" -eq 1 ]; then echo "[dry-run] tar -C $STORAGE_DIR -czf - . | age -r <clé publique> > $dest/storage.tar.gz.age"; else tar -C "$STORAGE_DIR" -czf - . | enc > "$dest/storage.tar.gz.age"; fi
fi
if [ -n "${REGISTER_PATH:-}" ] && [ -f "${REGISTER_PATH:-}" ]; then
  if [ "$dry" -eq 1 ]; then echo "[dry-run] age < $REGISTER_PATH > $dest/purge-register.jsonl.copy.age"; else enc < "$REGISTER_PATH" > "$dest/purge-register.jsonl.copy.age"; fi
fi
if [ -n "${ANCHOR_PATH:-}" ] && [ -f "${ANCHOR_PATH:-}" ]; then
  if [ "$dry" -eq 1 ]; then echo "[dry-run] age < $ANCHOR_PATH > $dest/anchors.jsonl.copy.age"; else enc < "$ANCHOR_PATH" > "$dest/anchors.jsonl.copy.age"; fi
fi
# Sommes de contrôle sur les fichiers CHIFFRÉS : l'intégrité se vérifie (et se transmet) sans jamais disposer de la clé.
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] (cd $dest && sha256sum *.age > SHA256SUMS)"
else
  (cd "$dest" && sha256sum -- *.age > SHA256SUMS)
fi

# Rotation : uniquement les répertoires horodatés de CE répertoire de sauvegarde, plus anciens que la durée retenue.
if [ "$dry" -eq 1 ]; then
  echo "[dry-run] rotation > $retention jours dans $BACKUP_DIR (répertoires AAAAMMJJTHHMMSSZ)"
else
  find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' -mtime +"$retention" -exec rm -rf -- {} +
fi
echo "sauvegarde ${ts} terminée (chiffrée)"
