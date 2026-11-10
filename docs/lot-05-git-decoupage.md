# Découpage Git en 4 commits (LOT 05.4 à 05.5.5) : plan, fichiers partagés, vérification

**Statut : PROPOSITION (10 novembre 2026). Aucun commit, aucun push, aucune réécriture de l'historique, aucun `git add`. Le script proposé n'a pas été exécuté.** Option retenue par le commanditaire : 4 commits cohérents (voir [lot-05-git-inventaire.md](lot-05-git-inventaire.md)).

Fichiers : manifeste [`docs/tools/lot-05-commits.manifest`](tools/lot-05-commits.manifest) (un fichier, un commit), script [`docs/tools/lot-05-commits.sh`](tools/lot-05-commits.sh) (`plan`, `check`, `files N`, `message N`, `stage N` ; il ne commite jamais).

## 1. Les 4 commits

| # | Message proposé | Fichiers | dont modifiés |
|---|---|---|---|
| 1 | `docs(lot-05): décisions DM-118/DM-119, cadrages 05.5.2 à 05.5.5, contradictions, exploitation et inventaire Git` | 17 | 7 |
| 2 | `feat(permit): contrôle public /v/{jeton}, limitation de débit et exploitation nginx (LOT 05.4)` | 53 | 6 |
| 3 | `feat(permit): révisions des droits, révocation et périodes d'une autorisation (LOT 05.5.1 et 05.5.2)` | 79 | 35 |
| 4 | `feat(permit): zones, plaques et remplacement du QR code d'un laissez-passer (LOT 05.5.3 à 05.5.5)` | 102 | 6 |

Messages à compléter, au moment du commit, avec les lignes d'attribution demandées par l'outil. Les deux migrations (`Version20261110150151`, `Version20261110155643`) sont dans le commit 3 ; les lots 05.5.3 à 05.5.5 n'ont aucune migration.

## 2. Méthode d'affectation et fichiers partagés

1. Chaque fichier **nouveau** est affecté à son lot par son nom (contrôle public et exploitation : 2 ; révisions, révocation, périodes et migrations : 3 ; zones, plaques, QR : 4 ; documentation : 1).
2. Les fichiers **modifiés** déjà suivis qui mêlent plusieurs lots sont affectés par une analyse statique des dépendances (références de classes `use` et espaces de noms) : un fichier ne peut figurer dans un commit **que si toutes les classes nouvelles qu'il référence sont dans ce commit ou un précédent**. Résultat : **0 référence inverse** (un commit ne référence jamais une classe d'un commit suivant).
3. Conséquence : les fichiers partagés sont mis **dans leur état final** dans le premier commit où ils peuvent compiler. Ceux du module Notification et des entités `Permit*` sont au commit 3 (ils portent aussi des blocs ultérieurs, par exemple les cas `ZonesRemoved`, `VehiclesRemoved`, `QrReplaced` de `NotificationType` : sans producteur dans ce commit, donc inoffensifs). Certains tests des lots 05.5.1 et 05.5.2 (par exemple `AuthorizationPeriodServiceTest`) dépendent de lecteurs qui référencent des classes du commit 4 : ils sont **au commit 4**.
4. **Aucun découpage par blocs (`git add -p`)** : la mise en scène se fait fichier par fichier, ce qui limite le risque d'erreur et rend la procédure rejouable.

### Fichiers modifiés par commit

**Commit 2 (contrôle public)**
- `.env`
- `assets/styles/civipass-app.css`
- `config/packages/framework.yaml`
- `config/packages/security.yaml`
- `config/services.yaml`
- `src/Permit/Infrastructure/SecretScrubbingLogger.php`

