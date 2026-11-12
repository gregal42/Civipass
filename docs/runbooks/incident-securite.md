# Incident de sécurité

**Cas** : chaîne du registre rompue, point d'ancrage incohérent, registre absent, accès suspect, activation inattendue d'un interrupteur.

## Étapes techniques
1. **Contenir** : `app:ops:maintenance --reason="incident sécurité"`, arrêt des timers ; vérifier que les trois interrupteurs valent `0` (`env | grep -E 'RETENTION_PURGE|RIGHTS_ERASURE|PUBLIC_VERIFICATION'` dans l'environnement du service).
2. **Préserver les preuves** : copie datée et en lecture seule du registre, du fichier d'ancrage, des journaux ; **ne rien corriger**.
3. **Qualifier** : `app:retention:restore-check --external-anchors=<copie externe>`. Une **réécriture complète** de la chaîne (empreintes recalculées) est détectée par l'écart avec les points d'ancrage reçus **hors serveur** : `ANCHOR_MISMATCH`.
4. **Ne jamais** : supprimer une ligne, relancer l'ancrage, rejouer. L'ancrage refuse d'ancrer une chaîne rompue (c'est voulu).
5. **Reprise après investigation** : décision écrite du responsable ; archiver l'ancien fichier d'ancrage (le conserver), démarrer un nouveau registre et un nouveau fichier d'ancrage **avec trace** (référence d'incident), puis remettre en service ([remise-en-service.md](remise-en-service.md)).

## Limites à connaître
Si le registre ET toutes les copies d'ancrage sont réécrits, l'altération est indétectable : d'où l'importance des copies **hors serveur** (courriel, collecteur distant). L'ancrage n'est indépendant que si elles le sont réellement.

## Décisions juridiques / métier
Violation de données (qualification, notification à la CNIL et aux personnes) : **responsable de traitement et DPO**.
