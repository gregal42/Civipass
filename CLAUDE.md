# Civipass — Instructions Claude Code

## Mission
Développer Civipass, SaaS multi-communes de gestion des laissez-passer en situation de crise. Référence : `docs/architecture.md`, `docs/data-model.md`, `docs/security.md`, `docs/development-roadmap.md`.

## État du projet
Symfony 8.1 / PHP 8.4 / Doctrine ORM / MySQL 8.4, Twig, AssetMapper, Stimulus, UX Turbo, Sonata Admin, Messenger, Workflow. Projet Docker/Makefile existant. PHPUnit, PHPStan niveau 6, PHP-CS-Fixer installés. Ne pas recréer le projet ni remplacer l'outillage.

## Règles impératives
1. Lire les documents `docs/` avant toute modification.
2. Proposer un plan succinct et les fichiers touchés avant chaque lot ; implémenter seulement le lot demandé.
3. Ne jamais créer d'entités/migrations à partir de ce document seul : les attributs et invariants doivent être précisés dans le ticket du lot et revus avant migration.
4. Respecter les frontières de domaine et l'isolation stricte par commune. Toute lecture/écriture sensible exige autorisation serveur + filtrage de périmètre.
5. Ne jamais faire confiance à un `municipalityId`, `eventId`, `zoneId` ou `permitId` fourni par le client sans vérifier les relations et permissions.
6. Les contrôleurs externes n'ont pas de compte utilisateur ; les citoyens non plus.
7. QR stable, opaque et non prédictible ; aucune donnée personnelle dans le QR lui-même.
8. Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. Modifications traçables. (Reformulation validée le 11 novembre 2026, DM-120 D1.)
9. Ne pas introduire de frontend build TypeScript sans décision explicite : AssetMapper ne transpile pas TypeScript. Le design Claude UI est une source visuelle à intégrer avec Twig/Stimulus.
10. Ne pas modifier Docker, Makefile, versions ou dépendances sans justification et accord.
11. Tests automatisés des règles métier, permissions, séparation des communes et transitions ; `make quality` doit passer.
12. À chaque livraison : liste des fichiers modifiés, migrations, commandes, résultats des tests, limites et risques.

## Architecture cible
Monolithe modulaire `src/Identity`, `src/Municipality`, `src/Event`, `src/Request` (demandes citoyennes), `src/Instruction`, `src/Permit`, `src/Control`, `src/Shared`, `src/Admin`. Couches légères `Domain`, `Application`, `Infrastructure`, `UI` selon besoin, sans dossiers vides artificiels. Éviter `src/Application/Application`.

## Priorité immédiate (historique)
LOT 00 = documentation, conventions, vérification de l'existant et plan d'exécution ; **aucune migration**. LOT 01 = identités, communes, rôles cumulables, invitations et sécurisation Sonata, après revue de schéma. *(LOT 00 et LOT 01 sont livrés. Prochain lot : TECH-02.1, audit seul tant que les arbitrages A1–A9 ne sont pas validés.)*

## Documents de référence
Point d'entrée : `docs/README.md`. Originaux dans `docs/references/` (manifeste SHA-256), décisions dans `docs/decisions/` (`DM-` métier, `DT-` technique), audits et lots dans `docs/lots/`, UX dans `docs/ux/`. Vérification : `docs/tools/verify-docs.sh`.
Hiérarchie en cas de contradiction métier : 1. décisions validées ; 2. SFD V1.0 détaillé (référence fonctionnelle principale) ; 3. CDC V1 ; 4. STD v1.1 ; 5. documents UX.

## Règles documentaires
- **Consulter `docs/decisions/` avant tout développement métier.** Une fiche « Proposée » n'est pas une décision validée.
- **Signaler toute contradiction documentaire** à l'utilisateur ; ne jamais modifier silencieusement CDC, SFD ou STD.
- **Ne pas inventer de règle métier** ; en cas de doute, poser la question ou proposer un arbitrage.
- Permissions serveur et isolation inter-communes obligatoires, testées avec des identifiants forgés. Aucun droit communal implicite pour CWS.
- **Pas de cartographie en V1** (ni géométrie, ni bibliothèque de zones).
- Ne pas committer sans accord de l'utilisateur.

## Conventions techniques
Symfony 8.1, PHP 8.4, Doctrine ORM. UUID v7 en `BINARY(16)`, dates en UTC (type `datetime_immutable` surchargé), `TransactionRunner` pour les opérations transactionnelles, audit via `AuditTrail` dans la même transaction, Sonata en lecture seule, design system importé par copie versionnée (`design-system/`).
Qualité : `make quality` (CS-Fixer, PHPStan niveau 6, PHPUnit) ; `make test-db` provisionne la base `*_test`. Ne jamais lancer de migration sur la base de développement sans accord.

## Workflow économe (tokens) — détail : `docs/dev-workflow.md`
- **Pendant l'incrément** : `bin/qa lint` (CS-Fixer + PHPStan sur les fichiers modifiés) puis `bin/qa changed` (tests liés aux fichiers modifiés), après un ensemble cohérent de modifications, pas après chaque ligne. Autres : `bin/qa failed` (relance les seuls tests échoués), `bin/qa module <Module>`, `bin/qa run <motif>`. Équivalents `make test-changed`, `make test-failed`.
- **Après un échec** : corriger, `bin/qa failed` jusqu'au vert, puis une fois `bin/qa changed`. Jamais masquer un échec, désactiver ou assouplir un test.
- **Fin d'incrément (avant de proposer la livraison)** : `bin/qa full` (= `make quality`, sortie compacte) **obligatoire**, puis `docs/tools/verify-docs.sh`, validation Doctrine, `git diff`. Un seul passage complet par livraison ; changement transverse (config, migrations, `tests/Support`) : toujours un passage complet.
- **Sorties** : succès = une ligne ; échec = échecs seuls ; journaux complets dans `var/qa/` (ne les lire qu'au besoin, avec `grep`).
- **Contexte** : ce fichier est l'index ; ne lire que les décisions `docs/decisions/`, les sections SFD/STD et les fichiers utiles à la tâche ; pas de relecture complète de la documentation par incrément ; pas de recherche répétée ; sous-agents seulement si le bénéfice est clair ; comptes rendus courts (modifications, tests, blocages, résultat).

