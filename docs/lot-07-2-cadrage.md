# LOT 07.2 — Gestion autonome des équipes communales : cadrage

**Statut : PROPOSÉ, en attente de validation (une seule fois, protocole A).** Aucune implémentation, aucune migration exécutée.
Références : [lot-06-audit.md](lot-06-audit.md) (P0-4), [lot-07-1-cadrage.md](lot-07-1-cadrage.md), DM-005, DM-009, DM-036, DM-057, DM-107, DM-121, DT-005, DT-024, DT-037, DT-052, DT-053.
Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

## 1. Objectif et périmètre

Un `MUNICIPALITY_ADMIN` invite, habilite et gère ses collaborateurs **depuis l'interface communale**, sans CWS ni Sonata.

**Inclus** : écran « Équipe » ; invitation par e-mail avec choix des rôles `EVENT_MANAGER`, `INSTRUCTOR`, `MUNICIPALITY_ADMIN` ; invitations en attente / expirées / révoquées (renvoyer, révoquer) ; modification des rôles ; retrait de l'accès à la commune ; comptes existants et multi-communes ; protection du dernier administrateur ; audit ; consultation de l'historique de l'équipe par l'administrateur.

**Exclus** : gestion des comptes eux-mêmes (désactivation de compte, mot de passe : CWS, DM-121) ; rôles hors des trois existants ; contrôleurs externes (pas de compte, DM-107 / règle 6) ; notifications automatiques de changement d'habilitation (voir §9) ; import en masse.

## 2. Ce qui existe déjà (réutilisé tel quel)

| Besoin | Existant | Écart |
|---|---|---|
| Rattachement, rôles, retrait | `MembershipManager::grant / changeRoles / deactivate` (verrou de la commune, audit, observateurs de perte d'accès, **dernier administrateur protégé**, auto-modification de rôles refusée) ; politique `MembershipAccessPolicy::canManageMembers` (administrateur actif, commune ACTIVE en écriture) | `deactivate` ne refuse pas l'auto-retrait ; aucun point d'entrée UI |
| Invitation sécurisée | `MunicipalityAdminInvitation` + `MunicipalityAdminInvitationService` (issue, resend, revoke atomiques), `AdminInvitationEnrollment` (compte nouveau ou existant, attestation), pages `AdminInvitationPages`, e-mail, limiteur d'IP, `MembershipManager::enrollInvitedAdministrator` | rôle **figé** à `MUNICIPALITY_ADMIN`, acteur **limité à CWS** |
| Interface communale | `ShellContextFactory` (navigation par rôle), `MunicipalityContext::requireMembership` (refus uniforme), contrôleurs et gabarits du design system, CSRF par formulaire, exigence `IS_AUTHENTICATED_FULLY` pour les écritures (`security.yaml`) | entrée « Équipe » à créer |
| Multi-communes | un compte peut avoir un rattachement par commune ; sélecteur `app_municipal_select` | — |
| Perte d'accès | `ArchiveAccessLossObserver` (désignations d'archive révoquées explicitement au retrait de rôle) | — |

## 3. Écrans (interface communale, design system, jamais Sonata)

Menu **« Équipe »**, visible du seul `MUNICIPALITY_ADMIN` (comme « Droits des personnes »).

1. **Liste de l'équipe** `/communes/{id}/equipe` : une ligne par personne (nom, e-mail, rôles en clair, dernière connexion, état), puis « Invitations » (adresse, rôles, état En attente / Expirée / Acceptée / Révoquée, envoi réussi ou échoué). Actions par ligne : **Modifier les rôles**, **Retirer de la commune**, **Renvoyer**, **Révoquer l'invitation**. Bandeau explicatif de la règle du dernier administrateur.
2. **Inviter un collaborateur** `/equipe/inviter` : e-mail + cases à cocher des rôles, chacun décrit en une phrase non technique : *Administrateur communal* (gère l'équipe, les événements et les zones) ; *Gestionnaire d'événements* (crée et pilote les événements, invite des communes ; ne gère pas les zones) ; *Agent instructeur* (instruit les demandes). Au moins un rôle ; rôles cumulables (DM-057).
3. **Modifier les rôles** `/equipe/{membershipId}/roles` : mêmes cases ; énonce l'effet (ex. « retirer instructeur : plus d'accès aux demandes »).
4. **Confirmation de retrait** `/equipe/{membershipId}/retirer` : énonce l'effet (accès à la commune coupé immédiatement, habilitations d'archive révoquées, aucune donnée supprimée, le compte et ses autres communes ne sont pas touchés).

