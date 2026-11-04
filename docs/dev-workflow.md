# Workflow de développement économe (tests ciblés, sorties compactes)

Objectif : réduire le temps et la consommation de contexte (tokens) **sans affaiblir** la qualité, la sécurité ni la couverture. Aucune règle métier, d'architecture ou de gouvernance n'est modifiée ; `make quality` reste **le** contrôle de livraison.

## Commandes (`bin/qa`, aussi via `make`)

| Commande | Usage |
|---|---|
| `bin/qa changed` (`make test-changed`) | tests liés aux fichiers modifiés (git) : même nom de classe, tests fonctionnels du module pour l'UI et les gabarits, tests de domaine du module, gardes d'architecture. Signale les changements transverses (config, migrations, support de test) |
| `bin/qa failed` (`make test-failed`) | relance **uniquement** les tests échoués au dernier passage |
| `bin/qa lint` | PHP-CS-Fixer (correction) puis PHPStan sur les fichiers PHP modifiés |
| `bin/qa module Event` | tous les tests d'un module (Event, Municipality, Identity, Onboarding, Shared) |
| `bin/qa run MOTIF` | tests dont le nom correspond au motif (`--filter`) |
| `bin/qa full` (`make qa`) | `make quality` complet (PHPStan, CS-Fixer, PHPUnit) avec sortie compacte |
| `bin/qa log [N]` | fin du dernier journal PHPUnit, **seulement si nécessaire** |

## Sorties compactes
- Succès : une ligne (`OK (545 tests, 4205 assertions)`).
- Échec : uniquement les échecs, avec message et trace, bornés. Les lignes de journal applicatif (`[error] Uncaught…`, normales dans les tests de refus 403/404) sont filtrées de l'affichage.
- Journaux complets conservés localement dans `var/qa/` (`phpunit.log`, `quality.log`, `phpstan.log`, `failed.txt`), non versionnés : ne les relire que pour un diagnostic précis, avec `grep` ciblé plutôt que lecture intégrale.

## Règles de fonctionnement
1. **Pendant l'incrément** : `bin/qa lint` puis `bin/qa changed` après un ensemble cohérent de modifications, pas après chaque ligne.
2. **Après un échec** : corriger, relancer `bin/qa failed` jusqu'au vert, puis relancer `bin/qa changed` une fois.
3. **Fin d'incrément, avant de proposer la livraison** : `bin/qa full` (ou `make quality`) **obligatoire**, puis `docs/tools/verify-docs.sh`, validation Doctrine et `git diff`. Un seul passage complet par livraison ; pas de relance complète après une petite correction (utiliser `bin/qa failed`).
4. **Jamais** masquer un échec, désactiver ou assouplir un test, ni sauter `make quality` pour obtenir un résultat vert. Un test instable se diagnostique (cause, correctif), il ne se désactive pas.
5. Changement transverse (`config/`, `src/Migrations/`, `tests/Support/`, `Makefile`, `phpunit.dist.xml`) : `bin/qa changed` ne suffit pas, prévoir `bin/qa full`.
6. Pas de relecture intégrale de la documentation à chaque incrément : `CLAUDE.md` est l'index ; ne charger que les décisions (`docs/decisions/`), sections SFD/STD et fichiers de la tâche.
7. Recherches : une recherche ciblée (`grep` sur un motif, un dossier) plutôt que la relecture de fichiers entiers ; ne pas répéter une recherche déjà faite dans la session.
8. Sous-agents : seulement pour un travail parallèle réellement indépendant ou volumineux ; pas par défaut.
9. Comptes rendus courts : modifications, tests, blocages, résultat.

## Ce qui ne change pas
- `make quality` (cibles `phpstan`, `cs-check`, `test`) est inchangé ; `bin/qa` ne l'affaiblit pas et ne le remplace jamais.
- Aucune intégration continue n'existe dans le dépôt : le contrôle de livraison est `make quality` exécuté localement.
- Base de test uniquement (`civipass_test`) ; la base de développement n'est jamais touchée par ces commandes.
