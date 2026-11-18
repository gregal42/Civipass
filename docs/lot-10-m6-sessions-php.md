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

## 7. Risques : nettoyages concurrents et stockage partagé entre pools PHP

**Valeur retenue (18 novembre 2026)** : `session.gc_maxlifetime = 10800`, **sous réserve de vérification sur le serveur Linux cible** ; le réglage reste dans l'**exemple** (`ops/php/civipass-session.ini.example`) tant que cette vérification n'a pas eu lieu. Les limites applicatives de session ne changent pas.

### 7.1 Plusieurs « nettoyeurs » sur le même dossier

Plusieurs mécanismes peuvent supprimer des fichiers de session : (a) le ramasse-miettes interne de PHP (probabiliste, à l'ouverture d'une session) ; (b) la minuterie `phpsessionclean` des paquets Debian/Ubuntu ; (c) la purge applicative `app:request-drafts:purge` (brouillons anonymes de plus de 30 min) ; (d) tout nettoyage maison (`find -mmin`, `tmpreaper`, `systemd-tmpfiles` si le dossier est sous `/tmp`).

| Risque | Conséquence | Parade |
|---|---|---|
| **Le seuil le plus court gagne** : si un nettoyeur applique 24 min (valeur de la ligne de commande ou d'un autre SAPI) alors que le pool vise 3 h, c'est 24 min qui s'applique | Sessions d'agents supprimées trop tôt (déconnexion anticipée), exactement le défaut constaté | Lister tous les nettoyeurs actifs ; chacun doit appliquer **au moins 10 800 s** ; vérifier la valeur réellement lue par la minuterie (§ 6) |
| **Suppression d'un fichier de session en cours d'utilisation** (suppression pendant qu'une requête le tient ouvert) | Sous Linux, la suppression réussit ; la requête écrit dans un fichier déjà supprimé : sa modification est perdue, l'utilisateur est renvoyé à la connexion à la requête suivante | Les seuils (3 h pour PHP, 30 min pour la purge de brouillons) sont des **inactivités** : seule une session sans requête depuis plus que le seuil est visée ; la fenêtre de course (une requête qui arrive juste au moment du seuil) est de l'ordre de la seconde et, pour un brouillon public, c'est le comportement voulu (effacement à 30 min) |
| **Purge de brouillons et collecte PHP simultanées** | Aucune erreur : la suppression d'un fichier déjà supprimé est ignorée (`unlink` silencieux), le compteur peut compter un fichier que PHP a supprimé entre-temps | Aucune action ; les compteurs sont indicatifs |
| **Nettoyage de `/tmp`** (si `session.save_path` est vide et que le dossier retombe dans `/tmp`) | Des sessions disparaissent selon la politique du système, hors de tout contrôle applicatif | Définir un dossier dédié (§ 7.2) |

### 7.2 Dossier de sessions partagé entre plusieurs pools ou applications

Le dossier par défaut (`/var/lib/php/sessions` sur Debian/Ubuntu) est **commun** à tous les pools PHP-FPM du serveur.

| Risque | Conséquence | Parade recommandée |
|---|---|---|
| Un **autre pool** ou une autre application du même serveur applique un seuil plus court (ou déclenche le ramasse-miettes avec son propre `gc_maxlifetime`) | Ses collectes suppriment aussi les sessions de Civipass si le même compte système possède les fichiers, ce qui ramène la durée utile à son seuil | Dossier **dédié** au pool Civipass : `php_admin_value[session.save_path] = /var/lib/civipass/sessions` (propriétaire `civipass`, mode `0700`) |
| Fichiers de pools différents sous des comptes différents | Les collectes d'un pool ne peuvent pas supprimer les fichiers d'un autre compte (droits `0600`), mais **s'accumulent** si aucun nettoyeur ne tourne pour eux | Prévoir un nettoyeur par dossier |
| **Dossier dédié = la minuterie `phpsessionclean` ne le nettoie pas** (elle ne traite que le dossier par défaut) | Les sessions s'accumulent sans limite | Activer le ramasse-miettes interne dans le pool (`php_admin_value[session.gc_probability] = 1`, `session.gc_divisor = 100`) **ou** une minuterie maison ; garder la purge de brouillons, dont `--path` doit désigner ce même dossier |
| Lecture inter-pools | Un pool compromis ne doit pas lire les sessions d'un autre | Dossier `0700` propre à chaque compte ; jamais un dossier commun lisible |

**Décision à prendre à la vérification sur le serveur cible** : dossier par défaut (plus simple, nettoyé par la minuterie du paquet, partagé) ou dossier dédié (isolé, mais nettoyage à fournir). Recommandation : **dossier dédié** si le serveur héberge un autre pool, **dossier par défaut** sinon ; dans les deux cas, `--path` de l'unité `civipass-request-drafts-purge` doit correspondre au dossier réellement utilisé.

## 8. Décision

`session.gc_maxlifetime = 10800` est **accepté comme valeur cible sous réserve de la vérification du § 6 sur le serveur Linux** ; il reste dans la configuration d'exemple (jamais appliqué automatiquement), en remplacement de la proposition à 12 h du runbook. Reste à trancher à la vérification : dossier de sessions par défaut ou dédié (§ 7.2).