Toutes les écritures : POST + CSRF par action et cible, page de confirmation en GET pour les actions destructrices, authentification complète exigée.

## 4. Règles métier et habilitations

- **R1 — Qui** : seul un `MUNICIPALITY_ADMIN` actif de **cette** commune, commune ACTIVE. Commune SUSPENDED : liste en lecture seule, aucune écriture (DM-034). CWS n'a aucun droit ici (DM-005) ; il garde Sonata (DM-121) pour le secours.
- **R2 — Périmètre strict** : toute cible (rattachement, invitation) est relue et **doit appartenir à la commune de l'URL** ; sinon 404 uniforme. L'identifiant de commune de l'URL n'est jamais cru (`requireMembership`). Un administrateur de A ne voit ni n'affecte rien de B.
- **R3 — Aucune fuite entre communes** : l'écran n'affiche que les rattachements de cette commune. Un refus d'invitation ne révèle jamais l'existence ni la nature d'un compte ailleurs : pour un acteur communal, un compte CWS ou désactivé donne le **même message neutre** (« Cette adresse ne peut pas être invitée ») ; seul « déjà rattaché à la commune » est précis (c'est sa propre équipe).
- **R4 — Invitation** : jetons, usage unique, 7 jours, renvoi invalidant l'ancien lien, aucun droit avant acceptation, aucun secret dans l'audit ni l'écran, un seul lien utilisable par (commune, adresse), adresse jamais dans l'audit : **identique au LOT 07.1** (DT-053). Un compte **CWS** ne peut être invité (A3 de 07.1).
- **R5 — Compte existant** : doit se connecter avec l'adresse invitée (aucun doublon) ; s'il est déjà rattaché **actif** à la commune → refus (utiliser « Modifier les rôles ») ; rattachement **inactif** existant → réactivé avec les rôles invités (`MEMBERSHIP_ACTIVATED`, pas de doublon). Un compte appartenant à plusieurs communes garde un rattachement indépendant par commune ; retirer l'un n'affecte pas les autres.
- **R6 — Dernier administrateur** (DM-036, DM-121) : retirer le rôle administrateur, retirer le rattachement ou le désactiver est refusé s'il laisse la commune sans administrateur **effectif** (rattachement ET compte actifs) ; une invitation en attente ne compte pas ; vérifié sous le verrou de la commune en **lecture courante** (correction de 07.1). L'interface désactive l'action et explique pourquoi ; le serveur refuse de toute façon.
- **R7 — Auto-modification** : un administrateur ne modifie ni ses propres rôles, ni son propre rattachement (déjà vrai pour les rôles ; étendu au retrait). Pour partir, un autre administrateur le retire (arbitrage A2).
- **R8 — Administrateurs pairs** : un administrateur peut retirer ou rétrograder un autre administrateur (plusieurs administrateurs autorisés, DM-121), avec confirmation et audit ; jamais le dernier (arbitrage A2).
- **R9 — Effet immédiat** : les droits sont relus à chaque requête ; le retrait coupe l'accès à la requête suivante ; les habilitations fines (désignation d'archive) sont révoquées explicitement par l'observateur existant et ne reviennent pas.
- **R10 — Visibilité CWS réduite** : la fiche CWS (07.1) ne montre que les invitations et rattachements portant `MUNICIPALITY_ADMIN` ; les adresses des agents ne sont jamais exposées à CWS.
- **R11 — Débit** : l'émission d'invitations par un acteur communal est limitée (paramètre technique : 20 / heure et par commune, 10 / heure et par administrateur) pour éviter l'usage de la plateforme comme relais d'e-mails.
- **R12 — Audit** (DT-037 : aucun texte libre, aucune adresse) : une ligne par écriture, dans la même transaction. Réutilise `ADMIN_INVITATION_ISSUED / RESENT / REVOKED / ACCEPTED / EMAIL_SENT / EMAIL_FAILED` (la charge utile porte désormais les **rôles**, non personnels) et `MEMBERSHIP_CREATED / ACTIVATED / ROLES_CHANGED / DEACTIVATED`. Les administrateurs lisent l'historique de **leur** commune via `AuditLogReader` (existant), filtré sur ces actions. Aucune nouvelle valeur d'énumération n'est nécessaire.

