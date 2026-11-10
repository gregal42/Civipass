# Inventaire Git depuis le LOT 05.4.1 et proposition de découpage en commits

**Statut : inventaire et proposition (10 novembre 2026). Aucun commit, aucun push, aucun `git add` n'a été réalisé.** Dernier commit : `ed8819b Lot 5 TBC` (branche `develop`).

## 1. Inventaire

- **251 fichiers** au 10 novembre 2026 (après le LOT 05.5.5 et son correctif de confidentialité) : 54 modifiés, 197 nouveaux (dont `ops/`, 2 migrations, DM-119, 5 cadrages, plan et script de découpage). Première photographie, avant le 05.5.5 : 227 entrées. Rien d'ignoré par erreur : `var/` (journaux, `var/qa/`) est exclu par `.gitignore`.
- **Aucune donnée sensible** détectée dans l'arbre (ni `.env.local`, ni sauvegarde `.sql`, ni clé, ni journal). `.env` ne contient que des valeurs non secrètes ; **`PUBLIC_VERIFICATION_ENABLED=0`**.
- **Migrations** : `Version20261110150151` (05.5.1 : `permit_revision`, `permit_revision_vehicle`, colonnes de couverture de l'outbox) et `Version20261110155643` (05.5.2 : `permit_authorization_period`), appliquées aux bases `civipass_test` et `civipass` (développement) seulement. Les lots 05.5.3 et 05.5.4 n'ont aucune migration.
- Dernière validation : `bin/qa full` EXIT 0 (2 827 tests, 19 736 assertions, 3 recettes manuelles ignorées), `verify-docs.sh` OK.

| Ensemble (approximatif, par nom de fichier) | Fichiers |
|---|---|
| 05.4 : contrôle public, limitation de débit, exploitation nginx (`ops/`, `config/`, `.env`, `PublicVerification*`, `RateLimiter`, gabarits `verification_*`) | ~54 |
| 05.5.0 et 05.5.1 : révisions, garde, révocation, écran des droits, DM-119 | ~29 |
| 05.5.2 : périodes (planificateur, service, `permit_authorization_period`) | ~25 |
| 05.5.3 : zones | ~31 |
| 05.5.4 : plaques | ~29 |
| **Fichiers partagés** modifiés par plusieurs lots (module Notification, entités `Permit*`, `AuditAction`, `TestDatabase`, `NotificationKit`, gabarits de pass et PDF, `GrantWindowCalculator`…) | ~50 |
| Documentation transverse (`README`, `authentication`, `exploitation-notifications`, `lot-05-schema`, `CONTRADICTIONS`, décisions) | 6 |
| Migrations | 2 |

## 2. Contraintes du découpage

1. Environ **50 fichiers sont partagés** entre plusieurs lots (par exemple `NotificationDispatcher`, `NotificationType`, `Permit`, `PermitAuthorization`, `AuditAction`, `TestDatabase`) : leur contenu final mêle plusieurs lots. Un découpage « un commit par lot » exige `git add -p` sur ces fichiers.
2. Un commit intermédiaire n'est **pas garanti vert** s'il contient un fichier partagé dans son état final (par exemple `NotificationType` qui connaît déjà `VehiclesRemoved`). Le contrôle se fait commit par commit (§4).
3. Les deux migrations vont dans les commits de leur lot, **jamais** groupées avec un autre.

## 3. Proposition

**Option recommandée à l'époque (7 commits, ordre de dépendance) — remplacée par l'option des 4 commits, voir [lot-05-git-decoupage.md](lot-05-git-decoupage.md)** :

| # | Message proposé | Contenu |
|---|---|---|
| 1 | `docs(decisions): DM-118/DM-119, cadrages 05.5, contradictions` | DM-119, `docs/decisions/README.md`, `CONTRADICTIONS.md`, cadrages 05.5.2 à 05.5.5, inventaire Git, `README` |
| 2 | `feat(permit): contrôle public /v/{jeton}, limitation de débit, exploitation nginx` | LOT 05.4 : `ops/`, `config/packages/{framework,security}.yaml`, `config/services.yaml`, `.env`, `PublicVerification*`, `RateLimiter`, gabarits `verification_*`, `SecretScrubbingLogger`, tests associés, `docs/authentication.md`, `docs/exploitation-verification-publique.md` |
| 3 | `feat(permit): révisions des droits et révocation d'une autorisation (05.5.1)` | `PermitRevision*`, `PermitRevisionGuard`, révocation, écran des droits, migration `Version20261110150151`, notification `AUTHORIZATION_REVOKED`, tests |
| 4 | `feat(permit): périodes d'une autorisation : prolongation, reprise, raccourcissement (05.5.2)` | planificateur, service, `permit_authorization_period`, migration `Version20261110155643`, notifications de période, tests |
| 5 | `feat(permit): zones d'une autorisation (05.5.3)` | planificateur, service, écrans, notifications `ZONES_*`, tests |
| 6 | `feat(permit): plaques d'un laissez-passer (05.5.4)` | planificateur, service, écran, notifications `VEHICLES_*`, tests |
| 7 | `docs(lot-05): exploitation des notifications, schéma, recette` | `exploitation-notifications.md`, `lot-05-schema.md` |

**Partage des fichiers communs** : chaque fichier partagé est découpé par `git add -p` selon le lot qui a introduit chaque bloc (par exemple, dans `NotificationType`, `ZonesRemoved` au commit 5 et `VehiclesRemoved` au commit 6). Les blocs du module Notification introduits par la couverture de révisions vont au commit 3.

**Option de repli (4 commits, plus sûre, sans `git add -p`)** : (1) documentation ; (2) LOT 05.4 ; (3) LOT 05.5.0 à 05.5.2 avec les deux migrations ; (4) LOT 05.5.3 et 05.5.4. Chaque commit contient les fichiers partagés dans leur état final du dernier lot qu'il porte ; le commit 4 est le seul à contenir des ajouts de plusieurs lots dans un même fichier partagé.

## 4. Vérification proposée avant d'enregistrer

Pour chaque commit : `git stash --keep-index`, `bin/qa lint`, `bin/qa full` (base de test migrée : les tables des lots suivants sont inoffensives), `docs/tools/verify-docs.sh`. En cas d'échec d'un commit intermédiaire, le fusionner avec le suivant plutôt que de modifier un test. Message de commit en français, sans mention de migration appliquée en production (aucune ne l'est).

## 5. Décision demandée

Choisir entre l'option recommandée (7 commits avec `git add -p`) et l'option de repli (4 commits), et autoriser la préparation d'un script de découpage **sans exécution**. Aucun commit ni push avant votre accord explicite.
