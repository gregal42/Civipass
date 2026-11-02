# Civipass — Tests et base de données de test

## Principe

Les tests d'intégration et fonctionnels écrivent et **vident** des tables. Ils ne tournent que sur une base **dédiée**, jamais sur la base de développement.

| Garde-fou | Où |
|---|---|
| L'environnement `test` ajoute le suffixe `_test` au nom de base (`civipass` → `civipass_test`) | `config/packages/doctrine.yaml` (`when@test`, `dbname_suffix`) |
| Toute purge est refusée si le nom de la base ne contient pas `_test` | `tests/Support/TestDatabase.php` |
| Les migrations de test sont jouées avec `--env=test` uniquement | cible `make test-db` |
| Aucun secret n'est versionné : l'URL de connexion de test vit dans `.env.test.local` (ignoré par Git) | `.gitignore` (`/.env.*.local`) |

## Première installation (ou nouvelle machine / CI locale)

```
make up                # conteneurs Docker démarrés
make test-db           # crée civipass_test, accorde les droits, applique les migrations (env test)
make quality           # PHPStan + CS-Fixer + PHPUnit
```

`make test-db` est **idempotent** : on peut le relancer à chaque nouvelle migration pour mettre la base de test à jour.

Ce que fait la cible :
1. Crée `.env.test.local` à partir de la ligne `DATABASE_URL` de `.env.local` si le fichier n'existe pas (mêmes serveur et identifiants ; le suffixe `_test` est ajouté par `doctrine.yaml`).
2. Se connecte **dans le conteneur MySQL** avec le compte root (mot de passe lu dans l'environnement du conteneur : jamais affiché, jamais dans Git), crée la base `civipass_test` et accorde les droits à l'utilisateur applicatif, qui n'en a normalement que sur `civipass`.
3. Joue `doctrine:migrations:migrate --env=test`.

Elle ne touche jamais à la base `civipass`.

## Procédure manuelle équivalente

1. Dans `.env.test.local` : `DATABASE_URL="mysql://<utilisateur>:<mot de passe>@<hôte>:3306/civipass?serverVersion=8.4.13&charset=utf8mb4"` (la même valeur que dans `.env.local`).
2. Créer la base `civipass_test` et accorder `ALL PRIVILEGES` sur `civipass_test.*` à l'utilisateur applicatif (compte administrateur MySQL).
3. `php bin/console doctrine:migrations:migrate --env=test --no-interaction`.

## Diagnostic

| Symptôme | Cause probable |
|---|---|
| `Base de test indisponible ou non migrée : lancez make test-db` | Base absente, droits manquants ou migration non jouée. |
| `Connection refused` vers `127.0.0.1` | `.env.test.local` absent : le test retombe sur l'URL placeholder de `.env`. |
| `Base "…" refusée : les tests destructifs exigent une base de test` | L'URL résolue ne mène pas à une base `_test` : ne rien purger, corriger la configuration. |

## Intégration continue

Le pipeline doit disposer d'un serveur MySQL 8.4 et exécuter : création de la base de test (compte disposant du droit `CREATE`), `doctrine:migrations:migrate --env=test`, puis `make quality`. L'URL de test est fournie par la variable d'environnement `DATABASE_URL` du pipeline (secret du CI), pas par un fichier versionné.

## Emails et limiteurs en test

- `MAILER_DSN=null://null` : aucun email ne part ; les messages sont capturés par le transport de test (`assertEmailCount`, `getMailerMessage`). Le client HTTP redémarre le noyau à chaque requête : les assertions d'emails portent sur **la dernière requête**.
- Les limiteurs (connexion, mot de passe oublié) utilisent un cache **fichier** persistant (nécessaire entre les requêtes d'un même test) ; `WebTestCase::freshClient()` le vide au début de chaque test.

## Design system et prévisualisation

- `tests/Unit/DesignSystem/IntegrityTest` : empreintes des fichiers importés, contrôleurs JavaScript compatibles navigateur, CSS scopé, polices présentes, layouts sans ressource externe ni CSS par JavaScript.
- `tests/Integration/DesignSystemTwigTest` : compilation des 66 gabarits et rendu des 56 exemples sous le Twig PHP du projet.
- `tests/Functional/DesignPreviewTest` : `/dev/ui` absent en `test` et en `prod`, présent en `dev` ; les noyaux `dev` et `prod` sont démarrés dans le test (sans base de données). Le noyau `dev` déclare des proxys de confiance dans l'état **statique** de `Request` : `tearDown()` et `WebTestCase::freshClient()` le remettent à zéro pour ne pas fausser les tests de sécurité.

## Structure des tests

- `tests/Unit` : sans base (valeurs, règles pures, architecture).
- `tests/Integration` : base de test ; héritent de `DatabaseTestCase` (purge avant chaque test).
- `tests/Functional` : requêtes HTTP (`WebTestCase`) sur la base de test.
- `tests/Support` : builders internes (`UserBuilder`, `MunicipalityBuilder`, `MembershipBuilder`) et `TestDatabase`.
