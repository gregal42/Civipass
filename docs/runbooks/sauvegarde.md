# Sauvegarde et vérification

**Objectif** : disposer d'une copie cohérente (base + stockage + copies du registre) et prouver qu'elle se restaure. **Politique retenue (A1, hypothèse à valider)** : rotation de **35 jours** et rejeu obligatoire des purges après toute restauration.

## Étapes techniques
1. Planification : `civipass-backup.timer` (quotidienne) et `civipass-backup-verify.timer` (hebdomadaire), voir `ops/systemd/README.md`. Manuellement : `ops/backup/backup.sh` (`--dry-run` pour voir les commandes).
2. Contenu de chaque sauvegarde (`$BACKUP_DIR/AAAAMMJJTHHMMSSZ/`), **intégralement chiffré avec age** (SEC-004) : `db.sql.gz.age`, `storage.tar.gz.age`, `purge-register.jsonl.copy.age`, `anchors.jsonl.copy.age`, `SHA256SUMS` (sommes des fichiers chiffrés). **Aucune donnée n'est jamais écrite en clair** ; `backup.sh` refuse de s'exécuter sans clé publique valide (`BACKUP_AGE_RECIPIENTS`) ou si une clé privée est présente sur le serveur (`BACKUP_AGE_IDENTITY`).
3. Vérification hebdomadaire sur le serveur : `ops/backup/verify-backup.sh` contrôle les **sommes, l'absence de fichier en clair et l'en-tête age** de chaque fichier. Elle **ne prouve pas la restauration** (la clé privée n'est pas sur le serveur) et le dit. La **preuve de restauration** est l'**exercice hors serveur** ci-dessous. Succès attendu : « sauvegarde … vérifiée ».
4. Supervision : `app:ops:status` signale `backup` et `backup-verify` en retard (alerte courriel).
5. **Copie hors serveur** (`offsite-sync.sh`, supervisée par `run-job` : verrou, battement `offsite-sync`, alerte) : **seules des données chiffrées quittent le serveur** (un répertoire contenant un fichier en clair est refusé ; le registre et les ancrages sont chiffrés avant l'envoi). Envoyer chaque sauvegarde et, **toutes les heures**, le registre de purge et le fichier d'ancrage vers un emplacement hors du serveur (en ajout seul pour le registre ; attribut `chattr +a` conseillé).

## Clés de chiffrement (SEC-004)

- **Génération, hors serveur** (poste de la personne responsable, jamais sur le serveur d'exploitation) : `age-keygen -o civipass-sauvegarde.key` ; la ligne « Public key: age1… » se copie dans `BACKUP_AGE_RECIPIENTS` de `/etc/civipass/civipass.env` (le serveur ne détient **que** la clé publique).
- **Deux destinataires recommandés** (`BACKUP_AGE_RECIPIENTS="age1… age1…"`) : la clé d'exploitation et une **clé de secours détenue par une autre personne** ; chacune déchiffre seule. Chaque clé privée est conservée dans un gestionnaire de secrets ou un coffre **distinct de celui de `NOTIFICATION_KEYRING`** et de l'accès au serveur (un attaquant du serveur n'obtient ni les sauvegardes, ni leur clé).
- **Rotation** : ajouter la nouvelle clé publique, retirer l'ancienne de `BACKUP_AGE_RECIPIENTS` ; les sauvegardes déjà faites restent lisibles avec l'ancienne clé privée, **à conserver jusqu'à la fin de la rétention** (35 j proposés). Perte d'une clé privée : si l'autre subsiste, ajouter un nouveau destinataire et sauvegarder aussitôt ; si les deux sont perdues, **les sauvegardes sont irrécupérables**.
- Contrôles : `check-deployment.sh` (clés publiques valides, aucune clé privée déclarée) et `preflight.sh` (aucune `AGE-SECRET-KEY-` sous `/etc/civipass` ni dans l'application).

## Exercice de restauration (preuve) — hors serveur

À faire **avant le pilote**, puis à fréquence décidée (au moins trimestrielle proposée), par la personne qui détient la clé privée :
1. Récupérer **depuis la copie hors serveur** un répertoire de sauvegarde horodaté.
2. Sur le poste ou le serveur de reprise (MySQL 8.4 vierge, `age` installé) : `ops/backup/restore-from-age.sh <répertoire> --identity <clé privée> --database <base temporaire> --storage-dir <dossier vide> --archive-dir <dossier>` (simulation), puis avec `--apply`. Le script vérifie les sommes, **refuse une mauvaise clé avant d'écrire**, refuse une base non vide (sauf `--force`) et une clé privée lisible par d'autres.
3. Comparer les effectifs (tables, `identity_user`, `event_event`, `audit_log`, `request_permit_request`) et, pour une reprise de production, suivre [restauration.md](restauration.md). **Chronométrer** (RTO) et consigner le rapport.

## Règles
- Les copies du registre et des ancrages sont des **archives** : **jamais restaurées par-dessus** les fichiers courants (le registre est postérieur à toute sauvegarde).
- Une sauvegarde contient les données purgées jusqu'à sa rotation : ne pas prolonger la durée sans décision.

## Décisions juridiques / métier
- Durée de conservation des sauvegardes (35 j proposés) et son articulation avec la durée de purge : **DPO**.
- Destination hors serveur et sous-traitants ultérieurs : **DPO et exploitation**.
