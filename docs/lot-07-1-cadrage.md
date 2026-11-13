# LOT 07.1 — Administration CWS (Sonata) : cadrage

**Statut : VALIDÉ le 13 novembre 2026 (A1 à A4, M1, invitations 7 jours configurables) ; RÉALISÉ en local, non poussé (voir §14).**
Références : [lot-06-audit.md](lot-06-audit.md) (P0-1 à P0-3), DM-005, DM-031/032/034/035/036, DM-065 (hors lot), DM-070/073/076/078/079, DT-018, DT-029, DT-030, `docs/backoffice.md`.
Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

**Orientations déjà validées (13 novembre 2026)** : Sonata réservé à CWS ; les communes utilisent l'interface métier ; accès commerciaux manuels, sans paiement ni facturation ; référentiels figés en V1 ; contrôle QR public traité au LOT 07.3.

**Vérification Doctrine (13 novembre 2026, conteneur `saas_civipass_php-fpm`)** : mapping OK, **schéma synchronisé avec la base locale**, 23 migrations exécutées, dernière `Version20261112180000`. Aucune anomalie.

---

## 1. Objectif et périmètre

**Objectif** : CWS ouvre une commune pilote **entièrement depuis Sonata**, sans SQL ni script PHP.

**Inclus**
1. Commune : ouvrir (depuis le référentiel national), consulter, corriger le contact, activer/suspendre.
2. Accès commerciaux : attribuer, révoquer, renouveler, consulter l'historique.
3. Administrateur communal : inviter par e-mail, renvoyer, révoquer l'invitation, remplacer, récupérer l'accès, protéger le dernier administrateur.
4. Comptes et appartenances : consulter, rattacher/retirer un **administrateur** communal, désactiver/réactiver un compte.
5. Traçabilité : audit de chaque écriture ; panneau d'historique administratif en lecture.

**Exclus** (renvoyés) : gestion d'équipe communale (LOT 07.2) ; contrôle QR (07.3) ; paiement, facturation, abonnements (hors V1) ; administration des référentiels (figés) ; clôture forcée DM-065 et impersonation DM-061 (permissions DT-029 non attribuées) ; création/retrait de super-administrateur (reste console `app:super-admin:create`) ; toute lecture de dossier opérationnel.

---

## 2. Composants existants réutilisables

| Besoin | Existant | Écart |
|---|---|---|
| Statut, contact de commune | `MunicipalityManager::changeStatus`, `updateContact` (verrou, audit) | `create` **sans audit** et sans lien au référentiel national → remplacé par l'ouverture depuis le référentiel |
| Commune depuis le référentiel | `NationalCommune`, `app:communes:sync`, `MunicipalityRegistrar::registerFromReferential` (idempotent, nom et INSEE jamais saisis) | acteur et origine d'audit à paramétrer (origine `CWS_BACKOFFICE`) |
| Droits d'utilisation | `MunicipalityCommercialAccess` (grant/revoke en domaine, `revoked_by`), `CommercialAccessEntitlementSource`, `EventCreationPolicy`, actions d'audit `COMMERCIAL_ACCESS_GRANTED/REVOKED` | **aucun service applicatif** : à créer (transaction, verrou, audit) |
| Rattachement, rôles, désactivation | `MembershipManager::grant/changeRoles/deactivate`, `assertNotLastAdmin` (verrou commune), `LastMunicipalityAdminGuard` | `enrollFirstAdministrator` refuse une commune déjà administrée : insuffisant pour remplacement/récupération |
| Comptes | `UserManager::createUser/deactivate/activate` + garde dernier administrateur | — |
| Invitation sécurisée | `InvitationToken` (empreinte seule), `InvitationLinkResolver`, `AdministratorEnrollment` (étapes Signup / LoginRequired / Confirm / WrongAccount, attestation, audit), `MailInvitationNotifier`, `InvitationDeliveryRecorder`, limiteur `invitation_enroll_ip`, `InvitationLinkController`, templates `onboarding/` | `InvitationLink` est **lié à une participation d'événement** (FK non nulle) : inutilisable tel quel (voir M1) |
| Back-office | `ReadOnlyAdmin`, `ReadOnlySecurityHandler`, pare-feu `/admin` (super-admin, authentification complète) | écritures à introduire par **actions dédiées** (§3) |
| Mots de passe oubliés | `PasswordResetService` (30 min) | inchangé ; sert aussi à la récupération d'un administrateur déjà activé |
| Lecture d'audit | `AuditLogReader` (périmètre) | lecture CWS limitée aux actions administratives (liste blanche d'actions) |

