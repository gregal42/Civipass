# Civipass — Instructions Claude Code

## Mission
Développer Civipass, SaaS multi-communes de gestion des laissez-passer en situation de crise. Référence : `docs/architecture.md`, `docs/data-model.md`, `docs/security.md`, `docs/development-roadmap.md`.

## État du projet
Symfony 8.1 / PHP 8.4 / Doctrine ORM / MySQL 8.4, Twig, AssetMapper, Stimulus, UX Turbo, Sonata Admin, Messenger, Workflow. Projet Docker/Makefile existant. PHPUnit, PHPStan niveau 6, PHP-CS-Fixer installés. Ne pas recréer le projet ni remplacer l'outillage.

## Règles impératives
1. Lire les documents `docs/` avant toute modification.
2. Chaque lot ou sous-lot commence par un **cadrage validé une seule fois** (voir « Protocole d'exécution des lots et sous-lots ») ; implémenter seulement le lot demandé, dans le périmètre du cadrage validé.
3. Ne jamais créer d'entités/migrations à partir de ce document seul : les attributs, invariants et le SQL doivent être précisés dans le cadrage du lot et **approuvés** ; une migration non approuvée exige un nouvel accord.
4. Respecter les frontières de domaine et l'isolation stricte par commune. Toute lecture/écriture sensible exige autorisation serveur + filtrage de périmètre.
5. Ne jamais faire confiance à un `municipalityId`, `eventId`, `zoneId` ou `permitId` fourni par le client sans vérifier les relations et permissions.
6. Les contrôleurs externes n'ont pas de compte utilisateur ; les citoyens non plus.
7. QR stable, opaque et non prédictible ; aucune donnée personnelle dans le QR lui-même.
8. Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. Modifications traçables. (Reformulation validée le 11 novembre 2026, DM-120 D1.)
9. Ne pas introduire de frontend build TypeScript sans décision explicite : AssetMapper ne transpile pas TypeScript. Le design Claude UI est une source visuelle à intégrer avec Twig/Stimulus.
10. Ne pas modifier Docker, Makefile, versions ou dépendances sans justification et accord.
11. Tests automatisés des règles métier, permissions, séparation des communes et transitions ; `make quality` doit passer.
12. À chaque livraison : rapport **synthétique** selon le protocole (E) : fonctionnalités, décisions techniques, résultats des tests, limites, migrations exécutées, commits et état Git, points à décider. Les listes détaillées de fichiers ne sont fournies que si elles servent une décision (`git` les donne).

## Architecture cible
Monolithe modulaire `src/Identity`, `src/Municipality`, `src/Event`, `src/Request` (demandes citoyennes), `src/Instruction`, `src/Permit`, `src/Control`, `src/Shared`, `src/Admin`. Couches légères `Domain`, `Application`, `Infrastructure`, `UI` selon besoin, sans dossiers vides artificiels. Éviter `src/Application/Application`.

## Priorité immédiate (historique)
*(État au 12 novembre 2026 : LOT 05.6.1 et 05.6.2 livrés et poussés ; prochain : LOT 05.6.3, cadrage soumis à validation. Le détail des lots figure dans `docs/lot-05-*`.)*
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
- Commits **locaux** autonomes dans le périmètre d'un cadrage validé (voir le protocole, D) ; **push, `main`, force push, déploiement et infrastructure : autorisation explicite**.

## Conventions techniques
Symfony 8.1, PHP 8.4, Doctrine ORM. UUID v7 en `BINARY(16)`, dates en UTC (type `datetime_immutable` surchargé), `TransactionRunner` pour les opérations transactionnelles, audit via `AuditTrail` dans la même transaction, Sonata en lecture seule, design system importé par copie versionnée (`design-system/`).
Qualité : `make quality` (CS-Fixer, PHPStan niveau 6, PHPUnit) ; `make test-db` provisionne la base `*_test`. Une migration **prévue et approuvée dans le cadrage** peut être exécutée sur les bases locales autorisées (`*_test`, développement), avec sauvegarde vérifiée et contrôles ; toute migration non approuvée, ou sur une base de production, exige un accord explicite.

## Workflow économe (tokens) — détail : `docs/dev-workflow.md`
- **Pendant l'incrément** : `bin/qa lint` (CS-Fixer + PHPStan sur les fichiers modifiés) puis `bin/qa changed` (tests liés aux fichiers modifiés), après un ensemble cohérent de modifications, pas après chaque ligne. Autres : `bin/qa failed` (relance les seuls tests échoués), `bin/qa module <Module>`, `bin/qa run <motif>`. Équivalents `make test-changed`, `make test-failed`.
- **Après un échec** : corriger, `bin/qa failed` jusqu'au vert, puis une fois `bin/qa changed`. Jamais masquer un échec, désactiver ou assouplir un test.
- **Fin d'incrément (avant de proposer la livraison)** : `bin/qa full` (= `make quality`, sortie compacte) **obligatoire**, puis `docs/tools/verify-docs.sh`, validation Doctrine, `git diff`. Un seul passage complet par livraison ; changement transverse (config, migrations, `tests/Support`) : toujours un passage complet.
- **Sorties** : succès = une ligne ; échec = échecs seuls ; journaux complets dans `var/qa/` (ne les lire qu'au besoin, avec `grep`).
- **Contexte** : ce fichier est l'index ; ne lire que les décisions `docs/decisions/`, les sections SFD/STD et les fichiers utiles à la tâche ; pas de relecture complète de la documentation par incrément ; pas de recherche répétée ; sous-agents seulement si le bénéfice est clair ; comptes rendus courts (modifications, tests, blocages, résultat).

## Protocole d'exécution des lots et sous-lots
Méthode de travail permanente (validée le 12 novembre 2026), destinée à limiter les échanges intermédiaires et la consommation de tokens **sans diminuer la qualité ni la sécurité**.

### A. Cadrage initial
Chaque lot ou sous-lot commence par un document de cadrage : objectif et périmètre fonctionnel ; règles métier et décisions existantes ; contraintes de sécurité et d'architecture ; migrations envisagées (SQL, entités, contraintes, index, données existantes, risques, retour arrière) ; critères d'acceptation ; dépendances et exclusions. Il est soumis **une seule fois** à validation. Une fois validé, il constitue un **mandat d'exécution** pour l'ensemble du lot ou sous-lot.

### B. Autonomie après validation
Dans le périmètre approuvé, sans autorisation supplémentaire : développer, créer et modifier les fichiers, refactoriser localement quand c'est justifié, ajouter/modifier/exécuter les tests, corriger bugs et régressions, mettre à jour la documentation, réaliser les vérifications de qualité, créer des **commits locaux** cohérents, choisir les détails d'implémentation. Aucune validation intermédiaire pour ces opérations.

### C. Arrêts obligatoires (arbitrage requis)
Demander un arbitrage **uniquement** si : une nouvelle décision métier est nécessaire ; une contradiction remet en cause une décision validée ; une faille de sécurité importante impose un changement de comportement ou de périmètre ; une migration non prévue ou non approuvée devient nécessaire ; une opération présente un risque significatif de perte de données ; une dépendance majeure rend le périmètre initial irréalisable ; une action sur la production ou l'infrastructure est nécessaire. Les migrations **prévues et approuvées** peuvent être exécutées sur les bases locales autorisées, avec sauvegarde vérifiée et contrôles.

### D. Git et déploiement
Commits locaux : autonomes. Push GitHub : **autorisation explicite**. Force push : interdit sans autorisation spécifique. Modification de `main` : autorisation explicite. Déploiement et modification d'infrastructure : autorisation explicite. **Vérifier systématiquement l'absence de déploiement automatique avant un push** (configuration CI/CD du dépôt ; ce qui n'est pas vérifiable côté serveur est signalé).

### E. Rapport final (synthétique)
À la fin du sous-lot, uniquement : 1. fonctionnalités livrées ; 2. décisions techniques importantes ; 3. résultats des tests ; 4. anomalies ou limites restantes ; 5. migrations exécutées ; 6. commits et état Git ; 7. points nécessitant une décision. Pas de long inventaire de fichiers ni de compte rendu des corrections internes, sauf si nécessaire à une décision ; ne pas répéter les informations déjà validées.

### F. Qualité (l'autonomie ne dispense d'aucun contrôle)
Avant de déclarer un sous-lot terminé : `bin/qa full` ; `docs/tools/verify-docs.sh` ; synchronisation Doctrine (`doctrine:schema:validate`) ; permissions et isolation multicommunale (identifiants forgés) ; invariants métier et non-régression ; risques résiduels documentés. **Ne jamais masquer un échec de test** pour déclarer un lot terminé.

### G. Préservation des règles existantes
Ce protocole n'affaiblit ni les règles de sécurité, ni les décisions métier, ni les obligations RGPD (notamment la **règle 8**). En cas de conflit entre une ancienne instruction et ce protocole, la contradiction est identifiée et sa résolution proposée à l'utilisateur.

**Contradictions relevées lors de l'adoption (résolues dans ce fichier, à confirmer par l'utilisateur)** : (1) règle 2 « plan avant chaque lot » → remplacée par le cadrage validé une fois ; (2) « Ne pas committer sans accord » → commits locaux autonomes, push soumis à autorisation ; (3) « Ne jamais lancer de migration sur la base de développement sans accord » → migrations approuvées dans le cadrage exécutables localement, avec sauvegarde ; (4) règle 12 « liste des fichiers modifiés » → rapport synthétique (E). Les décisions du commanditaire déjà validées (règle 8, DM-120, arbitrages D1 à D4) restent inchangées.
