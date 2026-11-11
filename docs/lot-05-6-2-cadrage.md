# LOT 05.6.2 — Clôture étendue : cadrage technique (11 novembre 2026)

**Statut : cadrage seul. Aucune implémentation, aucune migration.** En attente de l'accord explicite du commanditaire. Références : [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §13, [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md), [rgpd-politique-conservation.md](rgpd-politique-conservation.md).

## 1. Rapport de pré-inspection (code au 11 novembre 2026)

### 1.1 Ce que fait la clôture aujourd'hui

`EventLifecycleService::close` (`src/Event/Application/Service/EventLifecycleService.php`) : verrou exclusif de l'événement puis de **toutes** les participations (ordre événement → participations), `Event::close` (statut `CLOSED`, `closedAt`), participations passées à `CLOSED`, audit `EVENT_CLOSED` et `PARTICIPATION_CLOSED` (même lot, même transaction). Elle **ne touche à aucune donnée** : demandes, plaques, justificatifs, jetons, outbox restent en l'état. Une **seconde clôture est refusée** (`DomainRuleViolation` : transition `CLOSED → CLOSED` interdite) sans aucune mutation.

### 1.2 Chemins qui servent encore des données personnelles après la clôture

Constat : le blocage actuel est **partiel et décentralisé** (chaque service teste `isOperational()` **pour les écritures**) ; les **lectures** ne sont presque jamais bloquées, et c'est volontaire dans le modèle actuel (« le dossier reste consultable », `MunicipalRequestReader::unavailableReason`).

