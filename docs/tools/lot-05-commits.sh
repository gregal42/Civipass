#!/usr/bin/env bash
# Plan de découpage en 4 commits des modifications du LOT 05.4 à 05.5.5 (docs/lot-05-git-decoupage.md).
#
# CE SCRIPT NE COMMITE JAMAIS, NE POUSSE JAMAIS ET NE RÉÉCRIT JAMAIS L'HISTORIQUE. Il n'a pas été exécuté.
#   plan            affiche, par commit, le nombre de fichiers et le message proposé (défaut ; lecture seule)
#   check           contrôles de cohérence du manifeste (lecture seule) : fichiers inconnus ou manquants, doublons, secrets
#   files N         liste les fichiers du commit N (1 à 4)
#   stage N         MET EN SCÈNE (git add) les fichiers du commit N après avoir vidé l'index (git reset -q : l'index seul, jamais l'arbre de travail) ;
#                   à lancer seulement après accord ; le commit reste à faire à la main
#   message N       affiche le message de commit proposé pour N
# Jamais d'option qui commite. Vérification de chaque commit : docs/lot-05-git-decoupage.md §5.
set -euo pipefail
cd "$(dirname "$0")/../.."
MANIFEST="docs/tools/lot-05-commits.manifest"

message() {
  case "$1" in
    1) echo "docs(lot-05): décisions DM-118/DM-119, cadrages 05.5.2 à 05.5.5, contradictions, exploitation et inventaire Git" ;;
    2) echo "feat(permit): contrôle public /v/{jeton}, limitation de débit et exploitation nginx (LOT 05.4)" ;;
    3) echo "feat(permit): révisions des droits, révocation et périodes d'une autorisation (LOT 05.5.1 et 05.5.2)" ;;
    4) echo "feat(permit): zones, plaques et remplacement du QR code d'un laissez-passer (LOT 05.5.3 à 05.5.5)" ;;
    *) echo "commit inconnu : $1" >&2; exit 2 ;;
  esac
}
files() { awk -F'\t' -v n="$1" '!/^#/ && $1==n {print $3}' "$MANIFEST"; }

case "${1:-plan}" in
  plan)
    for n in 1 2 3 4; do
      printf '%s fichier(s)  commit %s : %s\n' "$(files "$n" | wc -l | tr -d ' ')" "$n" "$(message "$n")"
    done ;;
  check)
    status=0
    # 1) tout fichier modifié ou nouveau figure une fois dans le manifeste
    { git ls-files -m; git ls-files -o --exclude-standard; } | sort -u > /tmp/lot05-tree.txt
    awk -F'\t' '!/^#/ {print $3}' "$MANIFEST" | sort > /tmp/lot05-manifest.txt
    if ! diff -u /tmp/lot05-manifest.txt /tmp/lot05-tree.txt; then echo "KO  l'arbre de travail diffère du manifeste (régénérer le manifeste)"; status=1; else echo "OK  manifeste = arbre de travail"; fi
    # 2) aucun doublon
    if [ -n "$(sort /tmp/lot05-manifest.txt | uniq -d)" ]; then echo "KO  fichier présent deux fois"; status=1; else echo "OK  aucun doublon"; fi
    # 3) rien de sensible
    if grep -Eqi '(\.env\.local|\.sql$|\.key$|\.pem$|secret.*\.json$|\.log$)' /tmp/lot05-manifest.txt; then echo "KO  fichier sensible dans le manifeste"; status=1; else echo "OK  aucun fichier sensible"; fi
    # 4) l'interrupteur public reste désactivé
    if grep -q '^PUBLIC_VERIFICATION_ENABLED=0' .env; then echo "OK  PUBLIC_VERIFICATION_ENABLED=0"; else echo "KO  PUBLIC_VERIFICATION_ENABLED n'est pas à 0"; status=1; fi
    # 5) les deux migrations dans le même commit
    if [ "$(grep -c 'src/Migrations/Version2026111015' "$MANIFEST")" -ne 2 ]; then echo "KO  migrations attendues : 2"; status=1; else echo "OK  2 migrations"; fi
    exit "$status" ;;
  files)  files "${2:?numéro de commit}" ;;
  message) message "${2:?numéro de commit}" ;;
  stage)
    n="${2:?numéro de commit}"
    message "$n" >/dev/null
    echo "Vidage de l'index (git reset -q) puis mise en scène du commit $n ; aucun commit n'est fait."
    git reset -q
    files "$n" | while IFS= read -r f; do git add -- "$f"; done
    git status --short | head -5
    echo "…"
    echo "Message proposé : $(message "$n")"
    echo "Vérifier (bin/qa full sur l'index, voir docs/lot-05-git-decoupage.md §5), puis commiter À LA MAIN après accord." ;;
  *) echo "usage : $0 plan|check|files N|message N|stage N" >&2; exit 2 ;;
esac
