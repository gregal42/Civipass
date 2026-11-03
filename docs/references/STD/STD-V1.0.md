# CIVIPASS — Spécifications techniques V1.0

**Statut :** socle de référence pour revue, avant implémentation. **Date :** 01/11/2026. **Références :** SFD V1.0 détaillées, référentiel UX V1, décisions ARCH-001 à ARCH-012, MCD-001 à MCD-003.

## 1. Objet, périmètre et principes
Civipass est un SaaS municipal de gestion des demandes de laissez-passer lors d'événements exceptionnels. Un événement peut concerner plusieurs communes. Une demande citoyenne est unique par événement et contient les communes explicitement sollicitées ; chaque commune instruit et décide indépendamment. Un laissez-passer nominatif possède un QR stable, enrichi de droits communaux indépendants. L'interface municipale et l'application de contrôle sont deux PWA distinctes ; le formulaire citoyen est public et sans compte.

**Hors périmètre V1 :** moteur de paiement intégré, référentiel citoyen permanent, géolocalisation ou historique des scans, moteur de permissions personnalisable, application mobile native. Les choix RGPD/archivage définitifs nécessitent une validation juridique avant production.

## 2. Architecture générale
- Monolithe modulaire Symfony ; présentation Twig + Stimulus + TypeScript + Turbo ; Sonata Admin réservé à l'administration CWS.
- MySQL 8 partagé entre communes ; Doctrine ORM et migrations versionnées. Isolation logique systématique par participation/commune et non par simple filtre UI.
- API REST JSON ciblée, versionnée `/api/v1`, DTO explicites, validation Symfony, documentation OpenAPI/Nelmio. Ne pas exposer directement les entités Doctrine.
- Symfony Security + Voters + services métier ; Symfony Workflow pour cycles de vie ; Messenger avec transport Doctrine/MySQL et worker supervisé.
- Stockage local privé en V1 via `FileStorageInterface` ; migration future vers S3 sans changement métier.
- Développement Docker avec Dockerfile/Compose/Makefile fournis par le porteur du projet ; aucune version Symfony/PHP/Sonata n'est figée avant vérification de compatibilité.

## 3. Modules applicatifs
`IdentityAccess` (comptes, memberships, invitations), `Municipality` (communes, abonnements), `EventManagement` (événements, participations, zones), `ApplicationManagement` (dossiers, véhicules, pièces), `Instruction` (instruction, décision, verrou), `Permit` (droits, révisions, QR, PDF), `Control` (validation, PWA contrôleurs), `Notification`, `Audit`, `SharedInfrastructure` (stockage, horloge, transactions).

Organisation cible indicative : `src/Module/<Module>/{Domain,Application,Infrastructure,UI}`. La dépendance va des contrôleurs vers les cas d'usage ; le domaine n'importe pas Sonata ni les contrôleurs. L'organisation exacte sera vérifiée lors de l'initialisation.

## 4. Règles de données et intégrité
Le document compagnon `modele-donnees.md` est normatif pour les entités, attributs, cardinalités et index. Règles non négociables :
1. `Application` appartient à un `Event` ; une `ApplicationMunicipality` cible une `EventParticipation` du même événement.
2. Une instruction est propre à une participation communale ; notes et décisions ne sont pas partagées entre communes.
3. `Permit` est unique par dossier ; les autorisations sont communales et leurs révisions immuables.
4. Toute modification du dossier ne modifie jamais implicitement une autorisation existante ; les immatriculations autorisées sont figées dans la révision.
5. Une autorisation effective exige un événement actif, une participation active, une zone applicable, une révision accordée non révoquée, une période et un créneau horaire valides.
6. Les données sensibles et les pièces ne sont accessibles qu'aux acteurs autorisés, après vérification côté serveur.

