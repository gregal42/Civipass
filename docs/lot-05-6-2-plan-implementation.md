# LOT 05.6.2 — Plan d'implémentation détaillé (11 novembre 2026)

**Statut : phase 1 (étapes 1 à 3 du §9) IMPLÉMENTÉE le 11 novembre 2026, non commitée ; statistiques (étapes 4 et 5) et migration M1 NON commencées, en attente d'accord.** Fait suite au [cadrage technique](lot-05-6-2-cadrage.md). Décisions Q1 à Q8 du commanditaire intégrées, avec ses réserves (barrière exhaustive et gardes métier, notifications concurrentes, statistiques à deux niveaux, M1 distincte). **À présenter et valider avant la première ligne de code.** Le 05.6.3 n'est pas commencé.

Règles de lecture : tout ce qui est écrit « à vérifier » est **un point non prouvé** ; aucune contradiction n'est déclarée résolue tant que son test n'est pas vert (L5-33 et L5-35 restent ouvertes ; L5-34 reste ouverte jusqu'à la livraison et au test du lot).

---

## 1. Constat nouveau découvert pendant la préparation du plan (à traiter)

**N1 — rejeu d'idempotence servi sur un événement clos.** Dans `RequestSubmissionService`, la recherche d'une tentative déjà enregistrée précède le contrôle `isOperational()` : **avant** tout verrou lorsque la tentative porte des pièces jointes (lignes 141 à 143), puis sous verrou partagé (lignes 183 à 186). Un rejeu de la même clé d'idempotence (double envoi, bouton « actualiser », retour arrière du navigateur) sur un événement **clos** renvoie donc `SubmittedRequest` : identifiant et **référence** de la demande existante et, pour une délivrance directe, la décision délivrée (issue, mode de durée, dates de validité). C'est un chemin qui sert encore des données après la clôture, **absent de la pré-inspection précédente**. Correction : refuser le rejeu dès que l'événement n'est pas ouvert (voir §3.6 et le test T-N1).

---

## 2. Inventaire des clés d'idempotence et des éléments voisins (décision Q4)

Principe de Q4 : **aucune suppression systématique à la clôture** ; on distingue trois familles.

| Famille | Élément | Où | Contenu | Usage | Sort à la clôture |
|---|---|---|---|---|---|
| **Clé technique** | `request_idempotency_key` | table (FK `RESTRICT` vers événement et demande) | empreinte SHA-256 de (origine, agent, clé) ; `created_at` ; aucune donnée personnelle | rejouer un dépôt en double (`RequestSubmissionService`, lignes 137 à 256) ; contrainte d'unicité `uniq_request_idempotency_event_key` | **Conservée** (aucune écriture). Le **rejeu est refusé** après clôture (N1). Suppression avec le dossier à la purge (05.6.4), la FK étant `RESTRICT` |
| Clé technique | clé de tentative en session | `PublicRequestDraft::attemptKey()` | jeton de session du déposant | construit la clé ci-dessus | Aucune (session) |
| Clé technique | `notification_outbox.deduplication_key` | colonne unique | chaîne technique (type + identifiants) | éviter les doublons d'intentions | Conservée avec la ligne (trace) ; supprimée à la purge |
| Clé technique | nonce de demande manuelle | `ManualNotificationRecorder` (`requestManual`) | identifiant | dédoublonner un renvoi manuel | Idem |
| Clé technique | identifiant d'opération de formulaire | `PermitRevision.batch_id` (révocation, plaques, zones, périodes, **remplacement du QR**) | UUID | idempotence des révisions (05.5.1 à 05.5.5) | **Conservé** (élément de preuve, lie les lignes d'un même acte) |
| **Secret d'accès** | `permit_access_token` (lien bénéficiaire) | table | **empreinte** du secret, pas le secret | accès bénéficiaire | **Fermé par prédicat**, ligne conservée ; les liens jamais remis (ligne `PENDING` annulée) sont **révoqués** |
| Secret d'accès | `notification_secret` | table | secret **chiffré**, 48 h max | envoi du premier message | **Supprimé** à la clôture pour les laissez-passer de l'événement (`SecretPurger`) |
| Secret d'accès | `permit_verification_token` | table | jeton QR | contrôle public | Inchangé (contrat 410/404/« terminé » ; purge au 05.6.4) |
| Secret d'accès | `onboarding_invitation_link` | table | empreinte de jeton | invitation d'une commune | **Révocation explicite** des non consommés (Q5) |
| **Preuve** | `audit_log` (`batch_id` d'audit), lignes `SENT` de l'outbox, révisions immuables, décisions | tables | sans valeur personnelle (DT-037) | preuve et imputabilité | **Conservés** pendant l'archive |

Contrôle à l'implémentation : `grep` des usages (aucun autre appelant de `findByIdempotencyKey` n'existe) et test qui vérifie qu'**aucune** ligne de `request_idempotency_key` n'est modifiée ni supprimée par la clôture.

---

## 3. Barrière de clôture (corrections de conception n° 1, Q1 et Q2)

