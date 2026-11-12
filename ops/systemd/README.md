# Unités systemd Civipass (LOT 05.6.7)

**Livrées, jamais installées par le dépôt.** Aucune de ces unités n'est active : l'installation sur le VPS est un acte d'exploitation **explicite et manuel**, après les validations de [`docs/lot-05-6-7-cadrage.md`](../../docs/lot-05-6-7-cadrage.md) §9.

| Dossier | Contenu | Activation |
|---|---|---|
| `ops/systemd/` | tâches **non destructives** de la mise en service : sauvegarde, vérification de sauvegarde, point d'ancrage, cohérence du registre, intégrité, demandes de droits en retard, **simulation** de purge (aucune écriture), distributeur de notifications, supervision | à installer à la mise en service |
| `ops/systemd/optional/` | avis de conservation et alerte de dormance (envoient des courriels), **purge réelle** (destructive) | **NON ACTIVÉS** : validations DPO et recette ; la purge exige en plus `RETENTION_PURGE_ENABLED=1` |
| *(nulle part)* | exécution d'un effacement anticipé, **rejeu** après restauration, suppression d'événement | **jamais planifiés** : actes humains (`app:retention:replay`, écrans) |

## Principes

- Chaque tâche passe par `ops/bin/run-job` : refus pendant la **maintenance** (`var/ops/MAINTENANCE`, code 75), **verrou** par tâche (jamais deux exécutions simultanées), **délai maximal**, journal `journald`, **battement de cœur** écrit seulement en cas de succès. `SuccessExitStatus=75` : une exécution refusée n'est pas un échec.
- Échec → `OnFailure=civipass-failure@%n.service` : courriel à `OPS_ALERT_EMAIL` (nom d'unité, hôte, date ; aucune donnée personnelle).
- Supervision : `civipass-ops-status` (toutes les 15 min) échoue si un battement est trop ancien, si une tâche n'a jamais tourné, ou si la maintenance dure plus de 24 h.

## Installation manuelle (exemple, à adapter)

Les unités supposent l'application dans `/opt/civipass`, un compte `civipass` et `/etc/civipass/civipass.env` (variables d'environnement : base, `OPS_ALERT_EMAIL`, `RETENTION_ANCHOR_PATH`, `BACKUP_DIR`, `DB_NAME`, `MYSQL_DEFAULTS_FILE`…, mode 640). **Les interrupteurs `RETENTION_PURGE_ENABLED`, `RIGHTS_ERASURE_EXECUTION_ENABLED` et `PUBLIC_VERIFICATION_ENABLED` restent à `0` dans ce fichier.**

```
sudo cp ops/systemd/*.service ops/systemd/*.timer /etc/systemd/system/     # PAS optional/
sudo systemd-analyze verify /etc/systemd/system/civipass-*.service
sudo systemctl daemon-reload
sudo systemctl enable --now civipass-backup.timer civipass-anchor.timer civipass-ops-status.timer …
```

Pendant une restauration : `php bin/console app:ops:maintenance --reason=…` puis `systemctl stop 'civipass-*.timer'`, et **ne lever la maintenance que par `app:ops:release`** (voir `docs/runbooks/restauration.md`).

## Ancrage indépendant

`civipass-anchor` inscrit, chaque heure, l'empreinte de tête de la chaîne du registre dans `RETENTION_ANCHOR_PATH`, la journalise et l'envoie à `OPS_ALERT_EMAIL`. Il n'est **indépendant** que si ce chemin est hors de portée de qui peut écrire le registre (autre volume ou autre compte, collecteur de journaux distant, boîte courriel conservée hors serveur) : **c'est à l'exploitation de le garantir et de le confirmer** ; tant que ce n'est pas fait, le registre est « chaîné » et non « inviolable ».
