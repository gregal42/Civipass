# Runbooks d'exploitation Civipass (LOT 05.6.7)

Procédures **exploitables** par l'exploitation (VPS, nginx natif, systemd). Elles décrivent des commandes de l'application et des scripts de `ops/` ; **rien n'est installé ni activé par le dépôt**. Chaque procédure sépare :

- **Étapes techniques** : commandes, contrôles, critères de réussite (réalisables par l'exploitation) ;
- **Décisions juridiques ou métier** : qui décide, sur quel écrit. **CWS ne prend aucune décision juridique autonome** : il exécute des instructions écrites de l'autorité compétente (collectivité responsable de traitement, DPO), dont la **référence** est tracée.

| Procédure | Fichier |
|---|---|
| Sauvegarde et vérification | [sauvegarde.md](sauvegarde.md) |
| Restauration, contrôle, rejeu, remise en service | [restauration.md](restauration.md) |
| Vérification de cohérence (routine) | [coherence.md](coherence.md) |
| Clôture exceptionnelle d'un événement par CWS (LOT 08.6) | [cloture-exceptionnelle-cws.md](cloture-exceptionnelle-cws.md) |
| Information des demandeurs refusés (LOT 08.5) | [information-des-refus.md](information-des-refus.md) |
| Incident de purge | [incident-purge.md](incident-purge.md) |
| Vhost HTTPS dédié au contrôle : installation, vérification, retour arrière (LOT 10) | [vhost-controle.md](vhost-controle.md) |
| Données temporaires du dépôt public : brouillons, justificatifs, sessions (LOT 10) | [donnees-temporaires-depot-public.md](donnees-temporaires-depot-public.md) |
| Incident de notification | [incident-notification.md](incident-notification.md) |
| Incident de sécurité (registre rompu, accès suspect) | [incident-securite.md](incident-securite.md) |
| Violation de données personnelles (qualification, CNIL 72 h, information des personnes) | [violation-de-donnees.md](violation-de-donnees.md) |
| Liste de vérification avant remise en service | [remise-en-service.md](remise-en-service.md) |
| Récupération d'accès (comptes, administrateurs communaux) | [recuperation-acces.md](recuperation-acces.md) |
| Validation d'un administrateur communal désigné par un lien (SEC-016) | [validation-administrateur-communal.md](validation-administrateur-communal.md) |
| Recette du contrôle public sur un VPS HTTPS dédié (LOT 07.3.5, **non exécutée**) | [recette-verification-publique.md](recette-verification-publique.md) |

Les interrupteurs `PUBLIC_VERIFICATION_ENABLED`, `RIGHTS_ERASURE_EXECUTION_ENABLED` et `RETENTION_PURGE_ENABLED` valent **0** tant que les validations de [lot-05-6-7-cadrage.md](../lot-05-6-7-cadrage.md) §9 ne sont pas données. Les durées (sauvegardes 35 jours, seuils) sont des **hypothèses à valider**.
- [controle-hors-connexion.md](controle-hors-connexion.md) — préparer, utiliser, révoquer en urgence, perte/vol d'un téléphone de contrôle (LOT 09).
