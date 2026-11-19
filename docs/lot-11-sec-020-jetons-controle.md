# SEC-020 — Jetons de contrôle : empreinte + copie scellée (correctif B, 19 novembre 2026)

**Décision du commanditaire (option A)** : empreinte SHA-256 pour la recherche, copie chiffrée pour le réaffichage et la réimpression, suppression du clair après migration vérifiée, QR existants conservés, DM-113 et DM-118 inchangés.

## Fonctionnement
| Élément | Avant | Après |
|---|---|---|
| Colonne `token` (clair) | stockée, unique | **supprimée** (migration 2/2) |
| `token_sha256` | — | SHA-256 (hex) du jeton ; unique ; clé de recherche du contrôle en ligne **et** clé du jeu de données hors connexion (identique à `DatasetCodec::tokenKey`) |
| `token_encrypted` | — | copie scellée `k1.<id de clé>.<base64>` (XChaCha20-Poly1305, liée à l'identifiant de la ligne) ; sert au réaffichage du QR et au PDF à la demande |
| Contrôle, vérification publique, jeu de données hors connexion | lecture du clair | recherche/dérivation par l'**empreinte**, sans jamais déchiffrer : le contrôle continue même si le trousseau est perdu |

Une fuite de la base **seule** ne livre plus aucun jeton ; il faut en plus le trousseau (hors base).

## Migrations (dans l'ordre, l'étape intermédiaire est une commande)
1. `Version20261119140000` : ajoute `token_sha256` (calculée par MySQL pour l'existant, unique) et `token_encrypted`, rend `token` facultatif. **Aucun QR ne change.** Retour arrière sans perte (`down()`), le clair est toujours là.
2. `php bin/console app:permit:protect-tokens` : scelle chaque jeton existant, **vérifie** (réouverture identique, empreinte concordante) ; idempotente ; n'affiche jamais un jeton.
3. `Version20261119140100` : supprime le clair. **Refuse de s'exécuter** (sans rien modifier) si une ligne n'a pas de copie scellée.

**Déploiement** : `doctrine:migrations:migrate --to-version=Version20261119140000` → `app:permit:protect-tokens` → `doctrine:migrations:migrate`. Une sauvegarde chiffrée vérifiée précède l'étape 3 (obligatoire).

**Retour arrière après l'étape 3** : `doctrine:migrations:execute 'App\Migrations\Version20261119140100' --down` (recrée `token` vide) puis `app:permit:protect-tokens --restore-clear` (rouvre les copies scellées, vérifie l'empreinte) avant de revenir à l'ancien code ; en dernier recours, la sauvegarde.

## Clé dédiée et rotation (sans invalider un laissez-passer)
Trousseau dédié `PERMIT_TOKEN_KEYRING` / `PERMIT_TOKEN_ACTIVE_KEY_ID` (même format et même classe `KeyRing` que `NOTIFICATION_KEYRING`, **distincts**). Rotation : ajouter la nouvelle clé (`p2:…,p1:…`), la déclarer active, lancer `app:permit:protect-tokens --rewrap` (ré-écrit les copies, vérifie), puis retirer `p1`. Aucun QR n'est invalidé : l'empreinte ne dépend d'aucune clé. Sans trousseau (développement), repli sur une clé dérivée de `APP_SECRET` (identifiant `app`), toujours rouvrable ; `check-deployment.sh` exige le trousseau dédié en production.

## Risques résiduels
- **Perte du trousseau** : QR non réaffichables ni réimprimables (le contrôle fonctionne) ; remède : remplacement du QR (LOT 05.5.5). D'où le séquestre hors serveur du trousseau, comme celui des notifications.
- Le clair circule en mémoire à l'affichage du QR/PDF (inévitable) et dans le QR/PDF remis au bénéficiaire.
- Preuve sur données réelles : base de développement (1 jeton) ; la migration n'est pas éprouvée sur un volume de production.