---

## 3. Écrans Sonata

Principe (arbitrage A1) : **pas de formulaire générique create/edit/delete Sonata** (les routes génériques restent absentes, les tests de lecture seule sont conservés). Chaque écriture est une **action nommée**, POST + jeton CSRF, page de confirmation qui énonce l'effet, appelant **un seul service applicatif audité**. Le gestionnaire de sécurité n'accorde que des permissions nommées (`CWS_COMMUNE_OPEN`, `…_STATUS`, `…_ACCESS`, `…_ADMIN`, `…_ACCOUNT`), toutes réservées à `ROLE_SUPER_ADMIN` en authentification complète (pas remember-me).

**Communes** (`MunicipalityAdmin`)
- *Liste* : ajouter colonnes « Droit de créer des événements » (valide jusqu'au… / aucun / expiré) et « Administrateurs actifs » (n ou ⚠ 0).
- *Action « Ouvrir une commune »* : recherche dans le référentiel national (communes actives non encore ouvertes ou inscrites sans administrateur) ; ouvre la fiche si la commune existe déjà (inscrite par invitation) plutôt que de créer un doublon.
- *Fiche* : bloc « Situation » (statut, droit de créer, continuité des événements ouverts), blocs Accès commerciaux, Administrateurs/invitations, Historique administratif (lecture).
- *Actions* : modifier le contact (réutilise `updateContact` avec acteur super-admin, déjà autorisé par `canEditContact`) ; suspendre / réactiver / désactiver (`changeStatus`) avec confirmation expliquant DM-034/035 ; la désactivation n'est pas proposée tant qu'un événement est ouvert (à confirmer par test, sinon avertissement).

**Accès commerciaux** (sous-bloc de la fiche commune, pas d'admin autonome)
- *Attribuer* : date de début (par défaut maintenant), date d'expiration (facultative, DM-076), motif obligatoire (≤ 1000 car.).
- *Renouveler* : crée un **nouvel** accès à partir de la date d'expiration courante (historique conservé).
- *Révoquer* : motif obligatoire ; définitif (DM-079). Une « suspension » du droit = révocation, puis nouvelle attribution (arbitrage A4).
- Texte permanent : « Cet accès n'autorise que la **création de nouveaux événements**. Les événements ouverts, leurs demandes et laissez-passer continuent (DM-073). »

**Administrateurs communaux** (sous-bloc de la fiche commune)
- *Inviter un administrateur* : e-mail + prénom/nom indicatifs ; envoi du lien ; statut d'envoi affiché.
- *Invitations* : en attente / expirée / utilisée / révoquée ; actions **Renvoyer** (révoque l'ancien lien, en émet un nouveau) et **Révoquer**.
- *Administrateurs actifs* : liste (prénom, nom, e-mail, dernière connexion) ; action **Retirer** (refusée pour le dernier administrateur d'une commune ACTIVE).
- *Récupération* : « Renvoyer un lien de réinitialisation » (compte actif) ou « Inviter un nouvel administrateur » (compte perdu).

**Comptes** (`UserAdmin`) : fiche enrichie (appartenances, communes administrées) ; actions **Désactiver** / **Réactiver** (garde dernier administrateur) ; aucune édition de rôle CWS ni de mot de passe.

**Appartenances** (`MembershipAdmin`) : consultation ; aucune action propre (les rattachements passent par la fiche commune).

---

## 4. Règles métier et habilitations

**R1 — Habilitation** : toute écriture exige un super-administrateur actif, authentification complète ; vérifiée **côté service** (pas seulement par la route). Aucune permission CWS DT-029 (impersonation, clôture forcée) n'est utilisée ni attribuée.

**R2 — Séparation (DM-005, A3)** : CWS ne gère que le rôle `MUNICIPALITY_ADMIN`. Il n'attribue ni `EVENT_MANAGER` ni `INSTRUCTOR`, ne lit aucun événement, demande, laissez-passer ni archive. Un compte **super-administrateur ne peut être ni invité ni rattaché** comme administrateur communal (comptes distincts) ; un super-administrateur ne peut pas se rattacher lui-même.

**R3 — Droit de créer des événements (DM-070/073/076)** : inchangé, `EventCreationPolicy` : commune ACTIVE **et** accès commercial valide. Expiration ou révocation : plus de **nouvel** événement ; tout le reste continue. Commune SUSPENDED : lecture seule, ni création ni nouvelle participation (DM-034/035). L'écran affiche ces deux états séparément.

**R4 — Un accès commercial** : début ≤ fin, motif obligatoire, historique jamais supprimé ; attribuer alors qu'un accès valide existe est refusé (renouvellement explicite à partir de sa fin) pour éviter les chevauchements ambigus.

**R5 — Ouverture** : nom et code INSEE viennent du référentiel (jamais saisis) ; idempotent ; doublon impossible (unicité INSEE, course gérée comme `MunicipalityRegistrar`).

**R6 — Invitation d'administrateur**
- Jeton opaque ≥ 256 bits, **seule l'empreinte** stockée, jamais loggé ni audité en clair ; e-mail du destinataire absent de l'audit.
- Validité **7 jours** (paramètre technique, non décision) ; un lien expiré se **renvoie** ; au plus **un lien utilisable** par (commune, e-mail) : renvoyer ou réinviter révoque les précédents sous le verrou de la commune.
- Usage unique, consommé **dans la même transaction** que le rattachement ; relu sous verrou.
- Le lien ne donne par lui-même aucun droit : parcours existant (nouveau compte → nom, prénom, mot de passe ≥ 12 car. ; compte existant → connexion obligatoire avec **l'adresse invitée**), attestation d'habilitation conservée dans l'audit (le contrôle de l'e-mail prouve la boîte, pas la qualité de représentant : mention conservée).
- Commune **ACTIVE ou SUSPENDED** à l'acceptation (INACTIVE : refus uniforme).
- Limiteur de débit par IP (existant) ; réponse unique pour jeton inconnu/mal formé.
- **Aucun droit n'est donné avant acceptation** : pas de compte ni de rattachement « fantôme » ; donc pas d'administrateur effectif compté à tort.

**R7 — Plusieurs administrateurs** : autorisés sans plafond fonctionnel. Le **premier** et les suivants suivent le même chemin (invitation CWS ou, plus tard, invitation par l'administrateur communal, LOT 07.2). Nouveau service d'enrôlement `enrollInvitedAdministrator` : contrairement à `enrollFirstAdministrator`, n'exige pas l'absence d'administrateur (l'autorité est l'invitation émise par CWS) ; conserve les garde-fous (rôle admin seul, compte actif, pas de doublon actif, verrou commune).

**R8 — Protection du dernier administrateur (DM-036)** : retrait de rôle, désactivation de rattachement ou de compte refusés s'ils laissent une commune **ACTIVE** sans administrateur effectif (verrou commune, ordre stable DT-024) ; vaut pour CWS comme pour la commune. Le remplacement se fait dans l'ordre **inviter → accepté → retirer l'ancien**.

**R9 — Suspension/désactivation** : suspendre une commune ne supprime ni rôles ni accès commerciaux ; la réactivation rend les droits tels quels. Désactiver un compte : effet immédiat (UserChecker), refusé s'il est le dernier administrateur d'une commune active, journalisé. Aucune suppression de données (règle 8).

**R10 — Audit** : une ligne `audit_log` par écriture, **dans la même transaction**, sans texte libre ni donnée personnelle (DT-037) : acteur, commune, anciennes/nouvelles valeurs de statut ; l'e-mail n'est jamais écrit. Nouvelles actions (colonne `VARCHAR(60)`, **aucune migration**) : `MUNICIPALITY_OPENED_BY_CWS`, `COMMERCIAL_ACCESS_RENEWED`, `ADMIN_INVITATION_ISSUED`, `ADMIN_INVITATION_RESENT`, `ADMIN_INVITATION_REVOKED`, `ADMIN_INVITATION_ACCEPTED` ; réutilisées : `COMMERCIAL_ACCESS_GRANTED/REVOKED`, `MUNICIPALITY_STATUS_CHANGED`, `MUNICIPALITY_CONTACT_CHANGED`, `MEMBERSHIP_*`, `USER_ACTIVATION_CHANGED`.

---

## 5. Parcours d'ouverture d'une commune pilote (cible)

1. **Préalable** : `app:communes:sync` exécuté (référentiel présent) ; au moins un super-administrateur actif (console, existant).
2. Sonata › Communes › **Ouvrir une commune** : recherche par nom ou INSEE, confirmation ; statut ACTIVE ; saisie du contact officiel (facultatif).
3. Fiche › **Attribuer un accès commercial** (début, fin, motif).
4. Fiche › **Inviter un administrateur** (e-mail). L'e-mail part ; le statut s'affiche (envoyé / échec avec Renvoyer).
5. L'administrateur ouvre le lien → crée son compte (ou se connecte) → atteste → devient `MUNICIPALITY_ADMIN`, arrive sur l'interface communale. CWS voit l'invitation « utilisée » et l'administrateur actif.
6. **Contrôle de fin d'ouverture** (fiche) : commune ACTIVE ✔ · droit de créer des événements ✔ · ≥ 1 administrateur actif ✔ · adresse d'envoi des e-mails configurée (indicateur d'exploitation, lecture seule, sans valeur sensible).
7. Suite (hors lot) : l'administrateur invite ses agents (LOT 07.2).

**Dépendance d'exploitation (pas du code)** : un SMTP réel est nécessaire pour l'invitation (P1-2 de l'audit). Le lien n'est **jamais affiché** à CWS ; l'échec d'envoi se traite par « Renvoyer ».

---

## 6. Suspension et récupération d'accès

| Situation | Action CWS (Sonata) | Garde-fou |
|---|---|---|
| Commune à suspendre (sécurité) | Fiche › Suspendre (confirmation, effet décrit) | lecture seule pour la commune, pas de perte de données |
| Réactivation | Fiche › Réactiver | — |
| Droit commercial échu | Renouveler (nouvel accès) | événements ouverts non affectés |
| Droit à retirer | Révoquer (motif) | idem |
| Invitation expirée / non reçue | Renvoyer (ancien lien révoqué) | un seul lien utilisable |
| Administrateur a oublié son mot de passe | il utilise « mot de passe oublié » ; sinon CWS renvoie un lien de réinitialisation | réponse sans fuite d'existence du compte |
| Administrateur injoignable / parti | Inviter un nouvel administrateur ; après acceptation, Retirer l'ancien | dernier administrateur protégé |
| Compte compromis | Désactiver le compte (sessions et remember-me invalidés à la requête suivante) | refusé si dernier administrateur : inviter d'abord le remplaçant |
| Commune sans administrateur effectif (ex. départ) | Inviter un administrateur | commune visible avec ⚠ dans la liste |
| Aucun super-administrateur actif | procédure console existante `app:super-admin:create` (documentée dans le runbook incident) | hors Sonata par nature |

---

## 7. Migrations proposées

**M1 — Une seule : table `municipality_admin_invitation`** (justification : `onboarding_invitation_link` exige une participation d'événement ; la rendre facultative affaiblirait un invariant éprouvé et mélangerait deux cycles de vie. Une table dédiée est plus simple et isole le risque.)

Entité `App\Onboarding\Domain\MunicipalityAdminInvitation` (module Onboarding). Attributs : `id` (UUID v7), `municipality` (FK), `recipientEmail` (≤ 180), `tokenHash` (CHAR(64), unique, binaire), `createdBy` (FK user, super-admin), `createdAt`, `expiresAt`, `consumedAt`, `consumedBy` (FK user, null), `revokedAt`, `revokedBy` (FK user, null), `parent` (invitation remplacée, null), `deliveryStatus`, `deliveryAttemptedAt`. Aucune suppression physique (FK RESTRICT).

```sql
CREATE TABLE municipality_admin_invitation (
  id BINARY(16) NOT NULL,
  municipality_id BINARY(16) NOT NULL,
  recipient_email VARCHAR(180) NOT NULL,
  token_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`,
  created_by BINARY(16) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME DEFAULT NULL,
  consumed_by BINARY(16) DEFAULT NULL,
  revoked_at DATETIME DEFAULT NULL,
  revoked_by BINARY(16) DEFAULT NULL,
  parent_id BINARY(16) DEFAULT NULL,
  delivery_status VARCHAR(10) NOT NULL,
  delivery_attempted_at DATETIME DEFAULT NULL,
  UNIQUE INDEX uniq_admin_invitation_token_hash (token_hash),
  INDEX idx_admin_invitation_municipality (municipality_id, created_at),
  PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;
-- + 5 clés étrangères ON DELETE RESTRICT (municipality, created_by, consumed_by, revoked_by, parent)
```

- **Données existantes** : aucune (table neuve) ; **aucune modification** des tables existantes ; `down()` : suppression des clés puis de la table (sans perte pour l'existant).
- **Risques** : faibles (ajout seul). Les dates sont en UTC (type surchargé) ; le type Doctrine garantit la synchro (`doctrine:schema:validate`).
- **Exécution** : base `*_test` puis développement, **après sauvegarde vérifiée**, sur la foi de ce cadrage une fois validé ; jamais en production sans accord.
- Valeurs d'enum d'audit : aucune migration (colonnes `VARCHAR`). Pas de modification de `municipality_commercial_access` (colonnes suffisantes ; pas d'état « suspendu »).

---

## 8. Tests majeurs

**Unitaires** : invariants de l'invitation (expiration, consommation unique, révocation, remplacement) ; règles R2/R4/R8 ; libellés d'état.
**Intégration** : services (ouverture idempotente, accès commercial attribuer/renouveler/révoquer avec audit transactionnel et rollback sur échec d'audit, enrôlement d'un administrateur, remplacement, dernier administrateur) ; EventCreationPolicy avec accès expiré/révoqué/commune suspendue (continuité des événements ouverts prouvée : demandes, instruction, droits, clôture restent possibles) ; audit sans donnée personnelle.
**Fonctionnels (Sonata + HTTP)** : seul un super-administrateur en authentification complète écrit (anonyme, agent, administrateur communal, remember-me seul, compte désactivé : refus) ; CSRF obligatoire sur chaque action ; GET n'écrit jamais ; **identifiants forgés** (commune, invitation, appartenance, compte) ; routes génériques create/edit/delete toujours absentes (les tests existants de lecture seule sont conservés et complétés) ; parcours complet **depuis une commune vierge** jusqu'à la connexion de l'administrateur, sans fixture contournant les maillons (premier scénario de recette P1-1) ; invitation expirée, utilisée, révoquée, remplacée, jeton inconnu (réponse unique) ; compte existant avec autre adresse ; super-administrateur refusé ; e-mail jamais présent dans l'audit, les journaux ni les pages.
**Concurrence** (processus parallèles, comme `enroll-administrator.php`) : deux acceptations du même lien ; acceptation vs révocation/renvoi ; deux invitations simultanées ; retrait de l'ancien vs désactivation de l'autre administrateur (le dernier ne disparaît jamais) ; attribution simultanée de deux accès ; ouverture simultanée de la même commune.
**Qualité** : `bin/qa full`, `doctrine:schema:validate`, `verify-docs.sh`.

---

## 9. Arbitrages indispensables

| # | Question | Recommandation (appliquée si validée telle quelle) |
|---|---|---|
| **A1** | Amender **DT-018** (Sonata strictement en lecture seule) pour autoriser des **actions d'écriture nommées, auditées, CSRF**, sans formulaires génériques ? C'est nécessaire à l'objectif (« depuis Sonata ») et DM-078 le prévoit. | **Oui.** DT-018 remplacée par une nouvelle décision ; tests de lecture seule maintenus pour tout le reste. |
| **A2** | Table dédiée **M1** plutôt que réutiliser `onboarding_invitation_link` ? | **Oui, M1.** |
| **A3** | CWS limité au rôle `MUNICIPALITY_ADMIN` ; super-administrateur jamais administrateur communal ni auto-rattaché ? | **Oui** (cohérent avec DM-005). |
| **A4** | « Suspension » d'un accès commercial = révocation puis nouvelle attribution (pas d'état « en pause », aucune migration) ; la suspension d'une **commune** reste celle de DM-034 ? | **Oui.** |

Non soumis à arbitrage (paramètres techniques, modifiables) : validité d'invitation 7 jours ; un seul lien utilisable par (commune, e-mail) ; accès commercial à expiration facultative (DM-076).

---

## 10. Découpage d'exécution (dans le mandat, commits locaux)

1. Services : accès commerciaux, ouverture, enrôlement/remplacement + M1 + tests de domaine/intégration.
2. Invitation : e-mail, page d'acceptation (réutilisation des templates), limiteur, tests fonctionnels et de concurrence.
3. Sonata : actions communes, accès, administrateurs, comptes ; permissions nommées ; tests d'accès.
4. Parcours de recette bout en bout, documentation (`backoffice.md`, runbook de récupération d'accès, nouvelle décision DT remplaçant DT-018, décisions DM/DT du lot), `bin/qa full`.

**Critères d'acceptation** : parcours §5 réalisé sans SQL ni console (hors préalable) ; aucun dossier opérationnel lisible par CWS ; dernier administrateur toujours protégé ; audit complet sans donnée personnelle ; `bin/qa full`, `schema:validate` et `verify-docs.sh` au vert.

**Hors lot / risques résiduels** : SMTP de production, lien jamais montré à CWS (renvoi seulement), habilitation officielle du représentant non vérifiable (attestation uniquement), double authentification CWS (P2), réexamen des sessions actives après désactivation (effet à la requête suivante).


---

## 14. Réalisation (13 novembre 2026)

Livré conformément au cadrage : actions nommées `/admin/cws/*` (DT-052), services `MunicipalityOpening`, `CommercialAccessManager`, `MunicipalityAdminInvitationService`, `AdminInvitationEnrollment`, `CwsAccountRecovery`, M1 `Version20261113160211` exécutée sur les bases locales `civipass` et `civipass_test` (sauvegarde de `civipass` vérifiée avant : 46 tables, dump complet), décisions DM-121, DT-052, DT-053, runbook [recuperation-acces.md](runbooks/recuperation-acces.md).

Écarts assumés par rapport au cadrage :
- **Motif de révocation** : non conservé (aucune colonne ; `revoked_by` et la date le sont, DM-079) — pas de migration supplémentaire.
- **Correction de concurrence** dans un composant existant : `countEffectiveAdmins` lit en lecture courante (`FOR SHARE`) ; sans cela deux retraits/désactivations simultanés pouvaient laisser une commune sans administrateur (DT-053).
- Les pages d'acceptation réutilisent les routes `/invitation*` existantes (masquage nginx inchangé) au lieu de routes nouvelles.
- Pas de limitation de débit sur l'émission d'invitations (acteur : super-administrateur authentifié) ; à reconsidérer avec une double authentification CWS (P2).