**Commit 3 (socle 05.5.1 et 05.5.2 : fichiers partagés dans leur état final)**
- `src/Notification/Application/AgentNotificationStatus.php`
- `src/Notification/Application/MessageComposer.php`
- `src/Notification/Application/NotificationDispatcher.php`
- `src/Notification/Application/NotificationOrdering.php`
- `src/Notification/Domain/ChangeCategory.php`
- `src/Notification/Domain/NotificationOutbox.php`
- `src/Notification/Domain/NotificationOutboxRepository.php`
- `src/Notification/Domain/NotificationType.php`
- `src/Notification/Domain/OutboxErrorCode.php`
- `src/Notification/Infrastructure/DoctrineNotificationOutboxRepository.php`
- `src/Notification/Infrastructure/OutboxIntentRecorder.php`
- `src/Permit/Application/PermitRightsEvaluator.php`
- `src/Permit/Application/Read/BeneficiaryPass.php`
- `src/Permit/Application/Read/NotificationFacts.php`
- `src/Permit/Domain/NotificationIntent.php`
- `src/Permit/Domain/NotificationKind.php`
- `src/Permit/Domain/PermitAuthorization.php`
- `src/Permit/Domain/PermitRepository.php`
- `src/Permit/Domain/PermitRights.php`
- `src/Permit/Domain/RightState.php`
- `src/Permit/Domain/VerificationToken.php`
- `src/Permit/Domain/ZoneRight.php`
- `src/Permit/Infrastructure/DoctrinePermitRepository.php`
- `src/Permit/UI/Http/BeneficiaryPassController.php`
- `src/Permit/UI/Twig/PermitExtension.php`
- `src/Request/Domain/GrantWindowCalculator.php`
- `src/Shared/Domain/AuditAction.php`
- `templates/layouts/pass.html.twig`
- `templates/municipal/request/show.html.twig`
- `templates/permit/pass.html.twig`
- `templates/permit/pass_pdf.html.twig`
- `tests/Integration/Notification/NotificationOutboxRecordingTest.php`
- `tests/Integration/Permit/PermitModuleBoundaryTest.php`
- `tests/Support/TestDatabase.php`
- `tests/Unit/Request/GrantWindowCalculatorTest.php`

**Commit 4 (fichiers partagés qui dépendent de classes de 05.5.3 à 05.5.5)**
- `src/Permit/Application/BeneficiaryPassReader.php`
- `src/Permit/Application/NotificationFactsReader.php`
- `src/Permit/Domain/Permit.php`
- `src/Permit/Domain/PermitAuthorizationZone.php`
- `src/Permit/Domain/PermitVehicle.php`
- `tests/Support/NotificationKit.php`

**Commit 1 (documentation)** : `docs/README.md`, `docs/authentication.md`, `docs/decisions/README.md`, `docs/exploitation-notifications.md`, `docs/lot-05-schema.md`, `docs/revisions/CONTRADICTIONS.md`.

### Affectations multiples : pourquoi un fichier modifié est dans un seul commit

Un fichier déjà suivi peut porter des blocs de plusieurs lots (il a été modifié à chaque lot). **Il n'est affecté qu'à UN commit**, dans son **état final**, car le découpage se fait par fichier (pas de `git add -p`). Le tableau ci-dessous est issu de l'analyse du diff (marqueurs de lot dans les lignes ajoutées et mots-clés) ; ce n'est pas une garantie de complétude, mais une aide à la relecture. Un fichier qui porte plusieurs lots est placé au **premier commit où il compile** : ses blocs d'un lot ultérieur sont alors inoffensifs dans ce commit (par exemple, des cas d'énumération sans producteur, des méthodes de dépôt sans appelant) et les classes qu'ils référencent sont dans ce commit ou un précédent (0 référence inverse).

