#!/usr/bin/env bash
# Construit l'archive de version de Civipass (sur le poste de build : macOS ou Linux ; AUCUN accès au serveur).
#
#   build-release.sh <référence-git> [--out DIR]       ex. : build-release.sh v1.0.0   ou   build-release.sh develop
#
# Contenu : `git archive` de la référence (jamais le répertoire de travail), sans tests/ ni docker/. `assets/vendor/` (jsQR du contrôle hors connexion, Stimulus,
# Turbo) est versionné depuis le correctif C-01/C-02 : le script vérifie, DANS l'archive, sa présence et l'empreinte de jsQR.js (consignée dans
# assets/vendor/jsqr/VENDOR.md), et refuse de construire sinon (référence antérieure au correctif, ou fichier altéré).
# Sortie : civipass-<référence>-<hash>.tar.gz + .sha256 + .manifest. Ne modifie ni le dépôt ni aucune branche. Ne pousse rien.
set -euo pipefail
REF="${1:?usage: build-release.sh <référence-git> [--out DIR]}"; shift
OUT="$PWD"
while [ $# -gt 0 ]; do case "$1" in --out) OUT="${2:?}"; shift 2 ;; *) echo "argument inconnu : $1" >&2; exit 64 ;; esac; done
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
HASH="$(git rev-parse --verify --short=12 "${REF}^{commit}")"
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'; else shasum -a 256 "$1" | awk '{print $1}'; fi; }

NAME="civipass-${REF//\//-}-${HASH}"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE:?}"' EXIT
mkdir -p "${STAGE:?}/${NAME:?}" "$OUT"
git archive --format=tar "$REF" | tar -x -C "${STAGE:?}/${NAME:?}"
rm -rf "${STAGE:?}/${NAME:?}/tests" "${STAGE:?}/${NAME:?}/docker"
SRC="${STAGE:?}/${NAME:?}"
JS="$SRC/assets/vendor/jsqr/jsQR.js"
[ -f "$JS" ] || { echo "ERREUR : assets/vendor/jsqr/jsQR.js absent de la référence $REF (référence antérieure au correctif C-01 ?)" >&2; exit 1; }
EXPECTED="$(sed -n 's/.*SHA-256 de `jsQR.js` : `\([0-9a-f]\{64\}\)`.*/\1/p' "$SRC/assets/vendor/jsqr/VENDOR.md")"
[ -n "$EXPECTED" ] || { echo "ERREUR : empreinte attendue introuvable dans VENDOR.md" >&2; exit 1; }
[ "$(sha256 "$JS")" = "$EXPECTED" ] || { echo "ERREUR : empreinte de jsQR.js différente de VENDOR.md" >&2; exit 1; }
for p in assets/vendor/@hotwired/stimulus assets/vendor/@hotwired/turbo; do [ -e "$SRC/$p" ] || { echo "ERREUR : $p absent de la référence $REF" >&2; exit 1; }; done
{ echo "reference=$REF"; echo "commit=$(git rev-parse "${REF}^{commit}")"; echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "jsQR_sha256=$EXPECTED"; } > "${STAGE:?}/${NAME:?}/RELEASE"
tar -C "$STAGE" -czf "$OUT/$NAME.tar.gz" "$NAME"
sha256 "$OUT/$NAME.tar.gz" > "$OUT/$NAME.tar.gz.sha256"
cp "${STAGE:?}/${NAME:?}/RELEASE" "$OUT/$NAME.manifest"
echo "archive : $OUT/$NAME.tar.gz"
echo "sha256  : $(cat "$OUT/$NAME.tar.gz.sha256")"