| # | Chemin | Données exposées après clôture | État actuel | Action 05.6.2 |
|---|---|---|---|---|
| E1 | `GET /communes/{m}/demandes` (liste, filtre événement) | nom, référence, catégorie, statut | **Ouvert** : l'événement clos est même sélectionné par défaut (`firstWithStatus(… Closed)`) | Fermer ; la liste ne porte plus les lignes d'un événement clos |
| E2 | `GET /communes/{m}/demandes/{requestId}` (détail) | identité, contact, adresse, textes libres, plaques, décision, motif de refus | **Ouvert** | Fermer |
| E3 | `GET …/justificatifs/{attachmentId}` | **fichier** du justificatif | **Ouvert** (fichiers hors racine web : `var/storage`, seul accès par ce contrôleur) | Fermer |
| E4 | `…/approuver`, `…/refuser` | écrans de décision (contiennent le détail) | Écriture refusée par `isOperational()`, mais **la page se lit** | Fermer |
| E5 | `GET /communes/{m}/corrections-identite` et `…/demandes/{id}/identite` | recherche par référence, identité | Écriture refusée ; **lecture ouverte** (message « clôturée ») | Fermer |
| E6 | `GET /communes/{m}/delivrances/{requestId}` | confirmation de délivrance directe : identité, QR | **Ouvert** | Fermer |
| E7 | `…/droits`, `/droits/{revocation,zones,zones/libelles,vehicules,periode/*,qr}` | identité, plaques, historique des révisions, QR | Écritures refusées par les planificateurs ; **lecture ouverte** | Fermer |
| E8 | `…/laissez-passer` et `/liens`, `/notification`, `/liens/{id}/revoquer` | liens bénéficiaires, état d'envoi des notifications | **Ouvert** (émission d'un lien sur un événement clos à vérifier) | Fermer |
| E9 | `GET /laissez-passer/{secret}` et `/pdf` (lien bénéficiaire) | **nom, prénom, plaques, QR** | **Ouvert** pendant `AccessExpiry::GRACE_DAYS` = 30 jours après la fin (`AccessExpiry`) | Fermer **à la clôture** (décision 6, DM-114 amendé) |
| E10 | `GET /v/{token}` | rien (« Événement terminé ») | **Déjà conforme** (`PublicVerificationReader` : `EventClosed`, sans donnée) | Aucune ; garder le test |
| E11 | `GET/POST /demandes/{eventReference}…` (dépôt public) | page « fermée » sans donnée personnelle ; dépôt refusé sous verrou (`isOperational()`) | **Conforme** | Test de non-régression seulement |
| E12 | `AgentRequestController` (saisie municipale) | page `new_closed` sans donnée | **Conforme** | Idem |
| E13 | Sonata (`/admin`) | `MunicipalityAdmin`, `MembershipAdmin`, `UserAdmin` seulement : **aucune entité demande, laissez-passer, plaque ni justificatif** ; lecture seule ; `ROLE_SUPER_ADMIN` | **Aucune exposition** | Test « aucun admin ne porte d'entité de demande » (garde-fou futur) |
| E14 | Pages d'événement (`/evenements/{eventId}`, zones, communes) | aucune demande ni donnée personnelle (`src/Event` ne référence ni `Request` ni `Permit`) | Conforme | Aucune ; garder accessibles (statistiques) |
| E15 | Routes API | **aucune** (pas d'API Platform ni de routes JSON) ; `/dev/ui` seulement en `dev` | — | Test de matrice (§2.4) |
| E16 | Lecteur d'audit `AuditLogReader` | audit sans valeur personnelle (DT-037) ; **non branché sur une route ni un gabarit** | Aucune exposition | Aucune |
| E17 | Liens d'invitation | liés à une participation `INVITED` ; `InvitationLinkResolver` refuse déjà (participation `CLOSED` ou événement clos : `Inactive`) ; réinitialisation de mot de passe : **liée à l'utilisateur**, aucun lien d'événement | **Déjà neutralisés par l'état** | Révocation explicite optionnelle (§3.2) |

### 1.3 Notifications (outbox)

- L'outbox ne stocke **aucun destinataire ni contenu** (identifiants de laissez-passer, d'autorisation, de révision, type, dates, état) : les traces sont **sans donnée personnelle** (à reconfirmer à l'implémentation). Le message est composé et le destinataire résolu **à l'envoi**.
- États : `PENDING`, `RESERVED`, `SENT`, `ABANDONED`, `NO_RECIPIENT`, `SUPPRESSED`. L'envoi SMTP se fait **hors transaction**, sous bail.
- **Faille identifiée** : le distributeur ne tient **pas** compte de la clôture. Les messages **prioritaires** (`AUTHORIZATION_REVOKED`, `QR_REPLACED`, retraits de zones et de plaques, `PERMIT_SHORTENED`) sont jugés « exacts » même sans droit (`accurate`) : ils seraient **envoyés après la clôture**, avec le lien bénéficiaire.
- Le secret du lien automatique (`notification_secret`) est chiffré, 48 h au plus, supprimé à l'état définitif.

### 1.4 Autres constats

- Aucun point d'entrée commun aux écrans agents : **chaque lecteur résout sa demande à sa manière** (`MunicipalRequestReader`, `DirectIssuanceReader`, `RequestIdentityCorrectionService`, `PermitRightsOverviewReader`, `PermitAccessService`). Un contrôle « à la source » demanderait ~8 modifications ; un oubli futur serait silencieux.
- `AccessExpiry` ajoute 30 jours de grâce **après** la fin ou la clôture : à restreindre aux événements **non clos**.
- `request_idempotency_key` : empreintes seules, FK vers événement et demande ; le dépôt est déjà refusé **avant** la relecture de la clé (`isOperational()` d'abord), la clé n'a donc plus d'usage après la clôture.

## 2. Barrière centrale de clôture (proposition)

### 2.1 Principe

**Deux couches, une seule règle** : « un événement clos n'ouvre plus aucun accès opérationnel, quel que soit le rôle ».

1. **Barrière HTTP centrale (principale)** : un écouteur `kernel.controller` (`ClosedEventBarrier`) appliqué à toutes les routes d'exploitation d'une commune, **classées dans un seul fichier de configuration** (liste de motifs de noms de routes, `civipass.closed_event.guarded_routes`). Aucun contrôleur n'a à penser à la clôture ; une URL directe, un téléchargement ou un POST forgé passent tous par l'écouteur.
2. **Prédicat applicatif (défense en profondeur)** : un service unique `EventOperationalGuard` (`isOpenForOperations`, `assert…`) appelé par les **lectures qui n'ont pas d'identifiant de demande dans l'URL** (liste des demandes, recherche d'identité) et par les services qui peuvent être appelés hors HTTP. Il **ne bloque pas** : la purge, l'archivage, les commandes console et le futur module d'archive n'y passent pas.

### 2.2 Fonctionnement de l'écouteur (sans fuite d'existence)

1. Route non classée « gardée » → rien.
2. Route gardée : utilisateur sans **appartenance effective** à `{municipalityId}` (`MunicipalityContext`) → **on laisse passer** : le contrôleur donne son refus **uniforme** habituel (un identifiant forgé n'apprend jamais « clos »).
3. Appartenance valide : recherche de l'événement par la **portion communale** (`request_permit_request_municipality` × demande × commune) ; absence → on laisse passer (même refus uniforme).
4. Événement clos : réponse **410** avec une page sans aucune donnée (« Cet événement est clos : les données ne sont plus consultables ici »), `Cache-Control: no-store`, identique pour tous les rôles (`INSTRUCTOR`, `EVENT_MANAGER`, `MUNICIPALITY_ADMIN`, CWS en tant que membre). **Pas d'audit par tentative** (bruit) ; compteur de sécurité seulement.

Un `403` n'est pas retenu (le rôle n'est pas en cause) ; un `404` brouillerait l'information pour un agent légitime.

### 2.3 Fermetures hors écouteur

| Point | Traitement |
|---|---|
| `BeneficiaryPassReader::find` (page et PDF) | Événement clos → `null` (mêmes réponse et 404 qu'un lien inconnu, **sans** changer les lignes de jeton, qui restent pour l'archive) |
| `AccessExpiry` | La grâce de 30 jours ne s'applique **qu'aux événements non clos** |
| Liste des demandes, recherche d'identité | Le modèle de lecture marque l'événement comme clos : **aucune ligne** n'est servie (l'événement reste dans le sélecteur, avec le libellé « clos »). Plus de sélection par défaut d'un événement clos |
| `PublicVerificationReader` | Inchangé (déjà neutre) |
| Dépôt public et saisie municipale | Inchangés (déjà conformes) |

