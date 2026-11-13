# Civipass — Back-office CWS (Sonata Admin)

Réservé aux **super-administrateurs CWS**. Les modules Sonata sont en **lecture seule stricte** ; les écritures passent exclusivement par des **actions métier nommées** sous `/admin/cws/` ([DT-052](decisions/technique/DT-052-actions-nommees-d-ecriture-dans-le-back-office-cws.md), LOT 07.1).

## Accès

| Condition | Mise en œuvre |
|---|---|
| Authentification | Firewall Symfony existant (`/login`) ; Sonata n'a **aucun** système d'authentification propre |
| Rôle | `ROLE_SUPER_ADMIN` **et** `IS_AUTHENTICATED_FULLY` (`access_control` de `security.yaml`) |
| Compte communal | 403, quels que soient ses rôles communaux (un membership ne donne aucun rôle Symfony) |
| Session « mémorisée » (remember-me) seule | Redirigée vers `/login` : mot de passe exigé |
| Compte désactivé | Session invalidée immédiatement |
| Déconnexion | POST + jeton CSRF (bouton du menu Sonata) |

Un superadmin n'a **aucun rôle communal implicite**. Les trois permissions de supervision de `MembershipAccessPolicy` (gérer les membres, modifier le contact, lire l'audit d'une commune) sont inchangées et **non utilisées par Sonata** : elles ne servent qu'aux services applicatifs audités. Elles ne s'étendent ni aux événements, ni aux zones, ni aux demandes, décisions ou laissez-passer.

## Modules (URLs)

| Module | Liste | Fiche |
|---|---|---|
| Utilisateurs | `/admin/identity/user/list` | `/admin/identity/user/{uuid}/show` |
| Communes | `/admin/communes/commune/list` | `/admin/communes/commune/{uuid}/show` |
| Appartenances communales | `/admin/communes/appartenance/list` | `/admin/communes/appartenance/{uuid}/show` |

Titre : **« BO Civipass »**. Logo : le **monogramme « Cp »** du design system (carré `brand`, Atkinson Hyperlegible Mono Bold), en SVG vectoriel (`assets/images/civipass-monogram.svg`, glyphes en tracés : aucune dépendance à une police ni au CSS du design system, que Sonata ne charge pas). Il est généré par `design-system/tools/import.mjs` (adaptation M1 de `PROVENANCE.md`), sans aucune forme nouvelle : le design system n'a pas d'autre logo.

Autres : `/admin/dashboard`, `/admin/` (redirige), `/admin/search`.

- **Utilisateurs** : adresse email, nom, prénom, actif, superadmin, dernière connexion, dates. **Jamais** le hash du mot de passe, ni aucun jeton (réinitialisation, remember-me) : les champs sont déclarés un par un, il n'y a pas de découverte automatique.
- **Communes** : nom, code INSEE, statut administratif, contact, adresse, dates. Pas de module Abonnements : l'entité n'existe pas encore.
- **Appartenances** : utilisateur (lien vers sa fiche), commune (lien vers sa fiche), rôles communaux en clair, activation, dates.
- **Navigation** : depuis une fiche utilisateur ou commune, l'onglet « Appartenances » ouvre la liste filtrée ; depuis une appartenance, liens vers l'utilisateur et la commune.
- **Filtres, recherche, tri** : sur chaque liste (ex. `?filter[email][value]=maire`, `?filter[_sort_by]=email&filter[_sort_order]=ASC`).
- Les identifiants sont des **UUID** : tout autre format donne un 404 propre (contrainte de route), jamais une erreur serveur.

## Lecture seule stricte : quatre barrières indépendantes

1. **Routes** : chaque admin ne déclare que `list` et `show`. Create, edit, delete, batch, export, history, acl **n'existent pas** (404, y compris pour un superadmin).
2. **Méthodes HTTP** : `access_control` refuse `POST`, `PUT`, `PATCH`, `DELETE` sur `/admin` (403), même au superadmin.
3. **Routes techniques de Sonata** (édition inline, éléments de formulaire, autocomplétion, aperçu d'objet) : fermées à tous (403) ; restent le tableau de bord, la recherche, les listes et fiches.
4. **Droits Sonata** : `ReadOnlySecurityHandler` s'appuie sur le gestionnaire par rôles de Sonata et n'accorde que `LIST` et `VIEW` ; `CREATE`, `EDIT`, `DELETE`, `EXPORT`… sont refusés **même à `ROLE_SUPER_ADMIN`**.

Les rôles communaux, les statuts de commune et les privilèges superadmin ne se modifient que par `MembershipManager`, `MunicipalityManager` et `UserManager` (transaction, verrou, audit).

## Actions nommées d'administration (LOT 07.1)

Accessibles depuis la liste des communes (bouton « Ouvrir une commune ») et l'onglet « Gestion CWS » d'une commune ; compte : onglet « Gérer le compte (CWS) » d'un utilisateur non CWS.

| Action | Route | Effet (service audité) |
|---|---|---|
| Ouvrir une commune | `/admin/cws/communes/ouvrir` | depuis le référentiel national (nom et INSEE jamais saisis), idempotent (`MunicipalityOpening`) |
| Fiche de gestion | `/admin/cws/communes/{id}` | situation (statut, droit de créer de **nouveaux** événements, continuité, administrateurs actifs, messagerie), accès, administrateurs, invitations, contact, historique administratif (liste blanche, aucune donnée opérationnelle) |
| Statut, contact | `…/confirmer/commune_*`, `…/contact` | `MunicipalityManager` (suspendre, réactiver, désactiver ; contact) |
| Accès commerciaux | `…/acces`, `…/confirmer/access_revoke` | attribuer, renouveler, révoquer (`CommercialAccessManager`) ; sans paiement |
| Administrateur | `…/administrateurs/inviter`, `…/confirmer/invitation_*`, `…/confirmer/admin_remove` | inviter, renvoyer, révoquer, retirer (`MunicipalityAdminInvitationService`, `MembershipManager`) ; dernier administrateur protégé |
| Compte | `/admin/cws/comptes/{id}` | désactiver, réactiver, envoyer un lien de réinitialisation (jamais un compte CWS) |

Chaque POST exige un jeton CSRF propre à l'action et à la cible ; les GET (fiches, confirmations) n'écrivent jamais. Le lien d'invitation n'est **jamais affiché** : un échec d'envoi se traite par « Renvoyer ».

## Limites et évolutions

- Toute nouvelle écriture suit le même modèle (action nommée, CSRF, service audité, tests) ; aucun formulaire générique d'entité. Les barrières 1 à 4 ci-dessus concernent les admins Sonata, inchangés ; la barrière 2 exclut désormais `/admin/cws/`.
- Non couvert : création/retrait de super-administrateur (console `app:super-admin:create`), clôture forcée (DM-065) et impersonation (DM-061).
- L'audit (`audit_log`) et les demandes de réinitialisation n'ont volontairement aucun module.
- Les assets Sonata (`public/bundles/`) ne sont pas versionnés : `assets:install` (script Composer) doit tourner au déploiement.
- Apparence : native Sonata (seuls le titre et le logo sont ceux de Civipass) ; le design system n'est pas chargé dans les pages Sonata.
