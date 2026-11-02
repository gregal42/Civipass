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
8. Pas de suppression physique d'une décision, d'une autorisation ou de son historique ; modifications traçables.
9. Ne pas introduire de frontend build TypeScript sans décision explicite : AssetMapper ne transpile pas TypeScript. Le design Claude UI est une source visuelle à intégrer avec Twig/Stimulus.
10. Ne pas modifier Docker, Makefile, versions ou dépendances sans justification et accord.
11. Tests automatisés des règles métier, permissions, séparation des communes et transitions ; `make quality` doit passer.
12. À chaque livraison : liste des fichiers modifiés, migrations, commandes, résultats des tests, limites et risques.

## Architecture cible
Monolithe modulaire `src/Identity`, `src/Municipality`, `src/Event`, `src/Request` (demandes citoyennes), `src/Instruction`, `src/Permit`, `src/Control`, `src/Shared`, `src/Admin`. Couches légères `Domain`, `Application`, `Infrastructure`, `UI` selon besoin, sans dossiers vides artificiels. Éviter `src/Application/Application`.

## Priorité immédiate
LOT 00 = documentation, conventions, vérification de l'existant et plan d'exécution ; **aucune migration**. LOT 01 = identités, communes, rôles cumulables, invitations et sécurisation Sonata, après revue de schéma.
