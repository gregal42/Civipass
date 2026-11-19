# Unités systemd de la copie hors serveur (hors du périmètre `ops/systemd/`)

`civipass-offsite-sync.service` et `.timer` sont **volontairement** à l'extérieur de `ops/systemd/` : les tests `OpsUnitsTest` exigent que toute unité planifiée passe par l'enveloppe `ops/bin/run-job` (maintenance, verrou, battement de cœur supervisé par `app:ops:status`), ce que cette copie ne fait pas encore, la liste des tâches étant figée dans `JobRegistry` (constat C-09, `docs/exploitation/constats-kit.md`).

Conséquences : pas de battement de cœur ni de verrou ; l'échec est signalé par `OnFailure=civipass-failure@%n.service`. Les intégrer à `run-job` suppose de modifier `src/Ops/Application/JobRegistry.php` : changement à cadrer et valider à part. Installation à la main, après recette : `cp` dans `/etc/systemd/system/`, `systemctl enable --now civipass-offsite-sync.timer`.