## 5. Parcours cible

1. L'administrateur ouvre **Équipe › Inviter**, saisit l'e-mail, coche les rôles, valide.
2. L'e-mail part (lien personnel). La liste montre l'invitation « En attente » ; aucun droit n'est donné.
3. Le collaborateur ouvre le lien, crée son compte (ou se connecte avec son compte existant), atteste, et arrive sur la liste des événements de la commune avec **exactement** les rôles invités ; le menu s'adapte à ses rôles.
4. L'administrateur peut ensuite modifier ses rôles, ou le retirer ; l'effet est immédiat et tracé.
5. Invitation non reçue ou expirée : **Renvoyer** (l'ancien lien cesse de fonctionner). Mauvaise adresse : **Révoquer** puis réinviter.

## 6. Migration proposée : **M2 (une seule)**

Pour porter les rôles invités, `municipality_admin_invitation` reçoit une colonne `roles` (ajout seul ; le nom de la table est conservé pour éviter tout renommage risqué, et documenté comme « invitation à rejoindre une commune »).

```sql
ALTER TABLE municipality_admin_invitation ADD roles JSON DEFAULT NULL;
UPDATE municipality_admin_invitation SET roles = JSON_ARRAY('MUNICIPALITY_ADMIN') WHERE roles IS NULL;
ALTER TABLE municipality_admin_invitation MODIFY roles JSON NOT NULL;
-- down : ALTER TABLE municipality_admin_invitation DROP roles;
```

- **Données existantes** : les invitations déjà émises (07.1) portent toutes `MUNICIPALITY_ADMIN` : l'`UPDATE` les renseigne avant le passage en `NOT NULL`. Aucune autre table touchée.
- **Risques** : faibles (table jeune, ajout de colonne). Retour arrière : `DROP`, sans perte pour 07.1 (le rôle administrateur y est implicite).
- **Domaine** : `roles` (liste dédupliquée, non vide, rôles connus, validée à la construction) ; l'invitation à `MUNICIPALITY_ADMIN` seul reste le cas CWS.
- **Exécution** : bases locales `*_test` puis développement, **après sauvegarde vérifiée**, après validation de ce cadrage ; jamais en production sans accord. Aucune autre migration (audit en `VARCHAR`, aucun nouvel enum).

## 7. Composants à créer ou adapter

- `MunicipalityAdminInvitationService` : accepte des rôles et un acteur **administrateur communal** en plus du super-administrateur (règles différenciées : message neutre R3, limiteur R11, commune ACTIVE exigée, périmètre R2).
- `MembershipManager::enrollInvitedAdministrator` → généralisé en `enrollInvited(user, municipality, roles, closure)` (rôles de l'invitation, jamais d'autres ; garde-fous inchangés : compte actif, non CWS, pas déjà rattaché actif, verrou). Le chemin CWS de 07.1 reste valide (rôle administrateur).
- `MembershipManager::deactivate` : refus de l'auto-retrait (R7) ; nouveaux points d'entrée « côté commune » **séparés** de ceux de CWS pour rendre explicite la règle de périmètre.
- `TeamController` (module Municipality/UI), gabarits `templates/municipal/team/*`, entrée de menu, règles `security.yaml` (`IS_AUTHENTICATED_FULLY` sur `/communes/…/equipe/…`), e-mail d'invitation d'équipe (nom de la commune, nom de l'invitant, rôles en clair).
- Lecture : `TeamReader` (rattachements et invitations de la commune, états, dernier administrateur) ; aucune lecture hors commune.

## 8. Tests majeurs

**Unitaires** : validation des rôles de l'invitation ; états ; règles R5 à R8.
**Intégration** : invitation avec chaque combinaison de rôles ; compte nouveau / existant / multi-communes / rattachement inactif réactivé ; refus (déjà rattaché, CWS, désactivé) avec message neutre ; renvoi, révocation, expiration ; modification des rôles avec révocation des habilitations d'archive ; retrait ; **dernier administrateur** (retrait de rôle, retrait, désactivation de compte) ; auto-modification refusée ; audit sans adresse ni jeton, avec rôles.
**Fonctionnels / sécurité** : accès réservé à l'administrateur actif de la commune (anonyme, remember-me seul, gestionnaire, instructeur, administrateur d'une **autre** commune, super-administrateur, compte désactivé : refus) ; commune SUSPENDED en lecture seule ; CSRF par action et cible ; GET n'écrit jamais ; **identifiants forgés** (rattachement ou invitation d'une autre commune, UUID inconnu, action inconnue : 404 uniforme) ; aucune fuite entre communes (listes, messages d'erreur, comptes) ; la fiche CWS ne montre pas les invitations d'agents.
**Concurrence** (processus parallèles) : deux administrateurs qui se retirent mutuellement ; retrait vs rétrogradation du dernier administrateur ; acceptation vs révocation de la même invitation ; deux invitations simultanées pour la même adresse ; changement de rôles vs retrait du même rattachement ; acceptation pendant la suspension de la commune.
**Parcours complet** (sans fixture contournant les maillons) : commune ouverte par CWS (07.1) → administrateur actif → invite un instructeur et un gestionnaire → ils acceptent → l'instructeur voit « Demandes », le gestionnaire crée un événement, aucun n'accède à « Équipe » → l'administrateur modifie puis retire l'instructeur → accès coupé immédiatement.
**Qualité** : `bin/qa full`, `doctrine:schema:validate`, `verify-docs.sh`.

## 9. Arbitrages indispensables

| # | Question | Recommandation (appliquée si validée telle quelle) |
|---|---|---|
| **A1** | Migration **M2** (colonne `roles` sur `municipality_admin_invitation`, table conservée) ? | **Oui.** |
| **A2** | Un administrateur peut-il retirer ou rétrograder **un autre administrateur** (jamais le dernier), et jamais lui-même (il demande à un pair) ? | **Oui** (cohérent avec « plusieurs administrateurs », DM-121 ; protection du dernier administrateur inchangée). |
| **A3** | Informer par e-mail la personne dont les habilitations changent ou sont retirées ? | **Non en V1** : audit et effet immédiat seulement ; à reconsidérer avec le module de notifications. Une notification d'invitation, elle, est envoyée. |

Hors arbitrage (paramètres techniques modifiables) : validité d'invitation 7 jours ; limites d'émission R11 ; libellés des rôles.

## 10. Découpage d'exécution (dans le mandat, commits locaux)

1. Domaine et services : M2, rôles dans l'invitation, `enrollInvited`, règles R5 à R8, limiteur, tests d'intégration et de concurrence.
2. Pages d'acceptation : rôles affichés, accès après acceptation, e-mail d'équipe.
3. Interface « Équipe » : liste, invitation, rôles, retrait, confirmations, menu, `security.yaml`, tests fonctionnels et de sécurité.
4. Parcours complet, documentation (décision DM/DT du lot, `docs/security.md`, runbook), `bin/qa full`.

**Critères d'acceptation** : un administrateur constitue et gère son équipe sans SQL, console ni Sonata ; aucun accès entre communes ; dernier administrateur toujours protégé, y compris sous concurrence ; audit complet sans adresse ni jeton ; `bin/qa full`, `schema:validate` et `verify-docs.sh` au vert.

**Risques résiduels** : SMTP de production requis pour les invitations ; habilitation officielle du représentant non vérifiable (attestation) ; pas de double authentification ; les comptes eux-mêmes (mot de passe oublié) restent hors périmètre communal ; revue ergonomique sur navigateur et avec des agents réels à prévoir (UX §6 de l'audit).