## 5. Authentification, autorisation et sécurité
- Mots de passe hachés par PasswordHasher Symfony ; session sécurisée, cookies HttpOnly/Secure/SameSite, rotation de session après authentification ; MFA pour comptes privilégiés à arbitrer avant production.
- Membership utilisateur–commune avec rôles cumulables (`municipalityAdmin`, `eventManager`, `instructor`) ; les permissions sont évaluées pour l'objet ET la commune cible. Les accès CWS sont distincts et audités.
- Contrôle d'accès dans les requêtes de lecture, services de commande et API ; jamais uniquement dans le frontend. Retour 403/404 selon politique anti-divulgation ; tests systématiques inter-tenants.
- CSRF pour formulaires/session ; validation et normalisation entrées ; requêtes paramétrées ; échappement Twig ; CSP et en-têtes de sécurité ; protection contre IDOR, XSS, injections et élévation de privilèges.
- Upload : maximum 5 fichiers par demande, 10 Mo chacun, types autorisés PDF/JPG/JPEG/PNG/DOC (contrôle du contenu réel, analyse antimalware à prévoir, neutralisation des noms, refus des exécutables). Stockage hors `public/`, téléchargement via contrôleur autorisé, pas d'URL permanente publique.
- QR : URL publique avec jeton opaque aléatoire à forte entropie, sans PII embarquée ; stockage d'une empreinte du jeton plutôt que du secret en clair si compatible avec la recherche ; limitation de débit, réponse minimale, pas de journal des scans. Une copie du QR n'est pas une preuve d'identité.
- Liens d'invitation, de récupération et de consultation : secrets aléatoires, expiration, invalidation et anti-rejeu adaptés à leur usage.
- Secrets uniquement via variables d'environnement/gestionnaire de secrets ; aucune donnée réelle ni secret dans Git, logs ou fixtures.
- Modéliser les menaces et tester les risques OWASP Top 10 et OWASP ASVS pertinents ; revue de sécurité avant mise en production.

## 6. Workflows métier
Symfony Workflow pour `Event` (draft, active, suspended, closed), `EventParticipation` (invited, active, suspended, closed), `ApplicationInstruction` (pending, inReview, additionalInfoRequested, approved, refused, revoked selon décisions à préciser) et cycle de dossier si nécessaire. Les transitions sont déclenchées par des services applicatifs, validées par des guards métier et auditées. La clôture globale respecte la clôture des participations ; suspension globale ou locale neutralise les droits correspondants. Toute suspension globale exige motif et confirmation. Une révocation ordinaire est irréversible ; nouvel octroi = nouvelle autorisation.

