#!/usr/bin/env bash
# Restauration d'une sauvegarde CHIFFRÉE (SEC-004) — à exécuter HORS du serveur d'exploitation (poste ou serveur de reprise de la personne qui détient la
# clé privée age), jamais en y copiant la clé sur le serveur de production. SIMULATION par défaut : `--apply` exécute.
#
#   restore-from-age.sh <répertoire-de-sauvegarde> --identity CLÉ_PRIVÉE --database NOM [--storage-dir DIR] [--archive-dir DIR] [--apply] [--force]
#
#   <répertoire-de-sauvegarde>  répertoire horodaté (AAAAMMJJTHHMMSSZ) récupéré HORS serveur (copie hors serveur)
#   --identity    fichier de clé privée age (mode 600 ou plus strict) ; jamais affiché
#   --database    base de destination : doit être VIDE (aucune table) sauf --force ; créée si absente
#   --storage-dir  répertoire de destination du stockage privé : doit être absent ou vide
#   --archive-dir  répertoire où déposer les COPIES du registre de purge et des ancrages (archive, jamais à la place des fichiers courants)
# Variables : MYSQL_DEFAULTS_FILE (identifiants MySQL, mode 600).
# Étapes : somme de contrôle (SHA256SUMS) → déchiffrement en flux (rien n'est écrit en clair hors de la destination) → restauration → comptage.
# Après une restauration de production, suivre docs/runbooks/restauration.md (maintenance, restore-check, rejeu).
set -euo pipefail
umask 077

backup="${1:-}"; [ -n "$backup" ] && shift || { echo "usage: restore-from-age.sh <sauvegarde> --identity CLÉ --database NOM [--storage-dir DIR] [--archive-dir DIR] [--apply] [--force]" >&2; exit 64; }
identity=""; database=""; storage=""; archive=""; apply=0; force=0
while [ $# -gt 0 ]; do
  case "$1" in
    --identity) identity="${2:?}"; shift 2 ;;
    --database) database="${2:?}"; shift 2 ;;
    --storage-dir) storage="${2:?}"; shift 2 ;;
    --archive-dir) archive="${2:?}"; shift 2 ;;
    --apply) apply=1; shift ;;
    --force) force=1; shift ;;
    *) echo "argument inconnu : $1" >&2; exit 64 ;;
  esac
done
[ -d "$backup" ] || { echo "sauvegarde introuvable" >&2; exit 64; }
[ -n "$identity" ] && [ -f "$identity" ] || { echo "--identity : fichier de clé privée introuvable" >&2; exit 64; }
[ -n "$database" ] || { echo "--database est obligatoire" >&2; exit 64; }
[[ "$database" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "nom de base invalide" >&2; exit 64; }
mode="$(stat -c '%a' "$identity" 2>/dev/null || stat -f '%Lp' "$identity")"
[[ "$mode" =~ ^[0-7]00$ ]] || { echo "la clé privée doit être en mode 600 ou 400 (actuel : $mode)" >&2; exit 64; }
command -v age >/dev/null 2>&1 || { echo "la commande age est introuvable" >&2; exit 69; }
[ -f "$backup/db.sql.gz.age" ] || { echo "db.sql.gz.age absent de la sauvegarde" >&2; exit 64; }
defaults=()
[ -n "${MYSQL_DEFAULTS_FILE:-}" ] && defaults=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE")

echo "sauvegarde : $(basename "$backup")  base : $database  stockage : ${storage:-(non restauré)}  archive : ${archive:-(non restaurée)}"
if [ "$apply" -ne 1 ]; then
  echo "[simulation] sha256sum -c ; age -d | gunzip | mysql $database ; --apply pour exécuter"
  exit 0
fi

(cd "$backup" && sha256sum -c SHA256SUMS)
# Le déchiffrement est vérifié AVANT toute écriture : un mauvais identifiant ne laisse rien derrière lui.
if ! age -d -i "$identity" "$backup/db.sql.gz.age" 2>/dev/null | gunzip -t 2>/dev/null; then
  echo "déchiffrement impossible : mauvaise clé privée ou sauvegarde altérée" >&2
  exit 1
fi
mysql "${defaults[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$database\` CHARACTER SET utf8mb4"
tables="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database'")"
if [ "$tables" != "0" ] && [ "$force" -ne 1 ]; then
  echo "la base $database n'est pas vide ($tables tables) : refus (--force pour passer outre, en connaissance de cause)" >&2
  exit 1
fi
age -d -i "$identity" "$backup/db.sql.gz.age" | gunzip -c | mysql "${defaults[@]}" "$database"
restored="$(mysql "${defaults[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database'")"
echo "base restaurée : $restored tables"

if [ -n "$storage" ]; then
  if [ -e "$storage" ] && [ -n "$(ls -A "$storage" 2>/dev/null)" ]; then echo "--storage-dir n'est pas vide : stockage non restauré" >&2; exit 1; fi
  [ -f "$backup/storage.tar.gz.age" ] || { echo "storage.tar.gz.age absent de la sauvegarde" >&2; exit 1; }
  mkdir -p "$storage"
  age -d -i "$identity" "$backup/storage.tar.gz.age" | tar -xz -C "$storage"
  echo "stockage restauré : $(find "$storage" -type f | wc -l | tr -d ' ') fichiers"
fi
if [ -n "$archive" ]; then
  mkdir -p "$archive"
  for f in purge-register.jsonl.copy anchors.jsonl.copy; do
    [ -f "$backup/$f.age" ] && age -d -i "$identity" -o "$archive/$f" "$backup/$f.age"
  done
  echo "copies d'archive déposées dans $archive (jamais à la place des fichiers courants)"
fi
echo "restauration terminée"
