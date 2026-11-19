#!/usr/bin/env bash
# Copie HORS SERVEUR des sauvegardes et des preuves de purge (kit V1.0 ; comble l'étape 5 de docs/runbooks/sauvegarde.md).
#
#   offsite-sync.sh [backups|register|all] [--dry-run]
#
# Variables (fichier d'environnement du service) :
#   OFFSITE_TARGET      obligatoire, destination rsync/SSH, ex. sauvegarde@hote.exemple:/srv/civipass (répertoire existant)
#   OFFSITE_SSH_KEY     clé privée dédiée (mode 600, propriétaire civipass) ; OFFSITE_KNOWN_HOSTS : fichier known_hosts (hôte pré-enregistré)
#   BACKUP_DIR, REGISTER_PATH, ANCHOR_PATH : comme backup.sh ; BACKUP_AGE_RECIPIENTS : clés publiques age (obligatoire pour `register`, voir backup.sh)
# RÈGLE (SEC-004) : AUCUNE donnée en clair ne quitte le serveur. Un répertoire de sauvegarde n'est copié que s'il ne contient que des fichiers `.age` et
# SHA256SUMS ; le registre et les ancrages sont chiffrés (age) avant la copie.
# - backups  : ajoute les répertoires de sauvegarde horodatés absents de la destination (jamais de --delete : la rotation distante est la
#              décision de l'exploitant, durée alignée sur BACKUP_RETENTION_DAYS) ;
# - register : dépose une copie HORODATÉE (jamais écrasée) du registre de purge et du fichier d'ancrage. Un compte distant en ajout seul
#              (chattr +a sur le répertoire, ou rsync restreint) rend la preuve inaltérable par un attaquant du serveur.
# Aucun secret affiché. Échec = code non nul (l'unité systemd alerte). Le fichier ACTIF civipass-error.log de nginx n'est JAMAIS copié.
set -euo pipefail
umask 077
mode="${1:-all}"; dry=0
[ "${2:-}" = "--dry-run" ] && dry=1
: "${OFFSITE_TARGET:?OFFSITE_TARGET est obligatoire}"
: "${OFFSITE_SSH_KEY:?OFFSITE_SSH_KEY est obligatoire}"
: "${OFFSITE_KNOWN_HOSTS:?OFFSITE_KNOWN_HOSTS est obligatoire (StrictHostKeyChecking activé)}"
: "${BACKUP_DIR:?BACKUP_DIR est obligatoire}"
[ -f "$OFFSITE_SSH_KEY" ] || { echo "clé SSH introuvable" >&2; exit 64; }
ssh_cmd="ssh -i $OFFSITE_SSH_KEY -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$OFFSITE_KNOWN_HOSTS"
rs() { if [ "$dry" -eq 1 ]; then rsync -a --dry-run --ignore-existing -e "$ssh_cmd" "$@"; else rsync -a --ignore-existing -e "$ssh_cmd" "$@"; fi; }
ts="$(date -u +%Y%m%dT%H%M%SZ)"
case "$mode" in backups|register|all) ;; *) echo "mode inconnu : $mode" >&2; exit 64 ;; esac

if [ "$mode" = backups ] || [ "$mode" = all ]; then
  n=0
  while IFS= read -r d; do
    while IFS= read -r f; do
      case "$(basename "$f")" in *.age|SHA256SUMS) ;; *) echo "répertoire non chiffré, copie refusée : $(basename "$d")/$(basename "$f") (SEC-004)" >&2; exit 1 ;; esac
    done < <(find "$d" -mindepth 1 -type f)
    rs "$d" "${OFFSITE_TARGET%/}/backups/"
    n=$((n + 1))
  done < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}T[0-9]{6}Z' | sort)
  echo "sauvegardes examinées : $n"
fi
if [ "$mode" = register ] || [ "$mode" = all ]; then
  age_args=()
  for key in $(printf '%s' "${BACKUP_AGE_RECIPIENTS:-}" | tr ',' ' '); do
    [[ "$key" =~ ^age1[0-9a-z]{58}$ ]] || { echo "BACKUP_AGE_RECIPIENTS : clé publique age invalide" >&2; exit 64; }
    age_args+=(-r "$key")
  done
  [ "${#age_args[@]}" -gt 0 ] || { echo "BACKUP_AGE_RECIPIENTS est obligatoire : les preuves sont chiffrées avant la copie hors serveur (SEC-004)" >&2; exit 64; }
  command -v age >/dev/null 2>&1 || { echo "la commande age est introuvable" >&2; exit 69; }
  tmp="$(mktemp -d)"
  trap 'rm -rf "${tmp:?}"' EXIT
  [ -n "${REGISTER_PATH:-}" ] && [ -f "$REGISTER_PATH" ] && age "${age_args[@]}" -o "$tmp/purge-register-$ts.jsonl.age" "$REGISTER_PATH"
  [ -n "${ANCHOR_PATH:-}" ] && [ -f "$ANCHOR_PATH" ] && age "${age_args[@]}" -o "$tmp/anchors-$ts.jsonl.age" "$ANCHOR_PATH"
  if compgen -G "$tmp/*" >/dev/null; then
    rs "$tmp"/ "${OFFSITE_TARGET%/}/proofs/"
    echo "preuves copiées ($ts)"
  else
    echo "aucun registre ni ancrage à copier"
  fi
fi
echo "copie hors serveur terminée"
