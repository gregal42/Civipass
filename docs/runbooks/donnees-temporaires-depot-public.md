# Runbook — données temporaires du dépôt public (brouillons et justificatifs)

**Statut** : prêt pour la recette locale ; livré, **jamais installé par le dépôt**. Décision : [DT-058](../decisions/technique/DT-058-cookie-de-session-anonyme-et-brouillon-du-depot-public.md) (proposée) ; contexte : [security.md](../security.md).

## 1. Ce qui est conservé, et combien de temps

| Donnée | Où | Durée | Suppression |
|---|---|---|---|
| Brouillon du formulaire public (identité, contacts, zones, plaques) | Session anonyme (fichier du dossier des sessions PHP) | 30 min d'inactivité | À l'accès suivant (expiration), par « Effacer ma saisie », ou par `app:request-drafts:purge` (toutes les 10 min) |
| Justificatifs temporaires (avant l'envoi) | `var/storage/tmp` | 24 h au plus, 30 min si rattachés à un brouillon expiré ou effacé | Mêmes mécanismes, et `app:request-uploads:purge` (horaire) pour les non rattachés |
| Confirmation (référence, e-mail, nom) | Même session | 30 min d'inactivité | Idem |

Les sessions authentifiées des agents ne sont jamais touchées par ces purges.

## 2. Tâches planifiées (unités fournies dans `ops/systemd/`)

| Unité | Fréquence | Commande | Supervision (`app:ops:status`) |
|---|---|---|---|
| `civipass-request-drafts-purge` | toutes les 10 min | `app:request-drafts:purge --path=/var/lib/php/sessions` | alerte après 1 h sans succès |
| `civipass-request-uploads-purge` | toutes les heures | `app:request-uploads:purge` | alerte après 2 h |

À adapter avant installation : `--path` doit être le dossier des sessions du pool PHP-FPM « civipass » (Debian/Ubuntu : `/var/lib/php/sessions`, sauf `session.save_path` défini dans le pool) ; l'unité s'exécute sous le compte `civipass`, qui possède ces fichiers. Le dossier doit figurer dans `ReadWritePaths` de l'unité.

## 3. Vérifier

```
php bin/console app:request-drafts:purge --path=/var/lib/php/sessions --dry-run   # compte sans supprimer
php bin/console app:request-drafts:purge --path=/var/lib/php/sessions             # supprime
php bin/console app:ops:status                                                     # battements de cœur
```
La commande n'affiche que des compteurs (brouillons, pièces temporaires, sessions authentifiées ignorées, sessions lues), jamais de donnée personnelle.

## 4. Nettoyage général des sessions PHP (M6)

Diagnostic complet et configuration recommandée : [lot-10-m6-sessions-php.md](../lot-10-m6-sessions-php.md). En résumé :

- Gestionnaire natif (fichiers) ; `gc_maxlifetime` actuel de l'image = **24 min**, **inférieur** à l'inactivité autorisée d'un agent (30 min, réglable jusqu'à 2 h) : constaté, une session inactive 26 min est supprimée par la collecte PHP et l'agent est renvoyé à la connexion.
- Réglage recommandé (exemple **non appliqué** : `ops/php/civipass-session.ini.example`) : `session.gc_maxlifetime = 10800` (inactivité maximale réglable de 2 h + 1 h de marge). La proposition précédente à 12 h est **abandonnée** : la collecte se mesure sur l'inactivité du fichier, pas sur la durée absolue.
- À vérifier sur le serveur cible : minuterie `phpsessionclean` (Debian/Ubuntu) et valeur qu'elle lit, dossier réel des sessions.
- Les brouillons anonymes ne dépendent pas de ce réglage : `app:request-drafts:purge` les supprime à 30 minutes.

## 5. Incident

- La purge échoue (alerte `civipass-failure@…`) : vérifier les droits sur le dossier des sessions (compte `civipass`), puis relancer la commande à la main.
- Doute sur une suppression à tort : seules les sessions **anonymes** avec brouillon inactives depuis plus de 30 minutes sont supprimées ; un citoyen concerné voit son formulaire vide et un message au retour.
