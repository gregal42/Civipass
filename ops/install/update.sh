#!/usr/bin/env bash
# Mise à jour ET retour arrière applicatif de Civipass sur le serveur (EN ROOT). Une seule instance, nginx natif, sans Docker.
#
#   update.sh <archive.tar.gz> --sha256 <empreinte> [--apply] [--app-dir /opt/civipass] [--skip-backup]
#   update.sh --rollback [--apply] [--app-dir /opt/civipass]
#
# Sans --apply : simulation (affiche les étapes). La mise à jour n'est exécutée que sur instruction explicite : voir docs/exploitation/mise-a-jour.md.
# Principe : la nouvelle version est préparée À CÔTÉ ($APP_DIR.new-<id>, composer --no-dev, assets compilés), puis échangée par renommage ;
# l'ancienne reste dans $APP_DIR.old-<id> (deux conservées) : le retour arrière applicatif est un simple échange inverse.
# Les migrations Doctrine ne sont PAS réversibles automatiquement : le retour arrière du code après une migration exige, selon le cas, la
# RESTAURATION de la sauvegarde faite juste avant (docs/runbooks/restauration.md) — le script ne restaure jamais la base de lui-même.
set -euo pipefail

APP_DIR=/opt/civipass; APPLY=0; ARCHIVE=""; SHA=""; ROLLBACK=0; SKIP_BACKUP=0
while [ $# -gt 0 ]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --rollback) ROLLBACK=1; shift ;;
    --sha256) SHA="${2:?}"; shift 2 ;;
    --app-dir) APP_DIR="${2:?}"; shift 2 ;;
    --skip-backup) SKIP_BACKUP=1; shift ;;
    -*) echo "argument inconnu : $1" >&2; exit 64 ;;
    *) ARCHIVE="$1"; shift ;;
  esac
done
say() { echo "==> $*"; }
die() { echo "ERREUR : $*" >&2; exit 1; }
run() { if [ "$APPLY" -eq 1 ]; then "$@"; else echo "[dry-run] $*"; fi; }
console() { (cd "$APP_DIR" && runuser -u civipass -- php bin/console "$@"); }
[ "$(id -u)" -eq 0 ] || die "à exécuter en root"
case "$APP_DIR" in /opt/?*) ;; *) die "--app-dir doit être un sous-répertoire de /opt (reçu : $APP_DIR)" ;; esac
ID="$(date -u +%Y%m%dT%H%M%SZ)"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.4-fpm}"
TIMERS_FILE=/run/civipass-update.timers

remember_timers() { if [ "$APPLY" -eq 1 ]; then systemctl list-units 'civipass-*.timer' --state=active --no-legend | awk '{print $1}' > "$TIMERS_FILE"; fi; }
stop_timers() {
  run systemctl stop 'civipass-*.timer'
  if [ "$APPLY" -eq 1 ]; then
    for _ in $(seq 1 60); do
      systemctl list-units 'civipass-*.service' --state=activating,active --no-legend | grep -q . || break
      sleep 5
    done
  fi
}
start_timers() {
  # Ne redémarre que les minuteurs qui étaient ACTIFS avant l'arrêt.
  if [ "$APPLY" -eq 1 ]; then
    [ -s "$TIMERS_FILE" ] && xargs -r systemctl start < "$TIMERS_FILE"
  else
    echo "[dry-run] redémarrer les minuteurs actifs avant l'arrêt"
  fi
}

if [ "$ROLLBACK" -eq 1 ]; then
  prev="$(ls -1d "${APP_DIR}".old-* 2>/dev/null | sort | tail -n1 || true)"
  [ -n "$prev" ] || die "aucune version précédente (${APP_DIR}.old-*)"
  say "retour arrière du code vers $prev"
  echo "ATTENTION : la base n'est pas modifiée. Si la version abandonnée a appliqué des migrations, vérifier leur compatibilité ou restaurer la sauvegarde (docs/runbooks/restauration.md)."
  remember_timers; stop_timers
  run mv "$APP_DIR" "${APP_DIR}.failed-${ID}"
  run mv "${APP_DIR}.failed-${ID}/var" "$prev/var"
  run mv "$prev" "$APP_DIR"
  run runuser -u civipass -- php "$APP_DIR/bin/console" cache:clear --env=prod --no-warmup
  run runuser -u civipass -- php "$APP_DIR/bin/console" cache:warmup --env=prod
  run systemctl reload "$PHP_FPM_SERVICE"
  start_timers
  say "terminé. Vérifier : ops/install/preflight.sh --stage app --domain <domaine> ; version abandonnée conservée : ${APP_DIR}.failed-${ID}"
  exit 0