### 2.4 Garde-fou contre l'oubli : test de matrice des routes

Un test parcourt **toutes** les routes du routeur : toute route dont le chemin contient `{requestId}`, `{permitId}` ou `{attachmentId}`, ou qui sert un laissez-passer, doit être **soit gardée, soit déclarée exemptée avec justification** (liste fermée). Une route nouvelle non classée **fait échouer** la suite. C'est ce test, plus que l'écouteur, qui garantit la propriété « non contournable ».

### 2.5 Ce qui n'est pas bloqué

Purge et archivage futurs (services, commandes, pas de route), module d'archive du 05.6.3 (**préfixe de routes distinct**, habilitation propre, hors liste des routes gardées), pages d'événement et statistiques, page publique « Événement terminé », administration Sonata (aucune donnée de demande).

## 3. Notifications et liens

### 3.1 Notifications en attente

**À la clôture (même transaction, après les verrous événement → participations, avant la fin)** : toutes les lignes `PENDING` des laissez-passer de l'événement passent à `SUPPRESSED` avec le **nouveau code `EVENT_CLOSED`** (colonne chaîne : aucun changement de schéma ; code ≤ 24 caractères, comme `SUPERSEDED_BY_REVOCATION`). Aucun contenu n'est conservé ; les lignes `SENT`, `ABANDONED`, `NO_RECIPIENT`, `SUPPRESSED` sont **conservées** (traces, sans donnée personnelle). Les secrets chiffrés (`notification_secret`) des laissez-passer de l'événement sont **supprimés**. L'audit `EVENT_CLOSED` reçoit **le nombre** de notifications annulées (aucun identifiant de personne). Le verrouillage des lignes de l'outbox est pris **en dernier** (ordre documenté : événement → participations → zones → laissez-passer → lignes).

**Décision à confirmer** : les messages **prioritaires** en attente (révocation, remplacement du QR…) sont **aussi** annulés. Argument : le lien bénéficiaire est fermé, les droits sont terminés, l'information devient sans objet et le message porterait un lien désormais mort. Contre-argument : un bénéficiaire pourrait ne pas apprendre une révocation survenue juste avant la clôture ; l'événement étant clos, l'impact opérationnel est nul. **Recommandation : annuler**.