### 3.1 Trois couches

| Couche | Rôle | Emplacement |
|---|---|---|
| **A. Barrière HTTP exhaustive** | Aucune route d'exploitation d'une commune ne répond sur un événement clos, **par construction** et non par liste à tenir à jour | écouteur `kernel.controller` |
| **B. Gardes métier** | Un service unique `EventOperationalGuard` appelé **dans** les lecteurs et services qui touchent dossiers, autorisations, plaques, justificatifs et liens ; protège aussi tout appel **hors HTTP** (commande, test, futur code) | services applicatifs |
| **C. Chemins autorisés séparés** | Les archives (05.6.3) et la purge (05.6.4) vivent dans des **routes et des services distincts**, **explicitement exemptés**, jamais derrière la couche A ni B | préfixes et espaces de noms réservés |

### 3.2 Couche A : exhaustive par construction

Une liste de motifs de noms de routes s'oublie. La barrière est donc **déduite de la forme de la route**, avec une **liste d'exemptions fermée** :

- **Règle** : toute route dont le chemin commence par `/communes/{municipalityId}/` **et** qui porte un paramètre de ressource d'exploitation (`{requestId}`, `{attachmentId}`, `{permitId}`, `{linkId}`) **ou** qui appartient aux familles `demandes`, `delivrances`, `corrections-identite` est **gardée**.
- **Exemptions** (liste fermée, chacune justifiée dans le fichier de configuration) : sélecteur de commune, **routes d'événement** (`/evenements/…` : aucune donnée personnelle), **préfixe réservé** `/communes/{m}/archives/…` (futur, **vide** aujourd'hui), préfixe réservé `/communes/{m}/retention/…` (futur).
- **Routes publiques** (`/v/{token}`, `/laissez-passer/{secret}`, `/demandes/{eventReference}`) : **hors écouteur** ; chacune est fermée par sa propre logique métier (couche B) et testée.
- **Test de matrice (garde-fou)** : le test parcourt **toutes** les routes du routeur et exige que chacune soit **gardée**, **exemptée avec justification**, ou **publique avec test dédié**. Toute route nouvelle non classée **fait échouer** la suite.

Fonctionnement (inchangé par rapport au cadrage, ordre de sécurité précisé) :

1. Pas de commune dans la route → rien.
2. **Habilitation d'abord** (`MunicipalityContext::requireMembership`) ; échec → on laisse le contrôleur donner son **refus uniforme**.
3. Recherche de l'événement par la **portion communale** (SQL direct, interface `ClosedEventResolver`, aucune dépendance de code vers `Request` ou `Permit`) ; absence → refus uniforme du contrôleur.
4. Événement clos → **410** neutre (page sans donnée, `no-store`), identique pour **tous les rôles**. Pas d'audit par tentative.

### 3.3 Couche B : gardes métier (liste)

| Garde appelée dans | Accès protégé |
|---|---|
| `MunicipalRequestReader::listing / detail / attachment / decisionPage` et `DoctrineMunicipalRequestReadModel` | dossiers, justificatifs |
| `DirectIssuanceReader::confirmation` | délivrance directe |
| `RequestIdentityCorrectionService::find / page` | corrections d'identité |
| `PermitRightsOverviewReader::overview`, `PermitRevisionGuard` | autorisations, plaques, périodes, zones, QR |
| `PermitAccessService::overview / issue / requestNotification / revoke` | liens, notifications manuelles |
| `BeneficiaryPassReader::find` | lien bénéficiaire (page, PDF) → `null` (404) |
| `RequestSubmissionService` (rejeu, N1) | rejeu d'un dépôt |
| `SecretProvisioner::provision` | création d'un lien automatique |
| `NotificationDispatcher` (§5) | envoi |

`EventOperationalGuard` expose : `isOpen(Event|eventId): bool` et `assertOpen(…)` (lance `ClosedEventAccess`, convertie en **410** par l'écouteur d'exceptions HTTP, ou en refus de service hors HTTP). Il lit un **état frais** (aucun cache) et **ne verrouille pas**.

### 3.4 Couche C : chemins séparés explicitement autorisés

- Espaces de noms réservés (`App\Archive\…`, `App\Retention\…`) : **aucun** appel à `EventOperationalGuard`.
- Test d'architecture : (a) les routes sous `/archives/` et `/retention/` ne sont pas gardées par la couche A ; (b) aucun service de ces espaces ne dépend de `EventOperationalGuard` ; (c) la couche A ne contient **aucun** accès à ces préfixes. Ces espaces sont vides tant que les 05.6.3 et 05.6.4 ne sont pas livrés : le test porte sur les **règles de classement**, pas sur du code absent.
- Opérations techniques légitimes **non bloquées** : clôture elle-même, commandes de purge des secrets et de l'outbox, migrations, futures purge et archive, calcul des statistiques (§6).

### 3.5 Tests de la barrière

Voir §9 (T-BAR-1 à T-BAR-9).

### 3.6 Correction N1 (rejeu)

