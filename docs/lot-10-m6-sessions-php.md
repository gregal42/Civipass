# LOT 10 / M6 — Sessions PHP : diagnostic et configuration recommandée

Date : 18 novembre 2026. **Aucune configuration de production n'est modifiée** : le fichier livré est un exemple à installer à la main après validation. Les limites applicatives de session (inactivité, durée absolue) restent **inchangées**. Contexte : [DT-058](decisions/technique/DT-058-cookie-de-session-anonyme-et-brouillon-du-depot-public.md), [runbook](runbooks/donnees-temporaires-depot-public.md).

## 1. Ce qui est réellement utilisé

| Point | Constat | Source |
|---|---|---|
| Gestionnaire | **Gestionnaire natif de PHP, fichiers** (`session.save_handler=files`), via le stockage natif de Symfony (`session.storage.factory.native`). Aucun `handler_id` configuré. En test : stockage fichier simulé (`MOCKSESSID`) | `debug:config framework session`, `framework.yaml` |
| Stockage | Dossier `session.save_path` de PHP : vide sur l'image du projet (donc `/tmp`) ; sur Debian/Ubuntu, `/var/lib/php/sessions`. Le pool FPM du kit ne le fixe pas. Fichiers `0600` du compte du pool (`civipass`) | `ini_get`, `ops/nginx/php-fpm/civipass-pool.conf.example` |
| Mise à jour du fichier | À chaque requête qui démarre la session (`metadata_update_threshold: 0` ; PHP rafraîchit la date de modification même sans changement de contenu) : **l'ancienneté du fichier = l'inactivité** | `framework.yaml` |
| Ramasse-miettes | Image du projet : `gc_probability=1`, `gc_divisor=1000` (une session démarrée sur mille déclenche une collecte), `gc_maxlifetime=1440` s (24 min). Non déterministe | `ini_get` dans le conteneur |

## 2. Durées applicatives (inchangées)

| Durée | Valeur par défaut | Bornes | Où |
|---|---|---|---|
| Inactivité agent | 30 min | 5 min à **2 h** (`SessionLifetime::MIN_IDLE`/`MAX_IDLE`) | `SessionLifetime`, `SessionLifetimeListener` |
| Inactivité CWS | 15 min | idem | idem |
| Durée absolue agent / CWS | 12 h / 8 h | jusqu'à 24 h | idem |
| Avertissement | 2 min avant | 30 s à 10 min | `session-guard` |
| Brouillon public anonyme | 30 min | fixe | `PublicRequestDraft::TTL_SECONDS` |

Ces durées sont appliquées **par l'application** (horodatages stockés dans la session) ; elles ne dépendent pas du ramasse-miettes de PHP, sauf pour une raison : si PHP supprime le fichier plus tôt, la session disparaît plus tôt.

## 3. Mesures sur la recette

1. **Retard de nettoyage** : 123 fichiers de session, dont 40 avec un brouillon public, tous plus anciens que 24 min (le plus ancien plus de 2 h 40 min). Le nettoyage probabiliste ne garantit rien.
2. **Suppression trop précoce (défaut du réglage actuel)** : une session d'agent connecté, **inactive depuis 26 minutes** (l'application en autorise 30), a été supprimée par une collecte PHP avec `gc_maxlifetime=1440`. La requête suivante a redirigé vers `/login` au lieu de répondre 200. Reproduit aussi par un test automatisé.
3. **Effet sur l'application** : tant que la collecte ne passe pas, aucune conséquence ; quand elle passe, un agent inactif plus de 24 minutes est déconnecté avant l'échéance annoncée (30 minutes). Sans fuite de données, mais avec un message d'expiration inexact.

## 4. Conséquences d'un réglage à 12 heures

Le ramasse-miettes compare l'**inactivité du fichier** au réglage ; la **durée absolue** de 12 h n'a pas à être couverte, car l'activité rafraîchit le fichier.

| `gc_maxlifetime` | Déconnexion anticipée d'un agent | Conservation des sessions inactives | Verdict |
|---|---|---|---|
| 1 440 s (actuel) | **Oui** dès 24 min d'inactivité (durée autorisée : 30 min, jusqu'à 2 h) | courte | à corriger |
| 3 600 s | Oui si l'administrateur règle l'inactivité au-delà de 1 h (bornée à 2 h) | courte | insuffisant pour toute la plage réglable |
| **10 800 s (3 h)** | **Non**, pour toute valeur réglable (max 2 h) + 1 h de marge | un fichier inactif reste au plus 3 h | **recommandé** |
| 43 200 s (12 h) | Non | un fichier de session inactif (jeton de sécurité, jetons CSRF) reste jusqu'à 12 h sans aucun bénéfice | inutilement long |

Les sessions **anonymes avec brouillon** ne dépendent pas de ce réglage (purgées à 30 min par `app:request-drafts:purge`) ; les sessions anonymes sans brouillon (jetons CSRF seuls, quelques centaines d'octets) restent jusqu'à `gc_maxlifetime`.

## 5. Configuration recommandée et son test

`ops/php/civipass-session.ini.example` : `session.gc_maxlifetime = 10800` (= inactivité maximale réglable de 7 200 s + 1 h de marge), avec le nettoyage décrit ci-dessous. Exemple **non appliqué**.

Tests (`tests/Unit/SessionGcConfigurationTest.php`), qui exécutent le vrai ramasse-miettes de PHP :
- la valeur proposée est supérieure à `SessionLifetime::MAX_IDLE`, au brouillon public et aux défauts ;
- les durées applicatives par défaut sont inchangées (30 min, 12 h, 15 min, 8 h) ;
- avec la valeur proposée, des sessions inactives 26 min, 30 min et 2 h sont **conservées** et une session plus ancienne est supprimée ;
- avec 1 440 s, la session inactive 26 min est supprimée (constat de départ, conservé comme garde).

## 6. Nettoyage propre à l'environnement Linux cible (à vérifier au 09.7)

Je n'ai pas accès au serveur cible : ce qui suit est une **liste de contrôle**, pas un constat.

- **Debian/Ubuntu (paquets PHP)** : le paquet désactive normalement le ramasse-miettes interne (`gc_probability=0`) et fournit la minuterie systemd `phpsessionclean` (environ toutes les 30 minutes) qui supprime les fichiers plus anciens que `gc_maxlifetime`. Points à vérifier : (a) la minuterie est active (`systemctl list-timers phpsessionclean.timer`) ; (b) **la valeur lue par la minuterie** : elle consulte la configuration PHP de chaque SAPI, donc le réglage doit être placé dans un fichier `conf.d` du SAPI FPM (comme dans l'exemple) et non seulement dans `php_admin_value` du pool, que cette minuterie ne voit pas (à confirmer sur la version installée) ; (c) le dossier réel des sessions du pool.
- **Hors paquets Debian** (image Docker, installation manuelle) : activer le ramasse-miettes interne (`gc_probability=1`, `gc_divisor=100`) ou planifier un nettoyage équivalent.
- **Contrôle final** : après installation, redémarrer PHP-FPM, puis vérifier `php-fpm8.4 -i | grep gc_maxlifetime` et rejouer le test d'inactivité du § 3 (session inactive 26 min : conservée).

## 7. Décision attendue

Valider `session.gc_maxlifetime = 10800` comme réglage cible (à appliquer à la mise en service, après vérification du § 6), en remplacement de la proposition à 12 h du runbook.