fi

[ -n "$ARCHIVE" ] && [ -f "$ARCHIVE" ] || die "archive absente. usage : update.sh <archive.tar.gz> --sha256 <empreinte>"
[ -n "$SHA" ] || die "--sha256 obligatoire (empreinte relevée sur le poste de build, par un autre canal que l'archive)"
actual="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
[ "$actual" = "$SHA" ] || die "empreinte de l'archive différente (attendue $SHA, obtenue $actual)"
[ -d "$APP_DIR" ] || die "$APP_DIR absent (première installation : docs/exploitation/installation.md §3)"

NEW="${APP_DIR}.new-${ID}"
say "1/9 préparation de la nouvelle version dans $NEW"
run mkdir "$NEW"
if [ "$APPLY" -eq 1 ]; then
  tar -xzf "$ARCHIVE" -C "$NEW" --strip-components=1
  if [ ! -f "$NEW/bin/console" ] || [ ! -f "$NEW/assets/vendor/jsqr/jsQR.js" ]; then
    rm -rf "${NEW:?}"
    die "archive invalide (bin/console ou assets/vendor/jsqr absent)"
  fi
  chown -R civipass:civipass "$NEW"
  ln -sfn /etc/civipass/civipass.env "$NEW/.env.local"
fi
say "2/9 dépendances de production et ressources (sans exécuter les scripts automatiques, qui téléchargeraient depuis un CDN)"
if [ "$APPLY" -eq 1 ]; then
  ( cd "$NEW" && COMPOSER_HOME=/var/lib/civipass/composer runuser -u civipass -- env APP_ENV=prod composer install --no-dev --no-scripts --classmap-authoritative --no-interaction --prefer-dist )
  ( cd "$NEW" && runuser -u civipass -- env APP_ENV=prod php bin/console assets:install public --no-interaction )
  ( cd "$NEW" && runuser -u civipass -- env APP_ENV=prod php bin/console asset-map:compile --no-interaction )
else
  echo "[dry-run] composer install --no-dev --no-scripts --classmap-authoritative ; assets:install ; asset-map:compile"
fi

say "3/9 arrêt des minuteurs et attente des tâches en cours"
remember_timers; stop_timers
if [ "$SKIP_BACKUP" -eq 0 ]; then
  say "4/9 sauvegarde avant migration (base, stockage, copies du registre)"
  run systemctl start civipass-backup.service
else
  say "4/9 sauvegarde ignorée (--skip-backup : à justifier par écrit)"
fi

say "5/9 échange des versions (indisponibilité de quelques secondes)"
run mv "$APP_DIR" "${APP_DIR}.old-${ID}"
run mv "${APP_DIR}.old-${ID}/var" "$NEW/var"
run mv "$NEW" "$APP_DIR"
# Le code n'est pas modifiable par l'application ; seul var/ lui appartient.
run chown -R root:civipass "$APP_DIR"
run chown -R civipass:civipass "$APP_DIR/var"
say "6/9 migrations Doctrine"
if [ "$APPLY" -eq 1 ]; then
  if ! console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
    echo "ÉCHEC DES MIGRATIONS. Les minuteurs restent ARRÊTÉS. Ne pas relancer à l'aveugle : état de la base à examiner ; retour : update.sh --rollback puis, si nécessaire, restauration de la sauvegarde." >&2
    exit 2
  fi
else
  echo "[dry-run] doctrine:migrations:migrate"
fi
say "7/9 cache de production"
if [ "$APPLY" -eq 1 ]; then
  console cache:clear --env=prod --no-warmup
  console cache:warmup --env=prod
else
  echo "[dry-run] cache:clear ; cache:warmup"
fi
say "8/9 rechargement de PHP-FPM (opcache sans revalidation) et des minuteurs"
run systemctl reload "$PHP_FPM_SERVICE"
start_timers
say "9/9 contrôles"
if [ "$APPLY" -eq 1 ]; then
  console doctrine:schema:validate --skip-mapping || echo "!! schéma non synchronisé"
  console app:ops:status || echo "!! app:ops:status signale des tâches"
  cat "$APP_DIR/RELEASE" 2>/dev/null || true
  # Rotation : conserver les deux dernières versions précédentes.
  ls -1d "${APP_DIR}".old-* 2>/dev/null | sort | head -n -2 | xargs -r rm -rf
fi
say "terminé. Suite : ops/install/preflight.sh --stage app --domain <domaine> [--control-domain <domaine>] et checklist de docs/exploitation/mise-a-jour.md"