`RequestSubmissionService::submit` : contrôle d'ouverture de l'événement **avant** les deux recherches de rejeu. Le contrôle sans verrou (avant les pièces jointes) est un **refus rapide** ; le contrôle **sous verrou partagé** fait foi (l'état de l'événement y est relu, jamais l'instantané). Un rejeu **avant** la clôture reste servi ; après, refus identique à « les demandes ne sont pas ouvertes ». Test : T-N1.

---

## 4. Liens et invitations (Q5)

| Lien | Traitement |
|---|---|
| Lien bénéficiaire | Prédicat (couche B) ; lignes conservées |
| Invitation non consommée | `revokedAt` posé dans la transaction de clôture ; **compteur** dans l'audit ; les liens consommés ne sont pas modifiés ; la révocation **ne dépend pas** de l'état de participation (déjà `CLOSED`) |
| Réinitialisation de mot de passe | Hors périmètre (lien lié à l'utilisateur) : **test** qu'il n'est ni lu ni modifié |
| Participation `INVITED` à la clôture | Passe à `CLOSED` (existant) ; liens révoqués |

---

## 5. Notifications : inventaire et protocole de concurrence (corrections n° 2, Q3)

### 5.1 Inventaire des mécanismes (vérifié dans le code)

| Élément | Constat |
|---|---|
| **Distributeur** | **Un seul** : `NotificationDispatcher::run`, lancé par `app:notifications:dispatch` (cron, passages **éventuellement chevauchants** ; pas de consommateur Messenger). `run` traite les messages **séquentiellement**, borné à 50 s |
| Sélection | `findDispatchable` : `PENDING` échus **et** `RESERVED` dont le bail (**10 min**, `LEASE = PT10M`) est dépassé ; lecture **non verrouillante** |
| Exclusion mutuelle | `reserve()` : transaction avec **verrou d'écriture de la ligne** (`PESSIMISTIC_WRITE`), refus si non distribuable (autre distributeur, bail valide), écriture `RESERVED` + `attempts + 1` + bail |
| Envoi | **Hors transaction, sans verrou** (`compose` puis `mailer->send`), puis `settle()` sous verrou **si le bail est encore le nôtre** |
| Réessais | `settle` → `retryAt` (`PENDING`, `available_at` futur) ; abandon selon `RetryPolicy` |
| **Effet de bord avant la réservation** | étape 2 : pour `PERMIT_ISSUED`, `SecretProvisioner::provision` **crée un lien et un secret chiffré** avant `reserve()` |
| Autres écrivains d'état | `OutboxPurger` (états définitifs de plus de 90 jours), `SecretPurger` (verrou ligne puis secret, **révoque le lien** non envoyé), `NotificationOrdering::decide` (suppressions) |
| **Producteurs de lignes** | `PermitIssuer`, `AuthorizationRevocationService`, `AuthorizationZoneService`, `PermitVehicleService`, `PermitTokenReplacementService`, `AuthorizationPeriodService` (via `NotificationIntentRecorder`/`OutboxIntentRecorder`), `ManualNotificationRecorder` (via `PermitAccessService`) |
| Verrous des producteurs | révisions : verrou **partagé** de l'événement puis de la **participation** ; `PermitAccessService` : événement partagé puis participation. La clôture prend l'événement **exclusif** puis **toutes** les participations : elle **attend** tout producteur en cours, et tout producteur ultérieur voit l'événement clos. *(À prouver par test de concurrence, voir T-NOT-4 ; `PermitIssuer` via la décision et le dépôt direct : **à vérifier**.)* |

### 5.2 Définition précise : « message pris en charge »

**Un message est « pris en charge » à la validation de la transaction `reserve()`** (ligne `RESERVED`, `attempts` incrémenté, bail posé). Avant cet instant : aucun effet externe, **sauf** la création du lien et du secret (étape 2), qui est donc elle aussi gardée. Après : le message peut partir. **L'envoi n'est jamais couvert par un verrou** (inévitable : on ne tient pas de verrou pendant un échange SMTP).

### 5.3 Protocole

**P1 — À la clôture** (même transaction, après les verrous événement → participations, **avant** la fin ; verrou de la ligne d'outbox **en dernier**, conforme à l'ordre documenté) :
1. lecture **verrouillante** (`FOR UPDATE`, jamais l'instantané REPEATABLE READ) des lignes `PENDING` des laissez-passer de l'événement (prises par `permit_id`, **sans** verrouiller les laissez-passer) ;
2. pour chacune : `suppress(EVENT_CLOSED)` (toutes catégories, **prioritaires comprises**, Q3), suppression du secret chiffré, **révocation du lien** jamais remis (réemploi de la logique de `SecretPurger`) ;
3. suppression des secrets chiffrés restants des laissez-passer de l'événement (les lignes déjà `SENT` n'en ont plus : purge existante) ;
4. **compteurs** (nombre de lignes annulées, de secrets supprimés, de liens révoqués) dans le **payload d'audit** `EVENT_CLOSED` : aucun identifiant de personne ni contenu ;
5. les lignes `RESERVED` **ne sont pas touchées** (voir 5.4 : l'état reste exact ; elles tombent sous P2).

**P2 — Garde du distributeur** (deux contrôles, lecture **fraîche** de l'état de l'événement, hors transaction) :
- **G1** en tête de `dispatchOne`, **avant** l'ordonnancement et **avant la création du lien** (étape 2) : événement clos → `suppress(EVENT_CLOSED)` (tous types), purge du secret et révocation du lien ;
- **G2** après `reserve()` et **juste avant** `compose` + `send` (étape 5) : événement clos → `settle` en `SUPPRESSED/EVENT_CLOSED` sous le bail ; aucun envoi.
- `SecretProvisioner::provision` : contrôle de clôture **dans sa propre transaction** (aucune création de lien sur événement clos).
- `NotificationFacts` gagne un champ `eventClosed` (lecture de l'état de l'événement dans la même requête que les faits).

**P3 — Plus de prise en charge après clôture** : la clôture a validé `SUPPRESSED` pour tous les `PENDING` ; `reserve()` relit la ligne **sous verrou** et la refuse (`isDispatchable` faux) : un distributeur qui avait listé la ligne **avant** la clôture obtient `skipped`. Les lignes revenues en `PENDING` **après** la clôture (réessai d'un message en vol) ne sont **jamais** envoyées : G1 les annule au passage suivant. Aucune ligne `PENDING` ne peut être créée **après** la clôture (producteurs sérialisés, voir 5.1) ; G1 couvre l'imprévu.

**P4 — Ordre de verrous** : clôture : événement (X) → participations (X) → lignes d'outbox (X). Distributeur : ligne seule (`reserve`, `settle`) ; **il ne verrouille jamais l'événement** (sinon inversion : ligne → événement contre événement → ligne). La lecture de l'état de l'événement par G1 et G2 est **non verrouillante et hors transaction** : pas d'interblocage possible avec la clôture.

### 5.4 Limites documentées (à ne pas présenter comme des garanties)

1. **Fenêtre irréductible** : un message dont G2 a réussi **avant** la validation de la clôture peut être **remis au transport SMTP après** celle-ci (la validation intervient entre G2 et `mailer->send`, intervalle de composition + connexion SMTP). On ne peut pas la fermer sans tenir un verrou pendant l'envoi, ce qui est exclu.
2. **Pas de borne « un seul e-mail »** : la borne **raisonnée** est « **au plus un message par distributeur actif au sens de G2** au moment de la validation » (chaque distributeur traite un message à la fois). Avec D passages chevauchants, jusqu'à D messages ; **cette borne n'est pas démontrée en production**, elle est **testée** par un transport instrumenté (§9, T-NOT-5) et ne figurera dans la documentation que **comme résultat de test**, avec la formulation ci-dessus.
3. **Après la remise au transport** : le message appartient à la chaîne SMTP ; une livraison tardive par le serveur de messagerie est **hors de notre contrôle**.
4. **Ligne `RESERVED` à la clôture** : laissée en l'état pour que l'état reste **exact** (si l'envoi a lieu, la ligne passe `SENT` ; sinon G1 ou G2 l'annule). Un bail expiré (plantage d'un processus) est repris par `findDispatchable` puis annulé par G1.
5. **Message déjà reçu** par le bénéficiaire avant la clôture : inchangé ; son lien est fermé par prédicat.

---

## 6. Statistiques : schéma, publication, permissions (correction n° 3, Q6)

### 6.1 Deux niveaux séparés

| Niveau | Contenu | Stocké ? | Visible ? |
|---|---|---|---|
| **Compteurs internes** (gestion) | comptes **exacts** : dossiers par statut, autorisations, demandes distinctes, dossiers par commune | **Oui**, dans `event_event.closure_statistics` (bloc `internal`) | **Jamais dans une interface**, un export ni Sonata ; lus seulement par le calculateur de publication et par la gestion (bordereau de purge) |
| **Statistiques publiées** (visibles) | valeurs **dérivées** des compteurs par la règle de secret statistique, **par profil de lecteur** | **Non** : **calculées à la lecture** à partir des compteurs et du seuil courant | Panneau « Bilan de clôture » de la fiche de l'événement, selon les permissions du §6.4 |

Conséquence : changer le seuil provisoire (5) **ne demande aucune migration ni recalcul** ; la règle appliquée est un paramètre.

**Réserve à porter au DPO** : des compteurs **exacts** et petits (par exemple « 1 refus ») conservés **après la purge** des dossiers peuvent rester identifiants. Proposition pour le 05.6.4 : à la purge du dernier dossier de l'événement, le bloc `internal` est **supprimé** et seul un **instantané publié** (seuillé) est conservé. Ceci est consigné comme **dépendance du 05.6.4**, non traité ici.

### 6.2 Schéma JSON strict (version 1)

```json
{
  "v": 1,
  "internal": {
    "requests": 37,
    "authorizations": 40,
    "municipalities": [
      {"id": "0192a5f0-…", "approved": 20, "refused": 6, "pending": 4, "authorizations": 20}
    ]
  }
}
```

Règles de validation (refus de l'écriture sinon) :
- clés **exactement** celles ci-dessus (aucune autre : ni nom, ni plaque, ni texte, ni identifiant de demande, de dossier, de laissez-passer ou d'utilisateur) ;
- `v` = 1 ; entiers ≥ 0 ; `id` = UUID d'une commune **participant à l'événement** (organisation, pas une personne) ;
- cohérence : `authorizations` global = Σ des `authorizations` communales ; `authorizations` communale ≤ `approved` ; `requests` ≤ Σ des dossiers ; une commune par `id` ;
- taille bornée (≤ 64 communes, ≤ 8 Ko) ;
- **écriture unique** : refusée si déjà renseigné ou si l'événement n'est pas clos ; jamais recalculée.

Les totaux (dossiers par statut globaux) sont **dérivés** des lignes communales : ils ne sont pas stockés (une seule source).

### 6.3 Règles de publication (seuil `T` = 5, paramètre `retention.statistics_min_count`)

**Unité publiée = le dossier communal** (demande × commune) : les statuts sont propres à la commune. Le nombre de **demandes distinctes** reste **interne** (il est déductible du nombre de dossiers dans un événement à une commune, et trompeur dans un événement multicommunal).

Vues, **jamais cumulées pour un même lecteur** (voir §6.4) :

| Vue | Contenu publié | Règle |
|---|---|---|
| **V-global** (commune organisatrice) | `dossiers` (total), `parStatut` {approuvés, refusés, non décidés} + `autorisations`, et `communes` [{commune, `dossiers`}] | **R1** un total n'est publié que s'il vaut ≥ T, sinon `null`. **R2** `parStatut` + `autorisations` forment **un seul groupe** « tout ou rien » : publié seulement si **chaque cellule est ≥ T ou égale à 0** ; sinon **le groupe entier** est `null` (l'autorisation égale en pratique aux approbations : elle est masquée avec elles). **R3** `communes` est **tout ou rien** : publié seulement si **chaque** commune a `dossiers` ≥ T ; sinon `null` (pas de masquage d'une seule ligne, qui se déduirait par différence avec le total) |
| **V-commune** (commune non organisatrice) | **sa propre ligne seulement** : `dossiers`, `parStatut` + `autorisations` | mêmes R1 et R2, appliquées à sa ligne |

Déductions examinées et fermées :
1. **Total ↔ statuts** : `parStatut` n'est publié qu'avec toutes ses cellules (≥ T ou 0) : aucune cellule masquée ne peut se déduire par différence avec `dossiers`.
2. **Statuts ↔ autorisations** : même groupe, mêmes règles : l'un ne se déduit pas de l'autre.
3. **Global ↔ communes** : `communes` tout ou rien (R3) ; **aucune ventilation par statut par commune** n'est publiée à l'organisateur, de sorte que `parStatut` global moins un `parStatut` communal ne se calcule pas.
4. **Lecteurs différents** : une commune non organisatrice ne voit **jamais** le global ni les autres communes : elle ne peut pas soustraire sa ligne d'un total. L'organisateur voit le global et les **totaux** communaux, mais **pas** les statuts de sa propre commune séparément (sauf événement à une seule commune, où le global les contient).
5. **Zéros** : une cellule à 0 est publiée avec son groupe (ce n'est pas une observation individuelle) ; **risque résiduel** (« aucun refus sur ≥ 5 dossiers » révèle que tous ont été approuvés) **soumis au DPO**.
6. **Dans le temps** : écriture unique, aucune comparaison de deux instantanés.
7. **Petits événements** : moins de T dossiers → aucun chiffre publié (c'est voulu).

**Preuve à l'appui** (voir T-STAT-6) : un test par **énumération** : pour des vecteurs de compteurs de petite taille, l'ensemble des vues **possiblement obtenues par un même lecteur** ne permet de resserrer **aucune cellule masquée** à une valeur unique entre 1 et T−1.

Aucune ventilation par catégorie de bénéficiaire ni par motif de refus (Q6 : pas de besoin démontré).

### 6.4 Permissions de consultation (statistiques multicommunales)

Nouvelle permission d'événement `ViewClosureStatistics` dans `EventPermission`/`EventAccessPolicy` (à vérifier à l'implémentation : rôles et appartenance de la liste de `ViewEvent`) :

| Lecteur | Voit |
|---|---|
| `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` de la **commune organisatrice** | **V-global** |
| `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` d'une **commune participante** (non organisatrice) | **V-commune** (sa ligne seule) |
| `INSTRUCTOR` | rien |
| CWS (sans appartenance) | rien (aucun droit communal implicite) |
| Commune non participante, identifiants forgés | refus uniforme (même réponse qu'un événement inexistant) |

Hors événement clos : aucune statistique (champ `null`).

À valider : l'`EVENT_MANAGER` voit-il les statistiques sans désignation (option B) ? Proposition : **oui**, les statistiques ne sont pas des données personnelles ni des archives ; la désignation explicite ne vaut que pour l'accès aux dossiers archivés (05.6.3).

### 6.5 Calcul à la clôture

Requêtes ensemblistes SQL (un `GROUP BY` sur `request_permit_request_municipality` par événement ; un décompte d'autorisations par commune), **après** les verrous (les décisions concurrentes, qui prennent le verrou de participation, sont terminées) ; `requests` = `COUNT(DISTINCT request_id)`. **Lecture verrouillante** non nécessaire (les écrivains sont exclus par les verrous X de participation) : à **prouver** par T-CLO-4.

---

## 7. Migration M1 (correction n° 4) : préparation, **non exécutée**

### 7.1 Classe Doctrine exacte envisagée (`src/Migrations/Version2026111XXXXXXX.php`, nom définitif attribué par `doctrine:migrations:generate` **après** votre accord)

```php
<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.2 (DM-120 D4, M1) : `event_event.closure_statistics`, compteurs internes de clôture (JSON nullable, écriture unique).
 *
 * Données existantes : aucune ligne n'est lue, modifiée ni déplacée ; NULL signifie « non calculées ».
 * Retour arrière : voir `down()` : refusé dès qu'une statistique existe (donnée non reconstituable après une purge) ; on restaure alors une sauvegarde.
 */
final class Version2026111XXXXXXX extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.2 : compteurs internes de clôture (event_event.closure_statistics).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $recorded = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_event WHERE closure_statistics IS NOT NULL');
        $this->abortIf($recorded > 0, sprintf('%d événement(s) portent des compteurs de clôture : le retour arrière les détruirait. Restaurez une sauvegarde de la base à la place.', $recorded));
        $this->addSql('ALTER TABLE event_event DROP closure_statistics');
    }
}
```

SQL exact (accepté comme base) : `ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL`. Le texte définitif sera celui de `doctrine:migrations:diff` (Doctrine produit ce SQL pour `Types::JSON` nullable) : **si la sortie diffère, je le dirai et je corrigerai avant de vous présenter la version finale**.

### 7.2 Entité

`Event` : `#[ORM\Column(type: Types::JSON, nullable: true)] private ?array $closureStatistics = null;` ; `recordClosureStatistics(ClosureStatistics $statistics)` (objet valeur validant le §6.2 ; refus si non clos ou déjà renseigné) ; `closureStatistics(): ?ClosureStatistics`. Aucun index ni FK.

### 7.3 Événements déjà clôturés

Colonne à `NULL` : l'interface affiche « Bilan non disponible pour cet événement » ; **aucune commande de rattrapage** (Q8). Aucune donnée de production n'existe (bases de développement et de test seulement).

### 7.4 Tests de la migration (avant toute exécution, sur base jetable)

1. `doctrine:schema:validate` : synchronisé **après** `up` ;
2. `up` puis `down` sur base jetable : retour au schéma initial ;
3. `down` **refusé** si une statistique existe ;
4. aucune ligne existante modifiée (comparaison de sommes de contrôle avant et après) ;
5. `make test-db`, suite complète.

### 7.5 Retour arrière et sauvegardes

Sauvegarde préalable de `civipass` et `civipass_test` (commande à fournir) ; colonne nullable, ajout **en ligne** (algorithme `INSTANT` possible sur MySQL 8.4, à confirmer à l'exécution) ; `down()` refusé s'il existe des compteurs ; **exécution : base de test d'abord, puis développement, chacune sur accord distinct**.

---

## 8. Fichiers et services à modifier ou créer

| Élément | Action | Étape |
|---|---|---|
| `src/Event/Application/EventOperationalGuard.php` | **nouveau** : `isOpen`, `assertOpen`, exception `ClosedEventAccess` | 1 |
| `src/Event/Application/ClosedEventResolver.php` + `src/Event/Infrastructure/DbalClosedEventResolver.php` | **nouveau** : événement d'une (commune, demande / laissez-passer / justificatif) par SQL | 1 |
| `src/Event/UI/Http/ClosedEventBarrier.php` + exceptions HTTP + `templates/municipal/event_closed.html.twig` | **nouveau** : écouteur, page 410 | 1 |
| `config/services.yaml` (paramètres) | motifs gardés, **exemptions justifiées**, préfixes réservés | 1 |
| `MunicipalRequestReader`, `DoctrineMunicipalRequestReadModel`, `DirectIssuanceReader`, `RequestIdentityCorrectionService`, `PermitRightsOverviewReader`, `PermitRevisionGuard`, `PermitAccessService`, `BeneficiaryPassReader`, `AccessExpiry` | gardes métier (liste §3.3) | 2 |
| `RequestSubmissionService` | contrôle d'ouverture avant le rejeu (N1) | 2 |
| `EventLifecycleService::close` | révocation des invitations, annulation des `PENDING`, suppression des secrets, compteurs d'audit | 3 |
| `OutboxErrorCode::EventClosed` ; `NotificationFacts` + `NotificationFactsReader` (`eventClosed`) ; `NotificationDispatcher` (G1, G2) ; `SecretProvisioner` | notifications | 3 |
| `NotificationOutboxRepository` (lecture verrouillante des `PENDING` d'un événement) ; `DoctrineNotificationOutboxRepository` | requête ensembliste | 3 |
| `InvitationLinkRepository` | révocation ensembliste des liens non consommés d'un événement | 3 |
| `Event` + migration M1 + `ClosureStatistics` (valeur objet) + `ClosureStatisticsCalculator` + `ClosureStatisticsPublisher` (vues) + `EventPermission::ViewClosureStatistics` + `EventDetailReader` + gabarit « Bilan de clôture » | statistiques | 4 et 5 |
| `docs/security.md` (L5-34), `docs/exploitation-notifications.md` (`EVENT_CLOSED`, limites §5.4), `docs/lot-05-6-cadrage.md` (état), `CONTRADICTIONS.md` (statuts) | documentation | 6 |
| `tests/…` | voir §9 | à chaque étape |

Aucun changement Docker, Makefile, version ou dépendance.

---

## 9. Ordre des développements et tests associés

Entre étapes : `bin/qa lint` puis `bin/qa changed`. **Une seule passe `bin/qa full` en fin de lot**. Concurrence : scripts `tests/Support/*.php` (ParallelPhp) sur MySQL réel.

### Étape 1 — Socle de la barrière (aucune migration)
Guard, résolveur, écouteur, page 410, configuration, exemptions.
- **T-BAR-1** matrice exhaustive : toutes les routes du routeur classées (gardée / exemptée justifiée / publique avec test) ; une route factice non classée **fait échouer** le test ;
- **T-BAR-2** pour chaque route gardée × chaque rôle (`INSTRUCTOR`, `EVENT_MANAGER`, `MUNICIPALITY_ADMIN`, cumul de rôles, CWS membre) × méthodes (GET, POST forgé, HEAD) : **410** neutre sur événement clos, **corps sans aucune donnée** (nom, plaque, référence, motif absents) ; même routes **servies** sur événement actif (non-régression) ;
- **T-BAR-3** identifiants forgés : autre commune, demande d'un événement actif d'une autre commune, non-membre : **refus uniforme indiscernable** (statut, corps, en-têtes) de « inexistant » ; jamais « clos » ;
- **T-BAR-4** téléchargement direct d'un justificatif, d'un PDF, d'un QR : 410 ;
- **T-BAR-5** chemins réservés : routes factices `/archives/` et `/retention/` **non gardées** par la couche A ; test d'architecture (§3.4) ;
- **T-BAR-6** opérations techniques non bloquées : commandes `app:notifications:*`, clôture d'un événement déjà en cours de purge de secrets.

### Étape 2 — Gardes métier et correction N1
- **T-BAR-7** chaque service de la liste §3.3 appelé **directement** (sans HTTP) sur événement clos : refus ; sur événement actif : inchangé ;
- **T-BAR-8** lien bénéficiaire (page et PDF) : servi avant, **404 identique à un lien inconnu** après ; `AccessExpiry` : grâce conservée **seulement** pour un événement non clos ;
- **T-BAR-9** QR public : « Événement terminé » seul, aucun nom, plaque ni zone ; jeton retiré 410 ; inconnu 404 ; dépôt public refusé ;
- **T-N1** rejeu d'idempotence : avant la clôture, rejoué normalement ; **après**, refusé (avec et sans pièces jointes ; dépôt public **et** délivrance directe) ; aucune référence ni décision renvoyée.

### Étape 3 — Clôture étendue : liens et notifications
- **T-CLO-1** clôture multicommunale (3 communes, dossiers dans tous les statuts) : participations closes ; invitations non consommées révoquées, consommées intactes ; un seul audit `EVENT_CLOSED` avec compteurs, **sans donnée personnelle** ; clôture depuis `DRAFT`, `ACTIVE`, `SUSPENDED` ;
- **T-CLO-2** seconde clôture refusée, **aucune** mutation, aucun audit, aucune statistique en double ;
- **T-CLO-3** `request_idempotency_key` **inchangée** par la clôture ; réinitialisation de mot de passe inchangée ;
- **T-NOT-1** `PENDING` ordinaires **et prioritaires** → `SUPPRESSED/EVENT_CLOSED` ; `SENT`, `ABANDONED`, `NO_RECIPIENT`, `SUPPRESSED` inchangés ; secrets supprimés ; liens jamais remis révoqués ;
- **T-NOT-2** G1 : une ligne `PENDING` d'un événement clos n'est jamais envoyée (y compris `PERMIT_ISSUED` : **aucun lien créé**) ; réessai d'un message en vol revenu `PENDING` annulé ;
- **T-NOT-3** G2 : événement clos entre `reserve()` et l'envoi (transport instrumenté qui déclenche la clôture) : aucun envoi, ligne `SUPPRESSED/EVENT_CLOSED` ;
- **T-NOT-4** **concurrence** : clôture simultanée à un producteur (révocation, zones, plaques, période, remplacement du QR, notification manuelle, `PermitIssuer`) : soit la ligne est créée **avant** et annulée, soit l'opération est refusée ; jamais de `PENDING` orphelin ; **aucun interblocage** (ordre événement → participations → lignes) ;
- **T-NOT-5** **plusieurs distributeurs** (3 processus `dispatchOne` en boucle) pendant la clôture, transport instrumenté horodatant `entrée dans send` : aucun envoi **commencé** après la validation de la clôture dont G2 n'ait pas précédé la validation ; le **nombre de messages passés après la clôture est mesuré et consigné** ; l'assertion porte sur « chaque envoi tardif a franchi G2 avant la validation », **jamais** sur un « au plus un » ;
- **T-NOT-6** réessais : message `SMTP_TEMPORARY` replanifié avant la clôture → annulé ensuite ; bail expiré (processus mort) → repris puis annulé ;
- **T-NOT-7** même clôture rejouée après une interruption partielle simulée : état final identique (G1 rattrape).

### Étape 4 — **Arrêt obligatoire** : présentation de M1 (SQL exact généré, résultat des tests du §7.4 sur base jetable, sauvegarde) et **accord distinct** avant exécution sur `civipass_test`, puis sur `civipass`.

### Étape 5 — Statistiques
Valeur objet, calculateur, éditeur de vues, permission, panneau.
- **T-STAT-1** exactitude des compteurs (statuts, dossiers, autorisations, communes, demandes distinctes) sur jeux multicommunaux ; événement vide ;
- **T-STAT-2** schéma strict : clé inconnue, identifiant de demande, chaîne libre, nombre négatif, UUID étranger, doublon de commune → **refus** ; **inspection** : aucune identité, plaque, référence, motif ;
- **T-STAT-3** écriture unique ; refus avant clôture ; aucune recalculation ;
- **T-STAT-4** seuil : 4, 5, 6 dossiers ; une cellule à 4 → groupe entier masqué ; zéros ; `communes` tout ou rien ; seuil lu de la configuration ;
- **T-STAT-5** permissions : organisatrice (V-global), participante (V-commune), `INSTRUCTOR`, CWS sans appartenance, commune étrangère avec identifiants forgés ; événement non clos : rien ;
- **T-STAT-6** **déductions** : énumération sur de petits vecteurs ; toutes les vues d'un même lecteur ne resserrent **aucune** cellule masquée à une valeur entre 1 et T−1 ; une commune participante ne peut reconstituer ni le global ni une autre commune ;
- **T-STAT-7** événement clos avant M1 (colonne `NULL`) : « Bilan non disponible », aucune erreur ;
- **T-STAT-8** clôture et statistiques **dans la même transaction** : un échec de calcul annule la clôture (aucun état mixte).

### Étape 6 — Documentation et clôture du lot
`security.md` (L5-34 : lien fermé **à la clôture**, plus d'expiration 30 jours pour un événement clos), `exploitation-notifications.md` (`EVENT_CLOSED`, protocole, limites §5.4 formulées **comme résultats de test**), cadrage, DM-120. **Statut des contradictions** : L5-34 passe à « résolue » **uniquement si** T-BAR-8 et la correction de `security.md` sont livrés et verts ; L5-33 et L5-35 **restent ouvertes**. Puis `bin/qa full`, `verify-docs.sh`, validation Doctrine, `git diff`, compte rendu.

### Critères d'acceptation du lot
1. T-BAR-1 à 9 et T-N1 verts ; aucune route nouvelle non classée ;
2. aucune donnée personnelle servie après clôture, pour aucun rôle, y compris par rejeu ;
3. notifications : T-NOT-1 à 7 verts ; limites §5.4 documentées comme **résultats mesurés** ;
4. statistiques : T-STAT-1 à 8 verts, deux niveaux séparés, seuil provisoire configurable ;
5. M1 présentée, validée, exécutée sur accord (test puis développement) ;
6. aucune régression sur les événements actifs ; `bin/qa full` vert ;
7. **aucun déploiement en production avant livraison et validation du 05.6.3** (Q7).

---

## 10. Points à valider avant de commencer

| # | Question | Proposition |
|---|---|---|
| P1 | Barrière **déduite de la forme des routes** avec exemptions fermées et test de matrice (au lieu d'une liste de motifs) | Oui |
| P2 | Correction **N1** (refuser le rejeu sur événement clos) : changement de comportement sur le dépôt | Oui |
| P3 | Lignes `RESERVED` laissées en l'état à la clôture (trace exacte), annulées par G1/G2 | Oui |
| P4 | Compteurs internes **stockés exacts** (aucune interface), publication **dérivée à la lecture** ; suppression du bloc `internal` à la purge (dépendance du 05.6.4, à soumettre au DPO) | Oui |
| P5 | Unité publiée = dossier communal ; demandes distinctes **internes** ; organisateur : global + totaux par commune ; participante : sa ligne seule | Oui |
| P6 | `EVENT_MANAGER` voit les statistiques sans désignation (option B) | Oui |
| P7 | Les limites SMTP (§5.4) seront documentées **uniquement** comme résultats de test, sans garantie « un seul e-mail » | Oui |