| Fichier modifié | Lots dont il porte des blocs (analyse du diff) | Commit |
|---|---|---|
| `.env` | 05.4 | 2 |
| `assets/styles/civipass-app.css` | 05.4, 05.5.1, 05.5.2 | 2 |
| `config/packages/framework.yaml` | 05.4 | 2 |
| `config/packages/security.yaml` | 05.4 | 2 |
| `config/services.yaml` | 05.4 | 2 |
| `src/Permit/Infrastructure/SecretScrubbingLogger.php` | — | 2 |
| `src/Notification/Application/AgentNotificationStatus.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Notification/Application/MessageComposer.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Notification/Application/NotificationDispatcher.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Notification/Application/NotificationOrdering.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Notification/Domain/ChangeCategory.php` | — | 3 |
| `src/Notification/Domain/NotificationOutbox.php` | 05.5.1 | 3 |
| `src/Notification/Domain/NotificationOutboxRepository.php` | 05.5.1 | 3 |
| `src/Notification/Domain/NotificationType.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Notification/Domain/OutboxErrorCode.php` | 05.5.1 | 3 |
| `src/Notification/Infrastructure/DoctrineNotificationOutboxRepository.php` | 05.5.1 | 3 |
| `src/Notification/Infrastructure/OutboxIntentRecorder.php` | 05.5.1 | 3 |
| `src/Permit/Application/PermitRightsEvaluator.php` | 05.4, 05.5.2, 05.5.3 | 3 |
| `src/Permit/Application/Read/BeneficiaryPass.php` | — | 3 |
| `src/Permit/Application/Read/NotificationFacts.php` | 05.5.1, 05.5.2 | 3 |
| `src/Permit/Domain/NotificationIntent.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Permit/Domain/NotificationKind.php` | 05.5.1, 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `src/Permit/Domain/PermitAuthorization.php` | 05.5.1, 05.5.2, 05.5.3 | 3 |
| `src/Permit/Domain/PermitRepository.php` | 05.4, 05.5.2, 05.5.4, 05.5.5 | 3 |
| `src/Permit/Domain/PermitRights.php` | 05.5.1 | 3 |
| `src/Permit/Domain/RightState.php` | 05.4 | 3 |
| `src/Permit/Domain/VerificationToken.php` | 05.5.5 | 3 |
| `src/Permit/Domain/ZoneRight.php` | 05.5.2 | 3 |
| `src/Permit/Infrastructure/DoctrinePermitRepository.php` | 05.4, 05.5.2, 05.5.4, 05.5.5 | 3 |
| `src/Permit/UI/Http/BeneficiaryPassController.php` | — | 3 |
| `src/Permit/UI/Twig/PermitExtension.php` | 05.5.1 | 3 |
| `src/Request/Domain/GrantWindowCalculator.php` | 05.5.2 | 3 |
| `src/Shared/Domain/AuditAction.php` | 05.5.2, 05.5.3, 05.5.4, 05.5.5 | 3 |
| `templates/layouts/pass.html.twig` | — | 3 |
| `templates/municipal/request/show.html.twig` | — | 3 |
| `templates/permit/pass.html.twig` | 05.5.1 | 3 |
| `templates/permit/pass_pdf.html.twig` | 05.5.1 | 3 |
| `tests/Integration/Notification/NotificationOutboxRecordingTest.php` | 05.5.1 | 3 |
| `tests/Integration/Permit/PermitModuleBoundaryTest.php` | 05.4 | 3 |
| `tests/Support/TestDatabase.php` | 05.4, 05.5.1, 05.5.2, 05.5.4 | 3 |
| `tests/Unit/Request/GrantWindowCalculatorTest.php` | 05.5.2 | 3 |
| `src/Permit/Application/BeneficiaryPassReader.php` | 05.5.4 | 4 |
| `src/Permit/Application/NotificationFactsReader.php` | 05.5.1, 05.5.3 | 4 |
| `src/Permit/Domain/Permit.php` | 05.4, 05.5.4, 05.5.5 | 4 |
| `src/Permit/Domain/PermitAuthorizationZone.php` | 05.5.3 | 4 |
| `src/Permit/Domain/PermitVehicle.php` | 05.5.4 | 4 |
| `tests/Support/NotificationKit.php` | 05.5.1 | 4 |

