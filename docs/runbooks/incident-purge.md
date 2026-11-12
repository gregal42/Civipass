# Incident de purge

**Cas** : purge interrompue, dossier supprimé sans trace, dossier purgé puis réapparu, comptes de clôture incohérents.

## Étapes techniques
1. **Arrêter** : `app:ops:maintenance --reason="incident purge"` ; vérifier que `RETENTION_PURGE_ENABLED=0` et `RIGHTS_ERASURE_EXECUTION_ENABLED=0`.
2. **Qualifier** : `app:retention:restore-check` et `app:retention:register-check` ; journaux de l'unité (`journalctl -u civipass-retention-purge`).
3. **Purge interrompue** : chaque dossier est traité dans **une transaction** ; l'état est soit purgé (avec sa ligne de registre), soit intact. Relancer la simulation `app:retention:purge` : elle reprend. Une demande orpheline (sans dossier) est reprise au passage suivant.
4. **Dossier supprimé sans trace (`COUNT_MISMATCH`)** : conserver les preuves (copie du registre, des journaux), ne rien « réparer » ; restaurer depuis la sauvegarde la plus proche si les données sont nécessaires, puis [restauration.md](restauration.md).
5. **Dossier purgé réapparu** (`REINTRODUCED_*`) : [restauration.md](restauration.md) étape 4 et suivantes.
6. Ne jamais supprimer ni modifier une ligne du registre.

## Décisions juridiques / métier
- Réactiver la purge réelle après incident : **DPO et collectivités**.
- Qualification de la perte éventuelle (violation de données) et notification : **responsable de traitement, DPO**.
