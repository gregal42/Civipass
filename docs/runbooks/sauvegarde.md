# Sauvegarde et vérification

**Objectif** : disposer d'une copie cohérente (base + stockage + copies du registre) et prouver qu'elle se restaure. **Politique retenue (A1, hypothèse à valider)** : rotation de **35 jours** et rejeu obligatoire des purges après toute restauration.

## Étapes techniques
1. Planification : `civipass-backup.timer` (quotidienne) et `civipass-backup-verify.timer` (hebdomadaire), voir `ops/systemd/README.md`. Manuellement : `ops/backup/backup.sh` (`--dry-run` pour voir les commandes).
2. Contenu de chaque sauvegarde (`$BACKUP_DIR/AAAAMMJJTHHMMSSZ/`) : `db.sql.gz`, `storage.tar.gz`, `purge-register.jsonl.copy`, `anchors.jsonl.copy`, `SHA256SUMS`.
3. Vérification : `ops/backup/verify-backup.sh` contrôle les sommes, restaure dans une base **temporaire**, compare la liste des tables et le volume, supprime la base temporaire. Succès attendu : « sauvegarde … vérifiée ».
4. Supervision : `app:ops:status` signale `backup` et `backup-verify` en retard (alerte courriel).
5. **Copie hors serveur** : envoyer chaque sauvegarde et, **toutes les heures**, le registre de purge et le fichier d'ancrage vers un emplacement hors du serveur (en ajout seul pour le registre ; attribut `chattr +a` conseillé).

## Règles
- Les copies du registre et des ancrages sont des **archives** : **jamais restaurées par-dessus** les fichiers courants (le registre est postérieur à toute sauvegarde).
- Une sauvegarde contient les données purgées jusqu'à sa rotation : ne pas prolonger la durée sans décision.

## Décisions juridiques / métier
- Durée de conservation des sauvegardes (35 j proposés) et son articulation avec la durée de purge : **DPO**.
- Destination hors serveur et sous-traitants ultérieurs : **DPO et exploitation**.