Lecture : « Commit 3 » pour un fichier qui porte aussi 05.5.3 à 05.5.5 signifie que ces blocs voyagent avec le socle 05.5.1–05.5.2 sans effet fonctionnel avant le commit 4 (qui apporte leurs producteurs, écrans et gabarits). Les fichiers du commit 4 sont ceux qui **référencent** des classes de 05.5.3 à 05.5.5 et ne pouvaient donc pas être plus tôt.

## 3. Plan de mise en scène (staging)

Pour chaque commit `N` (1 à 4), dans l'ordre : `docs/tools/lot-05-commits.sh stage N` (vide l'index avec `git reset -q`, qui ne touche pas l'arbre de travail, puis `git add` des seuls fichiers du manifeste) ; vérification (§4) ; commit à la main, après accord, avec le message proposé ; puis `N+1`. À la fin, `git status` doit être **propre** (aucun fichier oublié) : c'est le contrôle de complétude.

## 4. Stratégie de vérification de chaque commit avec `bin/qa full`

Un commit n'est enregistré que si l'état de l'**index** passe les contrôles. **Aucun de ces contrôles n'a été lancé et le script n'a pas été exécuté** (consigne du 10 novembre 2026) ; le contrôle de complétude du manifeste a été fait avec des commandes `git` ordinaires (voir §5). Procédure par commit (à valider avant exécution) :
1. `docs/tools/lot-05-commits.sh stage N`.
2. Isoler l'index de l'arbre de travail : `git stash push --keep-index --include-untracked -m lot05-verif-N` met de côté tout ce qui n'est **pas** dans l'index (arbre de travail = exactement le commit N cumulé aux précédents).
3. `bin/qa lint`, puis `bin/qa full` (= `make quality` : CS-Fixer, PHPStan niveau 6, PHPUnit), `docs/tools/verify-docs.sh`, `php bin/console doctrine:schema:validate --env=test`.
4. `git stash pop` (retour à l'arbre complet), puis commit (après accord).
5. **Si le commit N est rouge** : ne jamais modifier ni désactiver un test ; fusionner N avec N+1 (le manifeste est régénéré), sans réécrire l'historique déjà fait.
Bases de données : `civipass_test` est déjà migrée aux deux migrations ; les tables des lots suivants sont inoffensives pour les commits antérieurs. Aucune migration n'est jouée par cette procédure.

Risque connu de cette stratégie : `git stash --include-untracked` déplace de nombreux fichiers ; un échec en cours de route exige `git stash list` puis `git stash pop` avant toute autre commande. Variante plus prudente (préférée pour le premier essai) : copier l'arbre (`rsync`) dans un répertoire temporaire, y appliquer le seul index (`git checkout-index`), et lancer les contrôles dans un conteneur dédié, sans toucher à l'arbre de travail.

## 5. Contrôles de cohérence

Le mode `check` du script (lecture seule, **non exécuté depuis la consigne du 10 novembre 2026**) réalise ces contrôles ; ils ont été refaits à la main avec `git ls-files` : manifeste = arbre de travail (aucun fichier oublié ni inconnu), aucun doublon, aucun fichier sensible (`.env.local`, `.sql`, clé, journal), `PUBLIC_VERIFICATION_ENABLED=0`, deux migrations présentes. Analyse de dépendances (méthode §2) : 0 référence inverse. À rejouer **après** toute modification de l'arbre (régénérer le manifeste).

## 6. Limites

- L'état intermédiaire des commits 2 et 3 n'a **pas** été exécuté : seule l'analyse statique garantit qu'aucune classe n'est introuvable ; les tests (notamment ceux qui énumèrent des types ou des gabarits) ne le prouvent qu'au moment de la vérification du §4.
- Les messages de commit n'indiquent aucune migration appliquée en production (aucune ne l'est).
- Rien n'est poussé ; la branche reste `develop`.