### 3.2 Concurrence clôture ↔ émission

| Situation | Résultat |
|---|---|
| Message `PENDING` pas encore réservé à la clôture | Annulé par la clôture (`SUPPRESSED`, `EVENT_CLOSED`) ; le distributeur le voit non distribuable (`skipped`) |
| Distributeur entre `findDispatchable` et `reserve` pendant la clôture | `reserve` verrouille la ligne : soit la clôture est passée (ligne annulée → `skipped`), soit la clôture attend le verrou de la ligne puis l'annule |
| Message **déjà `RESERVED`** (envoi SMTP en vol) à la clôture | **Laissé finir** (au plus un message parti « à l'instant de la clôture ») ; s'il échoue et repasse `PENDING`, le **garde du distributeur** l'annule |
| Message en réessai (`PENDING`, `available_at` futur) | Annulé à la clôture (c'est un `PENDING`) |
| Production d'une révision/notification **après** la clôture | Impossible : toute écriture de droits est refusée sur événement clos (planificateurs, `isOperational`) ; le **garde du distributeur** couvre le reste |

**Garde du distributeur** (nouveau, en tête de `dispatchOne`) : événement du laissez-passer clos → `suppress(EVENT_CLOSED)` **pour tout type**, y compris prioritaire, avec purge du secret. Il rend la clôture robuste **même si** la mise à jour en masse était partielle (reprise après incident).

### 3.3 Liens

| Lien | Traitement à la clôture |
|---|---|
| Lien bénéficiaire (`permit_access_token`) | Fermé par **prédicat** (§2.3), lignes **conservées** (preuve, archive) ; aucune révocation en masse (pas d'écriture inutile) |
| Lien d'invitation | **Déjà inactif** (participation `CLOSED`, événement clos). Révocation explicite (`revokedAt`) des liens **non consommés** de l'événement, auditée par un compteur, pour que l'état soit lisible en base |
| Réinitialisation de mot de passe | **Hors périmètre** : lien lié à l'utilisateur, aucun lien d'événement |
| Clés d'idempotence du dépôt | Supprimées à la clôture (empreintes sans usage ; FK `RESTRICT` : suppression par requête dédiée) — **point à confirmer** : alternative, les supprimer à la purge |
| Comportement public | Inchangé : « Événement terminé » (200), 410 jeton retiré, 404 inconnu |

## 4. Statistiques anonymes (`closure_statistics`)

### 4.1 Principes

Calculées **une seule fois**, dans la transaction de clôture, **après** les verrous (les décisions concurrentes sont sérialisées par les verrous de participation) ; **jamais recalculées**, jamais modifiées (écriture unique) : aucune différence entre deux instantanés. Aucune identité, plaque, texte libre, référence de demande ou identifiant de dossier. Les identifiants de **communes** (organisations, pas des personnes) sont admis.

### 4.2 Définitions

- **Demande** : demande distincte ; **dossier communal** : couple (demande × commune). Les statuts sont ceux des **dossiers** (le statut est propre à la commune) ; une demande touchant deux communes compte pour 2 dossiers.
- **Autorisation délivrée** : autorisation créée (révoquée ou non : le chiffre mesure la délivrance, pas l'état final).
- Demandes **non décidées** = dossiers `PENDING` à la clôture.

### 4.3 Structure proposée

```json
{
  "v": 1,
  "threshold": 5,
  "requests": 37,
  "dossiers": {"total": 52, "approved": 40, "refused": 9, "pending": 3},
  "authorizations": 40,
  "municipalities": [{"id": "0192…", "dossiers": 30, "authorizations": 24}],
  "suppressed": ["dossiers.byStatus"]
}
```

Les valeurs **masquées** sont `null` ; `suppressed` liste, à titre informatif, les dimensions entièrement masquées. Aucune autre clé n'est admise (validation stricte à l'écriture).

### 4.4 Règle de secret statistique (seuil provisoire : 5)

| Règle | Contenu |
|---|---|
| **S1 — total** | Un total (demandes, dossiers, autorisations) est publié s'il est **≥ 5**, sinon `null` |
| **S2 — dimension tout ou rien** | Une **dimension** (répartition par statut ; répartition par commune) n'est publiée que si **chacune de ses cellules non nulle vaut ≥ 5**. Sinon la dimension **entière** est masquée (`null`) et listée dans `suppressed`. Pas de suppression « d'une seule cellule » |
| **S3 — zéro** | Une cellule à **0** n'est pas une observation individuelle : elle est publiée **si** la dimension l'est. Risque résiduel signalé : « aucun refus » sur ≥ 5 dossiers révèle que tous ont été approuvés (attribut commun au groupe) ; **à soumettre au DPO** |
| **S4 — pas de croisement** | Aucun croisement (commune × statut, catégorie × motif…). La répartition par catégorie et par motif de refus du cadrage v2.2 est **retirée de M1** (aucun besoin démontré ; sujet à reconsidérer) |
| **S5 — déduction par différence** | (a) Masquage **tout ou rien** : on ne peut déduire une cellule masquée par différence avec un total. (b) Les totaux de dimension ne sont **jamais** publiés séparément s'ils permettraient la déduction (les « total » sont publiés seulement s'ils sont ≥ 5, et une dimension masquée n'expose aucune cellule). (c) Le **total par commune** et le total global ne sont croisés qu'au même niveau de masquage. (d) Écriture unique : aucune comparaison de deux instantanés |
| **S6 — petits effectifs** | Événement à moins de 5 demandes : seuls les totaux ≥ 5 éventuels sont publiés ; l'événement n'a presque pas de statistique, **c'est voulu** |
| **S7 — paramètre** | Le seuil est un paramètre (`retention.statistics_min_count`, défaut 5) **enregistré dans le JSON** (`threshold`) pour que la règle appliquée reste lisible |

Cas limite acceptable : événement de 4 demandes → aucune statistique chiffrée.

## 5. Migration M1 (À VALIDER AVANT EXÉCUTION — non exécutée)

### 5.1 SQL exact (MySQL 8.4 ; généré selon le modèle de Doctrine ; à régénérer par `doctrine:migrations:diff` après validation)

```sql
-- up()
ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL;

-- down()
ALTER TABLE event_event DROP closure_statistics;
```

### 5.2 Entité Doctrine (`App\Event\Domain\Event`)

- Nouveau champ : `#[ORM\Column(type: Types::JSON, nullable: true)] private ?array $closureStatistics = null;`.
- Méthode `recordClosureStatistics(array $statistics): void` : **écriture unique** (refusée si déjà renseigné ou si l'événement n'est pas clos), clés validées (schéma §4.3, aucune clé inconnue, aucune valeur de type chaîne libre).
- Lecteur `closureStatistics(): ?array`. Aucune relation, aucune clé étrangère.

### 5.3 Type et contraintes

`JSON` nullable, **sans valeur par défaut**, **sans index** (jamais filtré). Aucune contrainte `CHECK` (la validité est portée par l'entité). `NULL` signifie « non calculées ».

### 5.4 Événements déjà clôturés

Aucune ligne n'est modifiée : `NULL`. **Aucune donnée de production n'existe** (bases de développement et de test seulement). Si besoin : commande explicite `app:event:closure-statistics --backfill` (**simulation par défaut**), calculant depuis les données **encore présentes** (jamais après purge) ; hors M1, à valider séparément.

### 5.5 Sauvegardes et retour arrière

- Ajout d'une colonne nullable : opération en ligne (`ALGORITHM=INSTANT` possible sur MySQL 8), sans verrou prolongé ; **sauvegarde préalable** de `civipass` et `civipass_test`.
- `down()` : **refusé** dès qu'une statistique existe (convention des LOT 05.5.1 et 05.5.2 : ne pas détruire une donnée non reconstituable ; après une purge, elle ne l'est plus) ; sinon `DROP COLUMN`.
- Les sauvegardes antérieures ne contiennent pas la colonne : restauration sans effet sur le code (champ nullable).
- Exécution : **base de test d'abord, puis développement, chacune sur accord explicite** (règle du projet).

### 5.6 Tests de non-régression de la migration

Schéma synchronisé (`doctrine:schema:validate`), `make test-db`, suite complète, round-trip `up`/`down` sur une base jetable, vérification qu'aucune ligne existante n'est modifiée.

## 6. Modifications de code envisagées (à l'implémentation)

| Fichier | Modification |
|---|---|
| `EventLifecycleService::close` | statistiques + annulation des notifications `PENDING` + suppression des secrets + révocation des liens d'invitation + suppression des clés d'idempotence, **dans la transaction existante** ; payload d'audit étendu (compteurs) |
| `Event` | champ `closureStatistics` (M1) |
| nouveau `ClosureStatisticsCalculator` (Event ou Shared) | agrégats par SQL, règle S1 à S7, schéma strict |
| nouveau `ClosedEventBarrier` + configuration des routes gardées | §2 |
| nouveau `EventOperationalGuard` | prédicat unique |
| `DoctrineMunicipalRequestReadModel`, `MunicipalRequestReader`, `RequestIdentityCorrectionService` | l'événement clos ne sert aucune ligne |
| `BeneficiaryPassReader`, `AccessExpiry` | §2.3 |
| `NotificationDispatcher`, `OutboxErrorCode::EventClosed` | garde de clôture |
| `templates/municipal/event_closed.html.twig` | page 410 sans donnée |
| `docs/security.md` | **correction de L5-34** : lien bénéficiaire **fermé à la clôture** (plus d'expiration 30 jours après la fin, sauf événement non clos) ; mention de la barrière ; DM-114 déjà amendé |
| `docs/exploitation-notifications.md` | code `EVENT_CLOSED` |

Aucun changement Docker, Makefile, version ou dépendance.

## 7. Risques identifiés

| # | Risque | Parade |
|---|---|---|
| K1 | Route d'exploitation oubliée (nouvelle ou existante) | Test de matrice des routes (§2.4) ; liste unique ; échec sur toute route non classée |
| K2 | Fuite d'existence (« clos » révélé à un non-membre ou à un identifiant forgé) | Appartenance vérifiée **avant** de répondre « clos » ; sinon refus uniforme du contrôleur |
| K3 | Message envoyé après la clôture (priorité, réessai, vol) | Annulation en masse **et** garde du distributeur ; un message en vol peut partir (documenté) |
| K4 | Interblocage clôture ↔ distributeur ou décisions | Ordre de verrous documenté (outbox en dernier) ; test de concurrence réel MySQL |
| K5 | Clôture trop longue (grand événement) | Mises à jour **par requêtes ensemblistes**, pas par entité ; mesure sur un jeu volumineux |
| K6 | Réidentification par les statistiques | Règles S1 à S7, écriture unique, aucun croisement ; seuil à valider par le DPO |
| K7 | Perte d'une lecture légitime (agents qui consultaient les dossiers clos) | **Changement de comportement voulu** (DM-120) ; l'accès revient par le module d'archive (05.6.3). **Entre le 05.6.2 et le 05.6.3, plus aucun accès** : prévoir la communication et, si besoin, ne livrer le 05.6.2 en production qu'avec le 05.6.3 |
| K8 | Idempotence : double clôture | Déjà refusée sans mutation ; conservée par test |
| K9 | Régression sur événements actifs | Suite existante conservée ; tests ciblés |
| K10 | Colonne JSON mal alimentée | Écriture unique, validation stricte, test de schéma |

## 8. Plan de tests

**Clôture et multicommunal** : événement à 3 communes avec demandes dans tous les statuts → statuts exacts ; participations closes ; audit unique avec compteurs ; clôture depuis `DRAFT`, `ACTIVE`, `SUSPENDED` ; seconde clôture refusée, **aucune** seconde statistique ni audit.

**Accès direct après clôture (matrice routes × rôles × méthodes)** : toutes les routes du §1.2 (E1 à E8) pour `INSTRUCTOR`, `EVENT_MANAGER`, `MUNICIPALITY_ADMIN`, membre multi-rôles, CWS membre : GET et POST forgés → **410** sans donnée (corps vérifié : aucun nom, plaque, référence, motif) ; téléchargement d'un justificatif refusé ; **identifiants forgés** (autre commune, événement actif d'une autre commune) → refus **uniforme**, indiscernable de « inexistant » ; non-membre : jamais « clos » ; test de matrice (route non classée = échec) ; routes `dev` absentes.

**Événements actifs (non-régression)** : mêmes routes **servies** normalement ; suite LOT 05.4 et 05.5 inchangée ; liste des demandes d'un événement actif intacte.

**Liens et QR** : lien bénéficiaire (page et PDF) : servi avant, **404 identique à un lien inconnu** après la clôture ; lignes de jeton conservées ; lien d'invitation non consommé : révoqué ; réinitialisation de mot de passe inchangée ; QR actif : « Événement terminé » seul, **aucun nom, plaque, zone** ; QR retiré : 410 ; inconnu : 404 ; dépôt public : page fermée, dépôt refusé.

**Notifications** : `PENDING` (ordinaire et prioritaire) → `SUPPRESSED/EVENT_CLOSED` ; `SENT` inchangé ; `RESERVED` laissé ; réessai d'un message en vol après clôture annulé par le garde ; secrets supprimés ; aucun message sorti après clôture (boîte MailCatcher) ; audit sans donnée personnelle ; **concurrence réelle** (`ParallelPhp`) : clôture simultanée à `dispatchOne` (avant réservation, entre réservation et envoi), à une décision, à un dépôt, à un remplacement de QR : jamais d'interblocage, jamais d'état incohérent.

**Statistiques** : exactitude (statuts, dossiers, autorisations, communes) ; événement vide ; **4, 5, 6 demandes** (seuil) ; une cellule à 4 → dimension masquée entière ; zéros ; déduction par différence impossible ; aucune clé inconnue ; **aucun identifiant de dossier, nom, plaque, texte libre** dans le JSON (test d'inspection) ; écriture unique ; seuil lu de la configuration.

**Migration M1** : schéma synchronisé, round-trip `up`/`down`, `down` refusé avec statistiques, lignes existantes inchangées.

**Qualité** : `bin/qa lint`, `bin/qa changed` en cours de route ; `bin/qa full` obligatoire en fin ; `verify-docs.sh`.

## 9. Critères d'acceptation

1. Aucune route de la matrice ne sert de donnée personnelle sur événement clos, pour aucun rôle ; une route nouvelle non classée fait échouer les tests.
2. Réponses publiques inchangées (« Événement terminé », 410, 404) ; lien bénéficiaire fermé à la clôture.
3. Aucune notification n'est émise après la clôture (hors message déjà en vol, documenté) ; `PENDING` annulés avec trace sans contenu.
4. Statistiques présentes, conformes à S1 à S7, sans identifiant.
5. M1 validée **puis** exécutée sur accord explicite, base de test puis base de développement.
6. Aucune régression sur les événements actifs ; `bin/qa full` vert ; L5-34 corrigée dans `security.md`.

## 10. Décisions à confirmer avant l'implémentation

| # | Question | Recommandation |
|---|---|---|
| Q1 | Réponse **410** avec page neutre pour tous les rôles (au lieu de 403/404) | Oui |
| Q2 | Barrière HTTP centrale par liste de routes + test de matrice, plus prédicat applicatif | Oui |
| Q3 | Annuler **aussi** les notifications prioritaires en attente | Oui |
| Q4 | Supprimer les clés d'idempotence à la clôture (ou à la purge) | À la clôture |
| Q5 | Révoquer explicitement les liens d'invitation non consommés | Oui |
| Q6 | Seuil de 5, masquage « tout ou rien » par dimension, zéros publiés, catégories et motifs retirés de M1 | Oui |
| Q7 | **Ne pas livrer le 05.6.2 en production sans le 05.6.3** (période sans accès aux dossiers clos) | Oui |
| Q8 | Commande de rattrapage `--backfill` des statistiques : hors M1 | Hors lot sauf besoin |
