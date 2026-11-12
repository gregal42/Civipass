# Liste de vérification avant remise en service

À cocher, dans l'ordre, après restauration ou incident. **Aucune case n'est une décision juridique** : celles-ci sont citées avec leur décideur.

**Techniques**
- [ ] Sauvegarde utilisée identifiée (date) ; fichiers du registre et d'ancrage courants **conservés et non restaurés**.
- [ ] `app:retention:restore-check` : preuve **saine** (registre, chaîne, ancrage, copie externe) ; aucune anomalie `PROOF_PROBLEM`.
- [ ] Dossiers/événements réintroduits : tous rejoués (`app:retention:replay`, empreinte et référence tracées) ou événements refaits à la main ; **0** `REINTRODUCED_*`.
- [ ] `PURGED_BUT_PRESENT`, `COUNT_MISMATCH`, `FILES_MISSING` : **0** (ou traités en incident).
- [ ] Pertes possibles reconnues par écrit (`--acknowledge-loss=<réf>`) ou absentes.
- [ ] Aucun droit, aucune autorisation, aucun rôle réactivé implicitement : comparer `permit_authorization` révoquées, appartenances et rôles avec l'état attendu.
- [ ] Interrupteurs : `RETENTION_PURGE_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `PUBLIC_VERIFICATION_ENABLED=0`.
- [ ] `app:ops:release` exécuté avec succès ; timers redémarrés ; `app:ops:status` sans alerte après un cycle.
- [ ] Nouveau point d'ancrage émis et reçu hors serveur.

**Juridiques / métier (décideur)**
- [ ] Rejeu autorisé par écrit (collectivités concernées / DPO).
- [ ] Gels et demandes de droits perdus ou retrouvés traités (communes).
- [ ] Perte de données qualifiée, notifiée si nécessaire (responsable de traitement, DPO).
