#!/usr/bin/env bash
# Test de charge du contrôle public `/v/{jeton}` (LOT 07.3, runbook de recette § 9). bash + curl + awk, aucune dépendance.
#
# À N'UTILISER QUE sur le serveur de RECETTE (données fictives) : jamais en production, jamais sur un domaine réel de commune.
# Garde-fous : HTTPS obligatoire ; la variable CIVIPASS_RECETTE_CONFIRM=serveur-de-recette-donnees-fictives doit être posée ; le jeton ne s'affiche jamais ;
# la durée et le nombre de requêtes sont bornés. Le script ne modifie rien : il n'envoie que des requêtes GET.
#
# Usage :
#   CIVIPASS_RECETTE_CONFIRM=serveur-de-recette-donnees-fictives \
#   ops/recette/charge-verification.sh --base https://recette.exemple.fr --token <jeton fictif> [--requests 600] [--concurrency 20] [--unknown-every 0] [--insecure-resolve]
#
#   --requests N        nombre total de requêtes (défaut 600, max 20000)
#   --concurrency C     requêtes simultanées (défaut 20, max 200)
#   --unknown-every K   une requête sur K porte un jeton inconnu bien formé (0 : aucune ; défaut 0), pour observer le plafond d'échecs
#   --help              cette aide
#
# Sortie : répartition des codes HTTP, latences (p50, p95, p99, max), débit. Interpréter avec runbooks/recette-verification-publique.md § 9.
set -u

BASE=""; TOKEN=""; REQUESTS=600; CONC=20; UNKNOWN_EVERY=0

usage() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; }
die() { echo "ERREUR : $*" >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --base) BASE="${2:-}"; shift 2 ;;
    --token) TOKEN="${2:-}"; shift 2 ;;
    --requests) REQUESTS="${2:-}"; shift 2 ;;
    --concurrency) CONC="${2:-}"; shift 2 ;;
    --unknown-every) UNKNOWN_EVERY="${2:-}"; shift 2 ;;
    --help|-h) usage; exit 0 ;;
    *) die "option inconnue : $1 (voir --help)" ;;
  esac
done

[ "${CIVIPASS_RECETTE_CONFIRM:-}" = "serveur-de-recette-donnees-fictives" ] || die "CIVIPASS_RECETTE_CONFIRM=serveur-de-recette-donnees-fictives requis : ce test ne vise que le serveur de recette (données fictives)."
[ -n "$BASE" ] || die "--base requis (https://…)."
case "$BASE" in https://*) ;; *) die "--base doit commencer par https:// (aucun test en clair)." ;; esac
case "$BASE" in *civipass.example*|*localhost*|*127.0.0.1*) die "domaine d'exemple ou local refusé : viser le serveur de recette." ;; esac
[ -n "$TOKEN" ] || die "--token requis (jeton d'un laissez-passer FICTIF)."
printf '%s' "$TOKEN" | grep -Eq '^[a-z2-7]{32}$' || die "le jeton doit être bien formé (32 caractères base32 minuscules)."
for n in REQUESTS CONC UNKNOWN_EVERY; do eval "v=\$$n"; case "$v" in ''|*[!0-9]*) die "$n doit être un entier." ;; esac; done
[ "$REQUESTS" -ge 1 ] && [ "$REQUESTS" -le 20000 ] || die "--requests entre 1 et 20000."
[ "$CONC" -ge 1 ] && [ "$CONC" -le 200 ] || die "--concurrency entre 1 et 200."
command -v curl >/dev/null || die "curl absent."

BASE="${BASE%/}"
OUT="$(mktemp)"; trap 'rm -f "$OUT"' EXIT
START="$(date +%s)"

one() {
  # $1 = numéro de la requête ; $2 = base ; $3 = jeton ; $4 = fréquence des inconnus
  local i="$1" base="$2" token="$3" every="$4" path
  path="$token"
  if [ "$every" -gt 0 ] && [ $((i % every)) -eq 0 ]; then
    # Jeton inconnu bien formé, propre à chaque requête (32 caractères base32 minuscules).
    path="$(LC_ALL=C tr -dc 'a-z2-7' </dev/urandom | head -c 32)"
  fi
  curl -s -o /dev/null --max-time 20 -w '%{http_code} %{time_total}\n' "$base/v/$path" || echo "000 20"
}
export -f one

seq 1 "$REQUESTS" | xargs -P "$CONC" -I{} bash -c 'one "$@"' _ {} "$BASE" "$TOKEN" "$UNKNOWN_EVERY" >"$OUT"
END="$(date +%s)"; DUR=$((END-START)); [ "$DUR" -ge 1 ] || DUR=1

echo "Requêtes : $REQUESTS, simultanéité : $CONC, durée : ${DUR}s, débit moyen : $((REQUESTS/DUR)) req/s (jeton non affiché)"
echo "Codes HTTP :"
awk '{c[$1]++} END{for(k in c) printf "  %s : %d\n", k, c[k]}' "$OUT" | sort
echo "Latences (s) :"
awk '{print $2}' "$OUT" | sort -n | awk '{a[NR]=$1} END{ if(NR==0){print "  aucune mesure"; exit} printf "  p50=%s p95=%s p99=%s max=%s\n", a[int(NR*0.50)<1?1:int(NR*0.50)], a[int(NR*0.95)<1?1:int(NR*0.95)], a[int(NR*0.99)<1?1:int(NR*0.99)], a[NR]}'
