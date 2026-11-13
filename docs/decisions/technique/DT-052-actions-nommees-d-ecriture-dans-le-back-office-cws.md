# DT-052 — Actions d'écriture nommées dans le back-office CWS (amende DT-018)

- **Identifiant** : DT-052
- **Thème** : Back-office
- **Statut** : Validée
- **Validation** : A1 validé par le commanditaire le 13 novembre 2026 (validation du cadrage du LOT 07.1)
- **Décisions remplacées** : DT-018 en partie (seule l'interdiction de toute écriture depuis Sonata est levée, dans le cadre ci-dessous).

## Contexte

L'objectif du LOT 07.1 est que CWS ouvre une commune pilote intégralement depuis Sonata (audit du LOT 06, P0-1 à P0-3 ; DM-078).

## Décision

Les admins Sonata restent en **lecture seule stricte** (routes `list` et `show` seules, `ReadOnlySecurityHandler` limité à LIST/VIEW, méthodes d'écriture refusées sur tout `/admin` hors `/admin/cws/`). Les écritures se font **uniquement par des actions métier nommées** sous `/admin/cws/…` :

- accès : super-administrateur actif en authentification complète (`access_control`), jamais remember-me seul ;
- chaque action : POST + jeton CSRF **propre à l'action et à la cible**, page de confirmation en GET qui énonce l'effet (aucune écriture en GET) ;
- chaque action appelle **un seul service applicatif audité** (transaction, verrous, audit dans la même transaction) qui **revérifie l'acteur** ; aucun formulaire générique create/edit/delete ;
- toute cible est relue et son type vérifié (404 uniforme pour un identifiant forgé, inconnu ou d'un autre type) ; CWS ne peut toucher ni un compte CWS, ni un rattachement qui ne porte pas le rôle administrateur.

## Conséquences fonctionnelles ou techniques

- `CwsAdministrationController`, services `MunicipalityOpening`, `CommercialAccessManager`, `MunicipalityAdminInvitationService`, `CwsAccountRecovery`, méthodes `MunicipalityManager::setStatusByCws`, `UserManager::deactivateByCws/activateByCws`, `MembershipManager::removeAdministratorByCws` (le test d'architecture continue de limiter les appels de `changeStatus`, `deactivate`, `activate`, `changeRoles` à leurs gestionnaires).
- Les tests de lecture seule des admins Sonata sont conservés ; les routes `admin_cws_*` sont éprouvées par `CwsAdministrationTest`.

## Documents concernés

- [backoffice.md](../../backoffice.md), [lot-07-1-cadrage.md](../../lot-07-1-cadrage.md)

## Écarts documentaires signalés

- `docs/backoffice.md` (« lecture seule intégrale ») est mis à jour dans le même lot.
