#!/usr/bin/env bash
# Construit l'archive de version de Civipass (sur le poste de build : macOS ou Linux ; AUCUN accès au serveur).
#
#   build-release.sh <référence-git> [--out DIR]       ex. : build-release.sh v1.0.0   ou   build-release.sh develop
#
# Contenu : `git archive` de la référence (jamais le répertoire de travail), sans tests/ ni docker/, PLUS assets/vendor/ du poste de build :
# ce dossier est ignoré par Git (.gitignore) mais indispensable (jsQR du contrôle hors connexion, Stimulus, Turbo). Le script vérifie
# l'empreinte de jsQR.js (consignée dans assets/vendor/jsqr/VENDOR.md) et refuse de construire sinon.
# Sortie : civipass-<référence>-<hash>.tar.gz + .sha256 + .manifest. Ne modifie ni le dépôt ni aucune branche. Ne pousse rien.
set -euo pipefail
REF="${1:?usage: build-release.sh <référence-git> [--out DIR]}"; shift
OUT="$PWD"
while [ $# -gt 0 ]; do case "$1" in --out) OUT="${2:?}"; shift 2 ;; *) echo "argument inconnu : $1" >&2; exit 64 ;; esac; done
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
HASH="$(git rev-parse --verify --short=12 "${REF}^{commit}")"
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'; else shasum -a 256 "$1" | awk '{print $1}'; fi; }

JS=assets/vendor/jsqr/jsQR.js
[ -f "$JS" ] || { echo "ERREUR : $JS absent du poste de build (dossier ignoré par Git : à restaurer avant de construire)" >&2; exit 1; }
EXPECTED="$(sed -n 's/.*SHA-256 de `jsQR.js` : `\([0-9a-f]\{64\}\)`.*/\1/p' assets/vendor/jsqr/VENDOR.md)"
[ -n "$EXPECTED" ] || { echo "ERREUR : empreinte attendue introuvable dans VENDOR.md" >&2; exit 1; }
[ "$(sha256 "$JS")" = "$EXPECTED" ] || { echo "ERREUR : empreinte de jsQR.js différente de VENDOR.md" >&2; exit 1; }
for p in assets/vendor/@hotwired/stimulus assets/vendor/@hotwired/turbo; do [ -e "$p" ] || { echo "ERREUR : $p absent (php bin/console importmap:install sur le poste de build)" >&2; exit 1; }; done

NAME="civipass-${REF//\//-}-${HASH}"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE:?}"' EXIT
mkdir -p "${STAGE:?}/${NAME:?}" "$OUT"
git archive --format=tar "$REF" | tar -x -C "${STAGE:?}/${NAME:?}"
rm -rf "${STAGE:?}/${NAME:?}/tests" "${STAGE:?}/${NAME:?}/docker"
mkdir -p "${STAGE:?}/${NAME:?}/assets"
cp -R assets/vendor "${STAGE:?}/${NAME:?}/assets/vendor"
{ echo "reference=$REF"; echo "commit=$(git rev-parse "${REF}^{commit}")"; echo "built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"; echo "jsQR_sha256=$EXPECTED"; } > "${STAGE:?}/${NAME:?}/RELEASE"
tar -C "$STAGE" -czf "$OUT/$NAME.tar.gz" "$NAME"
sha256 "$OUT/$NAME.tar.gz" > "$OUT/$NAME.tar.gz.sha256"
cp "${STAGE:?}/${NAME:?}/RELEASE" "$OUT/$NAME.manifest"
echo "archive : $OUT/$NAME.tar.gz"
echo "sha256  : $(cat "$OUT/$NAME.tar.gz.sha256")"
