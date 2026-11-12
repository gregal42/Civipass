# LOT 05.6.4 — Purge à deux niveaux, suppression de l'événement et 404 après purge : cadrage (12 novembre 2026)

**Statut : VALIDÉ le 12 novembre 2026 (R1 : option 2) ; RÉALISÉ en local (voir §10), non poussé.** Aucune implémentation, aucune migration exécutée. Références : [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §3.3, §3.4, §7, §13 ; [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) ; [rgpd-politique-conservation.md](rgpd-politique-conservation.md) ; [lot-05-6-3-cadrage.md](lot-05-6-3-cadrage.md) §8.

## 1. Objectif et périmètre

Supprimer de façon **contrôlée, traçable, idempotente et reprenable** les données d'un dossier communal à l'échéance de son archive (niveau **commune**), puis les données **partagées** d'une demande quand plus aucun dossier ne les justifie (niveau **partagé**), puis l'événement lui-même sur demande explicite (phase 4) ; garder un **registre sans donnée personnelle** ; faire répondre **404** à tout jeton après la purge (option O1).

**Inclus** : préavis ; service de purge (simulation par défaut) ; registre et bordereau ; suppression de l'événement ; réduction des compteurs de clôture à leur forme publiée (L5-37) ; 404 après purge ; décision C14. **Exclus** : droits des personnes et purge anticipée à la demande (05.6.6), comptes candidats et dormance (05.6.5), planification en production, sauvegardes et rejeu après restauration (05.6.7), toute suppression de compte, de commune ou de référentiel.

## 2. Règles et décisions applicables (non rediscutées)

Règle 8 (**aucune suppression avant la fin de la conservation justifiée ; à l'issue, suppression contrôlée, traçable**) ; DM-120 D1 à D5 ; **D3 : l'unité est le dossier communal** (une donnée partagée n'est jamais supprimée tant qu'un dossier la justifie ; un gel n'ouvre rien aux autres communes) ; clés étrangères **`RESTRICT` conservées, aucun `CASCADE`** ; service explicite, **idempotent, reprenable** ; comptes, communes, types, référentiels **jamais supprimés** par ce lot ; **aucune planification** créée ; durées = **hypothèses** à valider (DPO, archives) avant production.

## 3. Pré-inspection (schéma réel au 12 novembre 2026)

**Graphe des clés étrangères** (enfant → parent, toutes `RESTRICT`) : le tableau ci-dessous en déduit l'ordre de suppression.

| Niveau | Données | Table(s) | Clé de sélection |
|---|---|---|---|
| **Commune** (dossier `D` = demande `R` × commune `M`) | zones demandées | `request_permit_request_zone` | `request_municipality_id = D` |
| | périodes closes, zones et lignes d'autorisation | `permit_authorization_period`, `permit_authorization_zone`, `permit_authorization` | autorisations de `M` sur le laissez-passer de `R` |
| | historique des droits de `M` (révisions, lignes de plaques) | `permit_revision_vehicle`, `permit_revision` | révisions dont la commune est `M` **ou** liées aux autorisations de `M` |
| | décision de `M` | `request_permit_request_decision` | `request_municipality_id = D` |
| | journal de consultation et gels (épisodes levés compris) | `archive_consultation`, `archive_legal_hold` | `request_municipality_id = D` (le gel **actif** interdit la purge) |
| | désignations d'archive de `M` pour l'événement | `archive_manager_designation` | supprimées à la purge du **dernier** dossier de `M` pour cet événement (plus rien à lire) |
| | notifications propres à `M` (liées à ses autorisations) et leurs secrets | `notification_outbox`, `notification_secret` | `authorization_id` de `M` |
| | audit de `M` sur la demande et le laissez-passer | `audit_log` (aucune clé étrangère) | `subject_id ∈ {R, laissez-passer}` **et** `municipality_id = M` |
| | **ligne du dossier** | `request_permit_request_municipality` | `D` (en dernier) |
| **Partagé** (demande `R` quand **aucun** dossier de `R` ne subsiste) | identité, contact, adresse, textes | `request_permit_request` | `R` (en dernier) |
| | plaques déclarées et autorisées | `request_permit_request_vehicle`, `permit_vehicle` | `R` / laissez-passer |
| | justificatifs : **lignes et fichiers** | `request_permit_request_attachment` + `FileStorageInterface::delete` | `R` ; **fichier supprimé d'abord** |
| | jetons : contrôle public, lien bénéficiaire | `permit_verification_token`, `permit_access_token` | laissez-passer de `R` |
| | laissez-passer | `permit_permit` | `R` |
| | clés d'idempotence | `request_idempotency_key` | `R` |
| | notifications de niveau laissez-passer et secrets | `notification_outbox`, `notification_secret` | `permit_id` du laissez-passer |
| | audit restant sur `R` et le laissez-passer (dont les lignes système sans commune) | `audit_log` | `subject_id ∈ {R, laissez-passer}` |
| **Événement** (phase 4) | habilitations restantes, coordination statistique, invitations, validités, zones, participations, événement | `archive_manager_designation`, `archive_statistics_coordination`, `onboarding_invitation_link`, `event_zone_validity`, `event_zone`, `event_participation`, `event_event` ; audit des sujets événement | dans cet ordre ; **refus** s'il reste un dossier ou une demande |
| **Jamais** | comptes, appartenances, communes, types d'événement, référentiels, compteurs de références (aucune donnée personnelle), audit global (comptes, communes, accès) | — | hors périmètre |

**Constats déterminants** : (P1) `permit_permit.first_decision_id` (non nul, `RESTRICT`) relie une donnée **partagée** à la décision d'**une** commune (L5-32) ; la propriété n'est **lue nulle part** dans le code ; (P2) les fichiers sont hors base : l'ordre « fichier d'abord, ligne ensuite » rend la reprise sûre (une suppression de fichier est idempotente) ; (P3) l'audit n'a **aucune clé** : la sélection se fait par sujet et commune, **collectée avant** la suppression des lignes qui portent les identifiants ; (P4) `PublicVerificationReader` répond déjà **404** à un jeton inconnu et l'événement clos est neutre : un jeton supprimé sort donc naturellement du 410 vers le 404 (O1) ; (P5) l'outbox est déjà purgée à 90 jours pour les états définitifs (L5-33) ; (P6) les compteurs de clôture exacts (`event_event.closure_statistics`, v1) subsistent (L5-37) ; (P7) l'accès d'archive et la purge n'appellent **jamais** la garde opérationnelle (test d'architecture existant) ; (P8) aucun service de purge ni registre n'existe.

## 4. Conception

### 4.1 Éligibilité et préavis
Un dossier est **éligible** quand : événement **clos** ; `closedAt + ARCHIVE_RETENTION_MONTHS` **atteint** (repère d'éligibilité : **jamais une fermeture d'accès** en soi, correction A3 du 12 novembre 2026 ; l'accès reste ouvert jusqu'à la purge effective) ; **aucun gel actif** ; **préavis émis** depuis au moins `RETENTION_NOTICE_DAYS` (30, hypothèse). `app:retention:notice` (simulation par défaut) envoie aux `MUNICIPALITY_ADMIN` actifs de la commune un e-mail **sans donnée personnelle** (événement, nombre de dossiers, date de purge prévue, rappel du gel) et enregistre une ligne `NOTICE` au registre ; un échec d'envoi n'écrit rien (rejouable). Le préavis est **recontrôlé** à l'exécution : un gel posé entre-temps ou une échéance non atteinte **bloque** la purge.

### 4.2 Purge au niveau commune
Par dossier, **une transaction** : verrou de la ligne du dossier (même ordre que le gel et la consultation), contrôle **sous verrou** (gel actif, échéance), collecte des identifiants d'audit, suppressions dans l'ordre du §3 (tables enfants d'abord), vérification que **rien ne référence plus** la ligne du dossier (les `RESTRICT` sont le filet : un résidu **annule** la transaction), ligne de registre `DOSSIER` (comptes par table, **aucune donnée personnelle**), **une** ligne d'audit `RETENTION_DOSSIER_PURGED` (identifiants et comptes). Idempotent : un dossier déjà purgé est ignoré ; une reprise repart des lignes restantes. Un dossier **gelé** n'est jamais touché ; une consultation ouverte en même temps échoue (clé étrangère) : **fail-closed**.

### 4.3 Purge au niveau partagé
Quand le **dernier** dossier de la demande `R` a été purgé : fichiers des justificatifs **supprimés d'abord** (idempotent, absence tolérée), puis suppression des lignes du §3 dans une transaction, ligne de registre `SHARED`, ligne d'audit. Tant qu'un dossier de `R` subsiste (gelé ou non échu), **rien de partagé n'est supprimé**. Si le dernier dossier de l'événement vient d'être purgé, les compteurs de clôture sont **réduits à leur forme publiée** (§4.6).

### 4.4 Décision C14 (arbitrage **R1**)
`permit_permit.first_decision_id` interdit de supprimer la décision de la première commune approbatrice (donc sa ligne de dossier) tant que le laissez-passer existe. **Option 1** : conserver la décision et la ligne de dossier de cette commune jusqu'au niveau partagé (aucune migration ; mais un résidu de la commune `A` — identifiant de l'agent décideur, dates — survit tant qu'un dossier de `B` est retenu, ce qui **contredit D3** pour `A`). **Option 2 (recommandée)** : rendre la clé **nullable** (M7), la mettre à `NULL` avant la suppression de la décision ; la provenance perdue n'est lue nulle part, et chaque dossier est supprimé **entièrement** à son échéance. Coût : une migration d'une ligne, sans perte de donnée.

### 4.5 Suppression de l'événement (phase 4)
Action **explicite** du `MUNICIPALITY_ADMIN` de la commune **organisatrice** (route du préfixe réservé `/communes/{m}/retention/…`, politique propre), avec **saisie de la référence publique** de l'événement. Conditions, relues sous verrou de l'événement : événement **clos** ; **aucun dossier ni demande** ne subsiste (tous purgés, ou événement sans demande) ; aucun gel. Suppression ordonnée du §3 (habilitations, coordination, invitations, validités, zones, participations, événement) et de l'audit des sujets événement ; ligne de registre `EVENT_DELETED` (référence, comptes) ; **comptes, communes et référentiels intacts**. Le moindre résidu référençant l'événement **annule** l'opération.

### 4.6 Compteurs de clôture (L5-37)
*Couverture des journaux, désignations, gels et compteurs : voir §3 (tables), §4.2 (suppression avec le dossier) et le présent paragraphe ; traçabilité minimale : §4.8 (registre, audit, fichier hors base).*
À la purge du **dernier** dossier de l'événement, le bloc de compteurs exacts (`v1`) est remplacé, dans la même transaction, par un **instantané publié** (`v2`) : valeurs **déjà seuillées** (global, ventilation, lignes communales) calculées avec le seuil du moment ; plus aucun effectif exact. Le lecteur du bilan sert `v1` (compteurs) ou `v2` (instantané) ; mêmes permissions. Aucune migration (même colonne JSON). Seuil appliqué : provisoire, à valider (DPO).

### 4.7 404 après la purge (O1)
Aucune table résiduelle de jetons : le jeton de contrôle public et le lien bénéficiaire sont supprimés avec le laissez-passer ; la page publique et le lien répondent comme pour un jeton **inconnu** (404, même corps, mêmes en-têtes) ; un jeton retiré **avant** la purge répondait 410, et **après** la purge, 404 (`DM-115` amendé). Testé sur jeton actif, retiré et inconnu.

### 4.8 Registre et bordereau
Table `retention_purge_record` (M6), **sans clé étrangère** (elle survit aux lignes supprimées) et **sans donnée personnelle** : nature (`NOTICE`, `DOSSIER`, `SHARED`, `EVENT_DELETED`), événement (identifiant, référence), commune, dossier, demande, dates, exécutant (système ou identifiant), **comptes par table**, fichiers supprimés, destinataires du préavis. Chaque exécution **ajoute aussi une ligne** au registre **hors base** `var/retention/purge-register.jsonl` (chemin configurable) : base du **rejeu après restauration** (05.6.7). Le **bordereau** est la sortie de la commande (par exécution) et la page « Registre des purges » de l'administrateur de la commune (lecture seule, **sa** commune). Le sort final (élimination ou versement) et le **visa d'élimination** restent à définir avec le service d'archives : le bordereau est conçu pour être complété, pas présenté comme un visa.

### 4.9 Garde-fous d'exploitation
`app:retention:purge` : **simulation par défaut** (comptes, aucune écriture), `--execute` explicite, `--limit`, `--event`, `--municipality`. **`RETENTION_PURGE_ENABLED=0` par défaut** : tant que la politique n'est pas validée (DPO, archives), `--execute` est **refusé** ; l'interrupteur est une configuration, jamais activée en production sans accord. **Aucune planification** n'est créée. Lots bornés, une transaction par dossier, **jamais** de purge dans une requête web.

## 5. Migrations (accord de principe D4 ; SQL exact généré par Doctrine à partir d'entités esquissées, aucune exécution)

### M6 — `retention_purge_record` (registre) — **nouvelle table, sans clé étrangère par conception**
```sql
CREATE TABLE retention_purge_record (id BINARY(16) NOT NULL, kind VARCHAR(20) NOT NULL, event_id BINARY(16) NOT NULL, event_reference VARCHAR(30) NOT NULL, municipality_id BINARY(16) DEFAULT NULL, dossier_id BINARY(16) DEFAULT NULL, request_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, due_at DATETIME DEFAULT NULL, actor_user_id BINARY(16) DEFAULT NULL, counts JSON DEFAULT NULL, files_deleted INT DEFAULT 0 NOT NULL, recipients INT DEFAULT NULL, batch_id BINARY(16) DEFAULT NULL, INDEX idx_retention_record_event (event_id, kind), INDEX idx_retention_record_municipality (municipality_id, occurred_at), INDEX idx_retention_record_kind (kind, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
```
Entité `App\Retention\Domain\PurgeRecord`. Remplace la colonne `event_event.purged_at` du cadrage v2.2 (§10.1, M4), **inutile** : l'état « purgé » se déduit de l'absence de dossier et le registre porte la trace. **Écart consigné** (L5-41).

### M7 — `permit_permit.first_decision_id` nullable (**conditionnelle** : option 2 de C14, arbitrage R1)
```sql
ALTER TABLE permit_permit CHANGE first_decision_id first_decision_id BINARY(16) DEFAULT NULL;
```
La clé étrangère `RESTRICT` est conservée ; seul le caractère obligatoire disparaît. Aucune ligne modifiée.

**Existant, retour arrière, tests** : M6 crée une table vide ; M7 ne change aucune valeur. `down()` de M6 refusé si la table contient des lignes (preuves) ; `down()` de M7 refusé si une valeur `NULL` existe (restaurer une sauvegarde). Sauvegarde `mysqldump` **vérifiée** (restaurée en base temporaire) avant chaque exécution ; `civipass_test` puis `civipass` ; `doctrine:schema:validate` ; suite complète. La purge elle-même est **irréversible** : jamais exécutée (`--execute`) sur `civipass` hors jeu de données de test créé pour l'occasion, avec sauvegarde.

## 6. Arbitrage réellement nécessaire

| # | Question | Recommandation | Bloquant ? |
|---|---|---|---|
| **R1** | C14 : option 2 (clé nullable, M7, dossier supprimé entièrement à son échéance) ou option 1 (résidu de la première commune jusqu'au niveau partagé, aucune migration) ? | **Option 2** : respecte D3 et la minimisation ; coût : une migration d'une ligne | Oui pour M7 ; sinon l'option 1 est mise en œuvre et le résidu documenté |

Tranché **sans arbitrage** (conforme aux décisions existantes) : unité = dossier communal ; fichier d'abord, ligne ensuite ; registre en base **et** hors base ; préavis par e-mail aux administrateurs, sans donnée personnelle ; `RETENTION_PURGE_ENABLED=0` par défaut ; audit du dossier supprimé avec lui (une ligne de purge conservée) ; journal de consultation, gel et désignations supprimés avec le dossier ; compteurs exacts remplacés par l'instantané publié ; aucune purge anticipée ni planification.

## 7. Critères d'acceptation et tests majeurs

1. **Simulation et garde-fous** : `app:retention:purge` sans `--execute` n'écrit **rien** (comptage identique avant/après) ; `--execute` refusé si `RETENTION_PURGE_ENABLED=0` ; jamais de purge dans une requête web.
2. **Éligibilité** : dossier non échu, gelé (même en retard de revue), sans préavis ou préavis trop récent : **jamais purgé** ; gel posé **pendant** la purge (course réelle) : la purge s'arrête ou le gel est refusé, jamais les deux.
3. **Commune** : toutes les tables du §3 vidées pour `D` ; **aucun résidu** (requêtes de contrôle par table, aucun orphelin, comptages avant/après) ; `request_permit_request_municipality` de `D` supprimée ; ligne de registre **sans donnée personnelle** ; une seule ligne d'audit de purge ; audit du dossier supprimé.
4. **Multicommunal (D3)** : `A` purgé, `B` gelé : copie partagée **conservée**, `B` y accède (archive), `A` n'a plus rien, `B` perd les lignes neutres de `A` ; gel de `A` **n'ouvre rien** à `B` ; purge de `B` ensuite : **tout** disparaît ; deux communes purgées dans l'ordre inverse ; ressources partagées (communes, comptes, types, référentiels, zones) **intactes**.
5. **Partagé** : justificatifs (**fichiers et lignes**), plaques, jetons, laissez-passer, clés d'idempotence, notifications et audit supprimés **seulement** quand plus aucun dossier ne subsiste ; fichiers absents tolérés ; cohérence disque (aucun fichier orphelin ni ligne pointant un fichier absent après reprise).
6. **Reprise et idempotence** : échec injecté **à chaque étape** (fichier, base, registre) puis relance : état final identique à une exécution sans panne, aucun double comptage ; deux exécutions simultanées : une seule agit par dossier (verrou), jamais d'interblocage ni d'erreur technique.
7. **C14** : option 2 : `first_decision_id` mise à `NULL` puis décision supprimée ; **aucune** lecture de `firstDecision()` ; test de non-régression de l'émission.
8. **Événement** : suppression refusée tant qu'un dossier, une demande ou un gel subsiste, par un non-administrateur de l'organisatrice, par une commune participante ou sans la référence saisie ; réussie après purge complète ou sur événement sans demande ; comptes et communes **intacts** ; registre `EVENT_DELETED` ; audit des sujets événement supprimé.
9. **O1** : jeton actif, retiré et inconnu : **avant** la purge 200/410/404 inchangés ; **après** : 404 **identique** à un inconnu (corps, en-têtes) pour page publique et lien bénéficiaire ; opérations opérationnelles toujours en 410 puis 404 (dossier inexistant).
10. **Compteurs** : après la purge du dernier dossier, plus aucun effectif exact (`v2` seulement) ; le bilan reste lisible avec les **mêmes permissions** ; masquage identique.
11. **Garde-fous** : la purge n'appelle jamais la garde opérationnelle (architecture) ; registre et bordereau sans donnée personnelle (inspection) ; `bin/qa full`, `verify-docs.sh`, `doctrine:schema:validate`.

## 8. Dépendances, exclusions et contradictions

- **Vers le 05.6.5** : comptes candidats et dormance après les purges (aucune suppression de compte ici). **Vers le 05.6.6** : l'effacement sur demande réutilise le service de purge par dossier (purge anticipée). **Vers le 05.6.7** : planification, rejeu du registre hors base après restauration, rotation des sauvegardes.
- **Contradictions** : **L5-32** (C14) traitée par **R1** ; **L5-33** (outbox à 90 jours) : l'outbox est supprimée **avec** le dossier et le laissez-passer ; la purge à 90 jours des états définitifs **subsiste** pour les événements actifs (pas de conflit) ; **L5-37** traitée (§4.6) ; **L5-41** nouvelle : colonne `purged_at` du cadrage v2.2 remplacée par le registre (M6).
- **Risques résiduels** : irréversibilité (parade : interrupteur, simulation, préavis, gel, sauvegarde) ; sauvegardes contenant des données purgées jusqu'à rotation (05.6.7) ; durées non validées juridiquement ; sort final et visa d'élimination non définis ; résidu documenté si l'option 1 est retenue.

## 9. Plan d'exécution (mandat)

1. Entité `PurgeRecord`, dépôt, paramètres (`RETENTION_NOTICE_DAYS`, `RETENTION_PURGE_ENABLED`, `RETENTION_REGISTER_PATH`), mapping du module `Retention` ; **M6** (et **M7** si R1 : option 2) avec sauvegardes vérifiées, `civipass_test` puis `civipass`.
2. Préavis (`app:retention:notice`), éligibilité.
3. Service de purge commune puis partagé, registre et bordereau, audit, compteurs `v2`.
4. Suppression de l'événement (service, route `/retention/…`, politique).
5. Tests (matrice du §7, reprise, concurrence réelle, jeu de données de purge sur base de test), 404 après purge.
6. Documentation (`security.md`, DM-120, DM-115, contradictions), `bin/qa full`, `verify-docs.sh`, validation Doctrine ; **commits locaux** ; rapport synthétique.

## 10. Réalisation (12 novembre 2026)

- **Migrations exécutées** (bases `civipass_test` et `civipass`, sauvegardes vérifiées) : `Version20261112103402` = M6 (`retention_purge_record`) + M7 (`permit_permit.first_decision_id` nullable, `RESTRICT` conservé). `down()` refuse si le registre contient des lignes ou si une clé est NULL.
- **Purge** : `app:retention:notice`, `app:retention:purge` (simulation par défaut ; `--execute` refusé tant que `RETENTION_PURGE_ENABLED=0`), `app:retention:register-check [--repair]`. Une transaction par dossier ; reprise des demandes orphelines (course entre deux derniers dossiers) au passage suivant.
- **Suppression de l'événement** : `/communes/{m}/retention` (registre de la commune) et POST de suppression, `MUNICIPALITY_ADMIN` de l'organisatrice, référence saisie, 404 uniforme, droit avant CSRF.
- **Registre** : table SQL + fichier JSONL hors base, écrit APRÈS la validation SQL (non atomique) ; divergences détectées ; seules les lignes SQL absentes du fichier sont réparées, les lignes de fichier sans SQL sont signalées. **Limites de restauration / rejeu : LOT 05.6.7.**
- **Point à valider avec le DPO** : suppression des journaux de consultation d'archive (identité des consultants) avec le dossier ; durées (12 mois, préavis 30 jours) = hypothèses, pas des validations juridiques.
- **Renommage** : le résumé publié de clôture s'appelle `ClosureStatisticsSummary` (le garde-fou « pas de cartographie » interdit le mot « snapshot » dans le module Event).
- Aucune mise en production avant validation du DPO, du service d'archives et des recettes de terrain ; `PUBLIC_VERIFICATION_ENABLED` reste à `0`.