## 7. API et contrôleurs
- Routes publiques : dépôt de demande et vérification QR à exposition minimale ; routes municipales authentifiées et contextualisées ; routes contrôleur avec authentification et activation d'appareil.
- Réponses JSON cohérentes : `data`, `errors`, `meta` selon contrat à figer ; codes HTTP corrects, erreurs non révélatrices ; pagination, tri, filtres autorisés explicitement.
- Commandes sensibles : contrôle d'autorisation + transaction + audit ; idempotency key pour dépôt de demande et opérations sujettes aux doubles clics.
- Concurrence : verrou métier de dossier par commune (15 min d'inactivité, reprise administrateur auditée) ; gestion explicite des conflits de version/transactions.

## 8. Documents, PDF, notifications
- Stockage privé local derrière `FileStorageInterface` : `put`, `readStream`, `delete`, `exists` ; clé technique opaque et métadonnées en base. Sauvegarde cohérente base + fichiers ; migration S3 documentée.
- PDF A4 noir et blanc, nom, zones, périodes, plaques, QR ; réimpression groupée limitée à 50 ; validité du papier limitée à 48 h après émission/réimpression et jamais au-delà des droits. Le contrôle en ligne reste autoritatif.
- Notifications e-mail et SMS via interfaces d'adaptateurs ; Brevo envisagé pour email ; SMS désactivable en environnement de test. Envois asynchrones, idempotence, tentatives, journal de statut, sans exposer de PII dans les logs.

## 9. Deux PWA et mode hors ligne
- PWA municipale : desktop/tablette/mobile, connectée pour les opérations métier ; pas d'écriture métier hors ligne.
- PWA contrôleur : activation par QR temporaire (30 min), au maximum 10 appareils par événement, cache local IndexedDB/Service Worker limité au périmètre autorisé ; chiffrement et effacement des données sensibles à concevoir avec modèle de menace.
- Synchronisation toutes les 15 min et dès retour du réseau ; affichage de l'ancienneté ; avertissement entre 12 et 24 h ; blocage après 24 h. **Jamais de statut vert hors ligne**, même avec données en cache. Révocation d'appareil à distance effective lors de la prochaine reconnexion ; fermeture d'événement désactive les appareils.
- Aucun journal de scans ni géolocalisation ; minimisation des données de contrôle.

## 10. Conventions de code — obligatoires
- camelCase pour variables, propriétés, méthodes, fonctions et identifiants TypeScript ; PascalCase pour classes/interfaces PHP et types ; UPPER_SNAKE_CASE pour constantes ; snake_case pour tables/colonnes SQL ; kebab-case pour URLs. Ne pas sacrifier PSR-12 ni les conventions Symfony/Doctrine au camelCase universel.
- PSR-12 et PSR-4, typage strict PHP, signatures typées, injection de dépendances, séparation des responsabilités, DTO et validateurs, erreurs métier explicites, aucune logique métier complexe en contrôleur ou template.
- Outils : PHP-CS-Fixer, PHPStan, PHPUnit/Symfony PHPUnit Bridge, ESLint, Prettier, Composer audit ; versions compatibles et règles CI à figer au bootstrap.
- Standards : accessibilité WCAG 2.2 AA comme cible, responsive, HTML sémantique, sécurité OWASP, principes RGPD de minimisation et de protection dès la conception.

## 11. Tests, CLI et Definition of Done
Chaque feature inclut tests unitaires des règles métier, tests d'intégration des repositories/transitions et tests fonctionnels API/HTTP selon risque ; tests de sécurité intercommunes obligatoires pour toute fonctionnalité multi-tenant. Les bugs corrigés nécessitent un test de non-régression. Fixtures déterministes et base de test isolée.

Commandes **cibles** du Makefile (à implémenter lors du bootstrap) : `make test`, `make test-unit`, `make test-integration`, `make test-functional`, `make test-security`, `make quality`, `make lint`, `make analyse`, `make audit`. Toutes doivent retourner un code de sortie fiable pour la CI.

Une fonctionnalité n'est terminée que si : critères d'acceptation respectés ; autorisations et cas d'erreur testés ; tests CLI verts ; analyse statique/formatage verts ; migrations revues ; documentation mise à jour ; audit et RGPD évalués ; revue humaine effectuée. Chaque livraison d'agent doit lister fichiers modifiés, commandes exécutées, résultats, limites et risques résiduels.

## 12. Environnements, CI/CD et exploitation
- Docker local piloté par Makefile existant ; `dev`, `test`, `prod` isolés. MySQL et stockage persistant sauvegardés. PHP-FPM + serveur HTTP, worker Messenger supervisé, cron/scheduler pour purges et synchronisations.
- CI GitHub : Composer validate, lint, PHPStan, tests, audit dépendances, build assets ; migrations exécutées de manière contrôlée au déploiement ; rollback applicatif et sauvegardes testées.
- Observabilité : erreurs applicatives, files Messenger, disponibilité, latence, espace disque, échecs notifications, sauvegardes ; pas de journal des scans.
- Conservation : accès opérationnel en lecture seule 30 jours après clôture par défaut ; politique d'archivage/purge des documents publics à confirmer avec conseil juridique et obligations des collectivités. Purge transactionnelle/logique robuste et journal d'audit à conservation distincte, proportionnée.

## 13. Décisions validées et sujets ouverts
**Validées :** ARCH-001 à ARCH-012 ; MCD-001 sélection explicite des communes ; MCD-002 modifications contrôlées ; MCD-003 versionnement des autorisations ; Docker/Makefile fourni ; normes et tests obligatoires.

**À confirmer avant production :** versions exactes PHP/Symfony/Sonata et bundle frontend ; politique de rétention juridique et base légale ; chiffrement et menace du cache offline ; modalités MFA et politiques de session ; anti-malware upload ; SLA/volumétrie ; géométrie et index spatiaux ; politique de consultation publique des données du QR ; état exact des workflows ; verrouillage et modification d'identité partagée ; structure des notifications ; stratégie de migration S3.

## 14. Ordre d'initialisation et lots
1. Revue Docker/Makefile, choix versions et compatibilité, dépôt Git et structure de docs.
2. Symfony minimal, MySQL/Doctrine, migrations, Twig/Stimulus/Turbo/TypeScript, Sonata, Security, Workflow, Messenger.
3. Toolchain qualité et commandes Makefile, CI, tests de santé et isolation de base.
4. Modèle de données par modules, contraintes et migrations relues.
5. Lots fonctionnels livrés par Claude Code : communes/accès → événements/zones → demandes → instruction → droits/QR/PDF → contrôle PWA → notifications/ops ; chaque lot avec critères d'acceptation et revue.

**Règle de gouvernance :** aucun agent de code ne modifie une décision structurante ou un contrat de sécurité sans proposition de changement explicite et validation humaine.
