# LOT 05.6.3 — Archives sécurisées et gel de conservation : cadrage (12 novembre 2026)

**Statut : CADRAGE VALIDÉ le 12 novembre 2026 (A1 : oui ; A2 et A3 : valeurs par défaut) ; sous-lot IMPLÉMENTÉ le 12 novembre 2026 (voir §10).** Migration unique `Version20261112085714` (M2, M3, M4 et M5) exécutée sur `civipass_test` et `civipass`. Références : [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §6, §13 ; [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) ; [rgpd-politique-conservation.md](rgpd-politique-conservation.md) ; [lot-05-6-2-plan-implementation.md](lot-05-6-2-plan-implementation.md) §11 et §12.

## 1. Objectif et périmètre

Donner accès aux données d'un événement **clos**, **par dossier communal**, en **lecture seule**, aux seuls responsables habilités (`MUNICIPALITY_ADMIN` de la commune, `EVENT_MANAGER` explicitement désigné pour cet événement et cette commune), avec **motif obligatoire** et **journal de chaque consultation** ; permettre le **gel de conservation** d'un dossier communal ; rattacher la ventilation statistique à une habilitation **distincte** (L5-36).

**Inclus** : (A) espace d'archives ; (B) désignation des `EVENT_MANAGER` (option B) ; (C) gel de conservation ; (D) habilitation de coordination statistique (selon arbitrage A1).
**Exclus** : purge et suppression (05.6.4), droits des personnes (05.6.6), rapports de comptes et dormance (05.6.5), exploitation et sauvegardes (05.6.7), toute modification de l'exploitation opérationnelle (inchangée : fermée à la clôture).

## 2. Règles et décisions applicables (non rediscutées)

Règle 8 (conservation justifiée puis suppression contrôlée) ; DM-120 D1 à D5 ; option B (désignation par événement et commune, `MUNICIPALITY_ADMIN` désigne) ; D3 (**unité = dossier communal** ; un gel de `A` n'ouvre rien de `B`) ; archive **identifiante**, jamais « anonymisée » ; `INSTRUCTOR` exclu ; **aucun droit communal implicite pour CWS** ; CLAUDE.md règles 4 et 5 (isolation, identifiants forgés) ; DT-037 (aucune valeur personnelle dans l'audit). La durée d'archive (hypothèse **12 mois**) et les motifs de gel restent **à valider** par le DPO et les archives avant production : paramètres, jamais constantes.

## 3. Pré-inspection (code au 12 novembre 2026)

| # | Constat | Conséquence |
|---|---|---|
| P1 | `MunicipalityContext::requireMembership` / `MembershipAccessPolicy::effectiveMembership` : utilisateur actif, commune active ou suspendue (lecture), membership actif. Rôles cumulables (`MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR`). | Réutilisés **à chaque accès** ; une commune suspendue garde la **lecture** (cohérent avec l'existant) |
| P2 | Perte d'appartenance ou de rôle : `MembershipManager::changeRoles` et `::deactivate` ; compte : `UserManager::deactivate`. Réactiver un membership ne touche à rien d'autre. | Crochets de **révocation explicite** des désignations **et** contrôle **à l'accès** (défense en profondeur) ; **jamais de réactivation automatique** |
| P3 | `EventParticipation` (événement × commune, `activated_at`), `PermitRequestMunicipality` (dossier communal : statut, décision, zones propres). Aucune notion de gel ni de désignation. | Tables dédiées (M2, M3) ; la désignation s'attache à la **participation** |
| P4 | Barrière de clôture (05.6.2) : le préfixe `/communes/{m}/archives/…` est **réservé** (hors barrière) ; les espaces `App\Archive` ne doivent **jamais** appeler `EventOperationalGuard` (test d'architecture existant). | L'archive est **son propre périmètre** : sa politique d'accès est la **seule** porte ; un test de matrice impose que **toute** route `/archives/` passe par elle |
| P5 | Justificatifs : fichiers privés (`var/storage`), servis uniquement par `MunicipalRequestAttachmentController` (**fermé** sur événement clos). Les pièces sont **partagées** entre les communes concernées par la demande. | Téléchargement **archive** par un contrôleur distinct, journalisé, dans le périmètre du dossier communal consulté |
| P6 | Données partagées entre communes : identité, contact, adresse, plaques, justificatifs, laissez-passer ; propres à la commune : ligne de dossier, décision, autorisation, révisions (lignes **neutres** pour les autres communes). | Le dossier archivé montre le **partagé + le propre** ; les autres communes n'apparaissent que sous forme **neutre** |
| P7 | Audit : `AuditTrail`/`AuditEvent` (sans valeur personnelle, DT-037) ; `AuditLogReader` non branché. | Audit pour les **actes de gestion** (désignation, révocation, gel, revue, levée) ; le **journal de consultation** exige un commentaire court : table dédiée (M4), pas l'audit |
| P8 | Statistiques (05.6.2) : ventilation selon `ParticipantListVisibility::Full` (rôles de gestion de l'organisatrice). | Voir §4.7 et arbitrage **A1** |
| P9 | Sonata : aucune entité de dossier ; lecture seule. | Aucune exposition ; test de garde-fou conservé |
| P10 | CWS (`ROLE_SUPER_ADMIN`) sans appartenance : aucun droit communal. | Accès archive **refusé** (test) |

**Risques de contournement et parades** : (1) route `/archives/` oubliée → test de matrice sur tout le routeur ; (2) identifiant forgé (commune, dossier, pièce) → dossier cherché par sa **portion communale** après habilitation, refus **uniforme** ; (3) lecture par un chemin opérationnel → déjà fermé par la barrière et les gardes métier ; (4) désignation périmée (rôle retiré, compte désactivé, base modifiée à la main) → **recontrôle à chaque accès** ; (5) rejeu d'une consultation expirée → lien de consultation **à durée limitée**, lié à l'utilisateur, recontrôlé ; (6) téléchargement sans journal → journal écrit **avant** l'envoi du fichier, échec = **refus** (fail-closed) ; (7) fuite entre communes par la liste → liste **sans donnée personnelle** (référence, catégorie, statut, date) ; (8) gel utilisé pour ouvrir l'accès d'une autre commune → le gel est **local** au dossier communal et n'accorde **aucun** accès.

## 4. Conception

### 4.1 Politique d'accès (`ArchiveAccessPolicy`, seule porte)
Un accès au dossier communal `(M, D)` est accordé **si et seulement si**, vérifié à chaque requête, côté serveur : l'utilisateur est **actif** ; il a un **membership effectif** (lecture) dans `M` ; il a le rôle `MUNICIPALITY_ADMIN` dans `M`, **ou** le rôle `EVENT_MANAGER` dans `M` **et** une **désignation active** sur la participation `(événement de D, M)` ; l'événement est **clos** ; l'archive de `D` est **ouverte** (voir 4.6) ; `D` appartient à `M` (portion communale). Sinon : refus **uniforme** (404). Aucun accès pour `INSTRUCTOR` seul, pour CWS ni pour une autre commune ; l'accès au bilan statistique **ne donne aucun accès** aux dossiers, et inversement.

### 4.2 Espace d'archives (`/communes/{m}/archives/…`, lecture seule)
- **Liste** des événements clos de la commune puis des dossiers communaux d'un événement : **référence, catégorie, statut, date de décision** ; aucun nom, aucune plaque, aucun justificatif.
- **Ouverture d'un dossier** : formulaire de **motif** (code obligatoire parmi : `LITIGE`, `RECOURS`, `DEMANDE_PERSONNE`, `CONTROLE_INTERNE`, `OBLIGATION_LEGALE`, `AUTRE` ; commentaire court ≤ 200 caractères, **obligatoire pour `AUTRE`**) → écriture du **journal** puis redirection vers `/archives/consultations/{id}` : lien **lié à l'utilisateur et au dossier**, **valable 30 minutes** (paramètre), **recontrôlé** à chaque affichage (même politique §4.1).
- **Dossier** : données communes (identité, contact, adresse, plaques, justificatifs listés) + données de **sa** commune (décision, autorisation, révisions) ; lignes **neutres** pour les autres communes ; aucune action, aucun formulaire d'écriture ; `Cache-Control: no-store`.
- **Justificatif** : `/archives/consultations/{id}/justificatifs/{pieceId}` : pièce vérifiée **appartenant à la demande du dossier**, entrée de journal `PIECE_TELECHARGEE`, puis envoi.
- **Aucune réouverture** de droits : aucun lien vers les écrans opérationnels, aucune action métier ; les routes opérationnelles restent en 410.
- **Journal consultable** par le `MUNICIPALITY_ADMIN` de la commune (consultations **de sa commune** : consultant, date, dossier, action, motif).

### 4.3 Journal des consultations (M4)
Une ligne par ouverture de dossier et par téléchargement : dossier communal, consultant, date UTC, action, code de motif, commentaire (≤ 200), pièce éventuelle. **Écrit dans la transaction qui précède l'envoi** ; un échec d'écriture **refuse** l'accès. Aucune modification ni suppression par l'application (purge au 05.6.4 avec le dossier, dépendance).

### 4.4 Désignation des `EVENT_MANAGER` (option B)
1. **Qui** : seul un `MUNICIPALITY_ADMIN` **de la commune** désigne ou révoque (droit relu sous verrou).
2. **Cible** : utilisateur **actif**, membership **effectif** dans la commune, rôle `EVENT_MANAGER` ; participation `(événement, commune)` **réellement activée** (`activated_at`).
3. **Portée** : une désignation = **un événement et une commune** ; aucune extension automatique à d'autres événements ou communes ; **une seule désignation active** par (participation, utilisateur).
4. **Quand** : avant ou après la clôture ; aucune désignation par défaut.
5. **Révocation effective** : par l'administrateur, **ou automatique** (cause tracée) si l'utilisateur perd le rôle `EVENT_MANAGER`, son membership ou son compte (crochets `MembershipManager`/`UserManager`) ; **recontrôle à chaque accès** même si le crochet a été contourné.
6. **Aucune réactivation automatique** : une désignation révoquée est **définitive** ; le retour du rôle ne la rétablit pas ; une nouvelle désignation est un **nouvel acte** de l'administrateur.
7. **Traçabilité** : audit `ARCHIVE_MANAGER_DESIGNATED` / `ARCHIVE_MANAGER_REVOKED` (identifiants, cause ; aucune valeur personnelle).
8. `MUNICIPALITY_ADMIN` n'a **pas** besoin de désignation (accès de plein droit à sa commune) ; la désignation ne confère **aucun** droit opérationnel ni statistique.

### 4.5 Gel de conservation (M3)
- **Unité** : le **dossier communal** ; activation, **revue** et **levée** réservées au `MUNICIPALITY_ADMIN` de **sa** commune (isolation testée).
- **Motif obligatoire** (code : `LITIGE`, `RECOURS`, `REQUISITION`, `DEMANDE_PERSONNE`, `AUTRE` ; commentaire ≤ 200, obligatoire pour `AUTRE`), **auteur** et **date** enregistrés ; **un seul gel actif** par dossier ; chaque épisode est **conservé** (historique : gels levés, auteurs, dates, revues).
- **Revue périodique** : échéance de revue = pose + `ARCHIVE_HOLD_REVIEW_MONTHS` (**6 mois, hypothèse à valider avec le DPO et les archives**) ; la **revue** (« confirmer le maintien », commentaire) reporte l'échéance et incrémente le compteur ; **aucune prolongation automatique**.
- **Gel en retard de revue** (arbitrage **A2**, valeur par défaut proposée) : le gel **continue de protéger** le dossier (aucune levée automatique, aucune purge) mais est **signalé** « à revoir » (bandeau pour l'administrateur, commande `app:archive:holds-report` sans donnée personnelle) ; il ne se renouvelle **jamais** sans acte explicite.
- Un gel **maintient l'accès à l'archive de ce dossier** au-delà de l'échéance (4.6) et **n'en accorde aucun à une autre commune**. Audit : `LEGAL_HOLD_PLACED` / `REVIEWED` / `LIFTED`.

### 4.6 Fermeture de l'accès à l'échéance (arbitrage **A3**, valeur par défaut proposée)
L'archive d'un dossier est **ouverte** de la clôture jusqu'à `closedAt + ARCHIVE_RETENTION_MONTHS` (**hypothèse 12 mois**, paramètre) **ou tant qu'un gel est actif**. Après l'échéance sans gel : **accès fermé** (refus uniforme) en attendant la purge du 05.6.4 ; **aucune suppression** dans ce sous-lot. Paramètres de configuration (jamais de constante), plancher de sécurité.

### 4.7 Statistiques : habilitation de coordination (L5-36, arbitrage **A1**)
Les deux permissions sont **distinctes et non fusionnées** : la **désignation d'archive** (§4.4) ne donne **aucun** droit statistique ; la **coordination statistique** ne donne **aucun** accès aux dossiers. Options examinées : (a) un champ `scope` dans la table de désignation → **écarté** (fusion de deux droits, risque d'élévation) ; (b) **table séparée** de coordination ; (c) statu quo. **Recommandation** : (b), minimale : la **ventilation par commune** est lue par le `MUNICIPALITY_ADMIN` de l'organisatrice **de plein droit** (c'est son événement) et par un `EVENT_MANAGER` de l'organisatrice **seulement avec une habilitation explicite**, accordée et révoquée par ce `MUNICIPALITY_ADMIN`, mêmes règles de révocation que §4.4. Bilan global et propre répartition : inchangés. **Migration M5 conditionnelle**, exécutée **seulement si A1 est validé**.

## 5. Migrations (accord de principe D4 ; SQL exact généré par Doctrine à partir des entités esquissées, aucune exécution)

Tables nouvelles, **clés étrangères `RESTRICT`** (règle 8), dates UTC, UUID v7 `BINARY(16)`, **aucune donnée existante lue ni modifiée** (tables vides au départ), **aucune suppression physique** par l'application. Procédé d'unicité « actif » : `active_marker` vaut `1` tant que la ligne est active et `NULL` ensuite (MySQL n'a pas d'index unique partiel) ; plusieurs lignes révoquées/levées coexistent donc, **une seule active**.

### M2 — `archive_manager_designation` (désignation, option B)
```sql
CREATE TABLE archive_manager_designation (id BINARY(16) NOT NULL, active_marker SMALLINT DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revocation_cause VARCHAR(24) DEFAULT NULL, designated_at DATETIME NOT NULL, revoked_by_user_id BINARY(16) DEFAULT NULL, participation_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, designated_by_user_id BINARY(16) NOT NULL, INDEX idx_archive_designation_user (user_id, active_marker), UNIQUE INDEX uniq_archive_designation_active (participation_id, user_id, active_marker), INDEX IDX_BECB119C999038D9 (revoked_by_user_id), INDEX IDX_BECB119C6ACE3B73 (participation_id), INDEX IDX_BECB119CA76ED395 (user_id), INDEX IDX_BECB119C5E60D79F (designated_by_user_id), PRIMARY KEY (id));
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C999038D9 FOREIGN KEY (revoked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C6ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119CA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C5E60D79F FOREIGN KEY (designated_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
```
Entité `App\Archive\Domain\EventManagerDesignation` : participation, utilisateur, désigné par, date, `revoked_at`, révoqué par, **cause** (`ADMIN_REQUEST`, `ROLE_LOST`, `MEMBERSHIP_DEACTIVATED`, `USER_DEACTIVATED`), `active_marker`. Contrainte : **`UNIQUE (participation_id, user_id, active_marker)`** = une seule désignation active par participation et utilisateur ; index `(user_id, active_marker)` (contrôle à l'accès et crochets).

### M3 — `archive_legal_hold` (gel, **épisodes conservés**)
```sql
CREATE TABLE archive_legal_hold (id BINARY(16) NOT NULL, active_marker SMALLINT DEFAULT NULL, last_reviewed_at DATETIME DEFAULT NULL, review_count SMALLINT DEFAULT 0 NOT NULL, lifted_at DATETIME DEFAULT NULL, lift_comment VARCHAR(200) DEFAULT NULL, reason VARCHAR(30) NOT NULL, comment VARCHAR(200) DEFAULT NULL, placed_at DATETIME NOT NULL, review_due_at DATETIME NOT NULL, last_reviewed_by_user_id BINARY(16) DEFAULT NULL, lifted_by_user_id BINARY(16) DEFAULT NULL, request_municipality_id BINARY(16) NOT NULL, placed_by_user_id BINARY(16) NOT NULL, INDEX idx_archive_hold_review (active_marker, review_due_at), UNIQUE INDEX uniq_archive_hold_active (request_municipality_id, active_marker), INDEX IDX_F022355527658D3E (last_reviewed_by_user_id), INDEX IDX_F0223555B4F7BB6E (lifted_by_user_id), INDEX IDX_F02235559D375344 (request_municipality_id), INDEX IDX_F0223555D4990EE2 (placed_by_user_id), PRIMARY KEY (id));
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F022355527658D3E FOREIGN KEY (last_reviewed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555B4F7BB6E FOREIGN KEY (lifted_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F02235559D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555D4990EE2 FOREIGN KEY (placed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
```
Entité `RequestLegalHold` : dossier communal, **motif** (code), commentaire, posé par/le, **échéance de revue**, dernière revue (par/le), `review_count`, levé par/le + commentaire, `active_marker`. Contraintes : **`UNIQUE (request_municipality_id, active_marker)`** = un seul gel actif par dossier ; index `(active_marker, review_due_at)` (rapport des revues). **Écart avec le cadrage v2.2 (§10.1)**, qui prévoyait trois colonnes sur `request_permit_request_municipality` : la demande « historique traçable » impose une **table d'épisodes** ; `request_permit_request_municipality` n'est **pas** modifiée (consignée L5-39).

### M4 — `archive_consultation` (journal des consultations) — **non prévue au cadrage v2.2** (L5-40)
```sql
CREATE TABLE archive_consultation (id BINARY(16) NOT NULL, action VARCHAR(24) NOT NULL, reason_code VARCHAR(30) NOT NULL, reason_comment VARCHAR(200) DEFAULT NULL, attachment_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, request_municipality_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_archive_consultation_dossier (request_municipality_id, occurred_at), INDEX idx_archive_consultation_user (user_id, occurred_at), INDEX IDX_DAFF377D9D375344 (request_municipality_id), INDEX IDX_DAFF377DA76ED395 (user_id), PRIMARY KEY (id));
ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377D9D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT;
ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377DA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
```
Entité `ArchiveConsultation` : dossier communal, consultant, `action` (`DOSSIER_OUVERT`, `PIECE_TELECHARGEE`), code et commentaire du motif, pièce (identifiant **sans clé étrangère**, pour ne pas bloquer la purge des pièces), date. Index `(request_municipality_id, occurred_at)` et `(user_id, occurred_at)`. Nécessaire car l'audit (DT-037) n'admet aucune valeur personnelle et le motif porte un commentaire libre.

**Clés étrangères** (toutes `ON DELETE RESTRICT`) :
```sql
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F022355527658D3E FOREIGN KEY (last_reviewed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555B4F7BB6E FOREIGN KEY (lifted_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F02235559D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT;
ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555D4990EE2 FOREIGN KEY (placed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377D9D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT;
ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377DA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C999038D9 FOREIGN KEY (revoked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C6ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119CA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C5E60D79F FOREIGN KEY (designated_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
```
Les noms `FK_…` sont ceux de Doctrine à la date de l'esquisse ; ils seront régénérés par `doctrine:migrations:diff` (même SQL de fond).

### M5 — conditionnelle (A1) : `archive_statistics_coordination` (habilitation de coordination statistique)
Table de la même forme que M2, rattachée à l'**événement** (et non à la participation) : `(id, event_id FK, user_id FK, granted_by_user_id FK, granted_at, revoked_at, revoked_by_user_id, revocation_cause, active_marker)`, `UNIQUE (event_id, user_id, active_marker)`. SQL exact à produire **seulement si A1 est validé**.

**Existant, retour arrière, tests** : aucune table existante modifiée ; `down()` = `DROP TABLE` **refusé si la table contient des lignes** (convention des lots 05.5 et 05.6.2 : restaurer une sauvegarde) ; sauvegarde vérifiée avant chaque exécution ; exécution `civipass_test` puis `civipass` ; `doctrine:schema:validate` ; round-trip `up`/`down` sur table jetable (aucune base jetable : droit `CREATE DATABASE` refusé) ; suite complète.

## 6. Arbitrages métier réellement nécessaires

| # | Question | Recommandation | Bloquant ? |
|---|---|---|---|
| **A1** | Mécanisme de l'habilitation de coordination statistique (L5-36) | Table séparée (M5) : `MUNICIPALITY_ADMIN` de l'organisatrice de plein droit, `EVENT_MANAGER` sur habilitation explicite ; **distincte** de la désignation d'archive | Seul **M5** en dépend ; le reste du sous-lot avance sans |
| **A2** | Gel **en retard de revue** : continue de protéger et est signalé, ou se lève automatiquement ? | **Continue de protéger**, signalé, jamais renouvelé ni levé automatiquement (la suppression de preuves est irréversible ; l'excès de conservation est signalé et corrigeable) | Non (valeur par défaut appliquée) |
| **A3** | Accès à l'archive **fermé à l'échéance** (clôture + durée, sauf gel), en attendant la purge | **Oui** (« fermeture à terme », DM-120) | Non (valeur par défaut appliquée) |

Tranché **sans arbitrage** (conforme aux décisions existantes) : liste des codes de motif (modifiable en configuration), validité du lien de consultation (30 min), journal visible du seul `MUNICIPALITY_ADMIN` de la commune, `MUNICIPALITY_ADMIN` sans désignation, commune suspendue = lecture conservée. Durées (12 mois, revue de gel 6 mois) : **hypothèses à valider avant production** (DPO, archives).

## 7. Critères d'acceptation et tests majeurs

1. **Accès** (matrice rôle × commune × événement × dossier × état, identifiants forgés, HTTP et services) : `MUNICIPALITY_ADMIN` de la commune ✔ ; `EVENT_MANAGER` désigné ✔, **non désigné ✘**, désigné pour `(E, M)` ✘ sur `(E, M')` et `(E', M)` ; `INSTRUCTOR` ✘ ; CWS sans appartenance ✘ ; autre commune ✘ (404 uniforme, jamais « clos » ni « archive ») ; événement non clos ✘ ; archive échue sans gel ✘ ; compte inactif ✘.
2. **Désignation** : seul l'administrateur de la commune ; cible invalide refusée ; unicité active ; révocation **automatique** (rôle retiré, membership désactivé, compte désactivé) **et** recontrôle à l'accès quand le crochet est contourné ; **aucune réactivation** au retour du rôle ; concurrence désignation / retrait de rôle.
3. **Motif et journal** : motif obligatoire (`AUTRE` exige un commentaire) ; **une ligne par ouverture et par téléchargement** (consultant, date, dossier, motif) ; échec du journal = **accès refusé** ; lien de consultation expiré, d'un autre utilisateur ou d'un autre dossier refusé ; journal lisible par l'administrateur de **sa** commune seulement.
4. **Lecture seule et isolation** : aucune route d'écriture ; aucun accès aux dossiers confidentiels d'une autre commune (données propres, lignes neutres) ; pièce d'une autre demande refusée ; aucune réouverture des droits opérationnels (routes opérationnelles toujours en 410).
5. **Gel** : pose, revue, levée par l'administrateur de **la** commune ; un administrateur d'une autre commune refusé ; un gel de `A` **n'ouvre rien de `B`** ; un seul gel actif ; historique conservé ; revue en retard : gel **maintenu** et signalé ; aucun renouvellement automatique ; accès maintenu au-delà de l'échéance.
6. **Garde-fous** : test de matrice (toute route `/archives/` passe par la politique ; aucune dépendance de la garde opérationnelle), architecture, Sonata sans entité de dossier, audit sans valeur personnelle, `bin/qa full`, `verify-docs.sh`, `doctrine:schema:validate`.
7. **Habilitation statistique** (si A1) : coordination et désignation d'archive **indépendantes** (l'une n'ouvre jamais l'autre).

## 8. Dépendances, exclusions et contradictions

- **Vers le 05.6.4** : purge d'un dossier = suppression de ses gels, journaux de consultation et désignations de l'événement (FK `RESTRICT`, ordre explicite) ; un gel actif **bloque** la purge du dossier ; décision C14 ; compteurs exacts (L5-37).
- **Contradictions consignées** : **L5-39** (M3 : table d'épisodes au lieu de colonnes) ; **L5-40** (M4 : journal des consultations non prévu au cadrage v2.2) ; **L5-36** traitée par A1.
- **Risques résiduels** : durées non validées juridiquement ; journal de consultation = donnée à régime propre (consultant, motif) soumise à la politique de conservation du DPO ; lien de consultation de 30 minutes non révocable individuellement (recontrôle à chaque affichage suffit) ; coordination statistique implicite tant que A1 n'est pas appliqué.

## 9. Plan d'exécution (mandat)

1. Entités, repositories, configuration (`ARCHIVE_RETENTION_MONTHS`, `ARCHIVE_HOLD_REVIEW_MONTHS`, `ARCHIVE_CONSULTATION_MINUTES`), `AuditAction` ; migrations **M2, M3, M4** (sauvegarde, `civipass_test` puis `civipass`) ; mapping Doctrine du module `Archive`.
2. `ArchiveAccessPolicy`, désignation (service, crochets de révocation, audit).
3. Gel (pose, revue, levée, rapport des revues).
4. Espace d'archives (liste, motif, consultation, pièces, journal), gabarits, classement des routes (`Reserved` → routes enregistrées et testées).
5. Habilitation statistique (M5) **si A1 validé**.
6. Tests (matrice, concurrence, forgés), documentation (`security.md`, DM-120, contradictions), `bin/qa full`, `verify-docs.sh`, validation Doctrine ; **commits locaux** cohérents ; rapport synthétique.

## 10. Réalisation (12 novembre 2026)

- **Migration** `Version20261112085714` : les quatre tables en une migration (M2 `archive_manager_designation`, M3 `archive_legal_hold`, M4 `archive_consultation`, M5 `archive_statistics_coordination`) ; la colonne du motif de consultation s'appelle `reason` (et non `reason_code`) ; SQL conforme au §5. Sauvegardes `mysqldump` des deux bases avant exécution, celle de `civipass` restaurée dans une base temporaire et comparée (38 tables, événements, audit) ; 20 migrations exécutées, 0 en attente, `doctrine:schema:validate` OK sur les deux bases ; tables vides ; `down()` refusé si une table contient des lignes.
- **Module `src/Archive`** : `ArchiveAccessPolicy` (seule porte), `ArchiveDesignationService`, `StatisticsCoordinationService`, `LegalHoldService`, `ArchiveConsultationService`, `ArchiveDossierReader`, `ArchiveAccessLossObserver` (crochets de `MembershipManager` et `UserManager` via `AccessLossObserver`), contrôleurs `ArchiveController` et `StatisticsCoordinationController`, commande `app:archive:holds-report`. Paramètres : `ARCHIVE_RETENTION_MONTHS` (12), `ARCHIVE_HOLD_REVIEW_MONTHS` (6), `ARCHIVE_CONSULTATION_MINUTES` (30), hypothèses à valider.
- **Statistiques** : la ventilation exige désormais le rôle `MUNICIPALITY_ADMIN` de l'organisatrice ou, pour un `EVENT_MANAGER`, une habilitation explicite (port `StatisticsCoordinationLookup`, rôles dans `EventPermission`).
- **Précisions de conception** : le droit est contrôlé **avant** le jeton CSRF (404 uniforme même sur un POST non habilité) ; un gel peut être posé sur un dossier dont l'archive est échue (pour le protéger de la purge) ; la page d'accueil des archives est une liste filtrée (vide, jamais 404, pour un membre sans habilitation).
