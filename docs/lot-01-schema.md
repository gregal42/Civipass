# Civipass — LOT 01 : conception du schéma (User, Municipality, MunicipalityMembership, AuditLog)

**Statut : LOT 01.1 implémenté et corrigé après revue indépendante (révision 5). Migration `Version20261102153833` exécutée sur la base de TEST uniquement ; la base de développement n'a reçu aucune migration. Pas d'interface Sonata, pas d'authentification complète (LOT 01.2).**

Périmètre : `User`, `Municipality`, `MunicipalityMembership` et le mécanisme d'audit minimal (`AuditLog`). `UserInvitation` et `MunicipalitySubscription` font l'objet d'un document séparé.

## 1. Décisions retenues

| # | Décision |
|---|---|
| D1 | `symfony/uid` installé (8.1.9). |
| D2 | Statuts communaux : `ACTIVE`, `SUSPENDED`, `INACTIVE`. |
| D3 | Contact de commune : nom, email, téléphone, adresse. |
| D4 | Mot de passe : 12 caractères minimum, sans règle de complexité artificielle. |
| D5 | Historisation dès le LOT 01 des changements de rôles communaux, de `Municipality.status` et de `User.superAdmin` (section 8). |
| D6 | Pas de verrouillage optimiste sur les memberships en V1. |
| D7 | `lastLoginAt` sur `User`. |
| D8 | Collation binaire (`utf8mb4_bin`) pour les identifiants normalisés : email et code INSEE. |
| D9 | Création d'événement : statut administratif `ACTIVE` **et** abonnement `ACTIVE`. Une commune non abonnée peut participer sur invitation si son statut administratif et les règles de participation le permettent. |
| D10 | Audit minimal et réutilisable au LOT 07 : table unique `audit_log` (section 8). |
| D11 | Téléphones : formats français usuels et E.164 acceptés, **stockés en E.164**, validation serveur. |
| D12 | Pas de `zenstruck/foundry` : builders de test internes. |
| D13 | `giggsey/libphonenumber-for-php-lite` installé pour la normalisation E.164 (métropole, outre-mer, international). |
| D14 | Base de test dédiée `civipass_test` via `.env.test.local` ; aucun test destructif ni migration sur la base de développement. |
| D15 | Le dernier superadmin actif ne peut être ni désactivé ni rétrogradé, **console comprise** ; récupération exceptionnelle = désignation d'un superadmin par la console, auditée. |
| D18 | Aucun compte ne peut être désactivé s'il laisse une commune `ACTIVE` sans administrateur effectif (toutes ses communes vérifiées, sous verrou). |
| D19 | Le contrôle du dernier administrateur ne compte que les utilisateurs ET memberships effectivement actifs (requête `COUNT`). |
| D20 | Contact de commune, activation/désactivation de comptes : transactionnels et audités (auteur, anciennes et nouvelles valeurs). |
| D17 | Commune `SUSPENDED` ou `INACTIVE` : ses autorisations sont inutilisables au contrôle ; invalidité temporaire, sans révocation, levée à la réactivation (section 13, implémentée avec Permit/Control). |
| D16 | Commune `SUSPENDED` : ni création d'événement ni nouvelle participation ; capacités opérationnelles suspendues sur les événements existants (section 13). |

Principes conservés : UUID v7 générés côté application en `BINARY(16)` ; rôles communaux cumulables en JSON validés par le domaine ; aucun rôle communal dans `User::getRoles()` ; aucune suppression physique des comptes ou des rattachements ; contrôle du dernier administrateur actif ; horodatages UTC ; séparation stricte statut administratif / abonnement / participation.

## 2. Compatibilité UUID v7 / `BINARY(16)` (vérifiée)

Versions installées : `doctrine/dbal` 4.5.0, `doctrine/orm` 3.7.6, `doctrine/doctrine-bundle` 3.3.4, `symfony/doctrine-bridge` 8.1.10, `symfony/uid` 8.1.9 (avec `symfony/polyfill-uuid` 1.43.0), MySQL 8.4.13.

- Le type Doctrine `uuid` (`Symfony\Bridge\Doctrine\Types\UuidType`) est **enregistré automatiquement** par DoctrineBundle. Aucune configuration à ajouter.
- Sur `MySQL84Platform`, la déclaration SQL est **`BINARY(16)`** ; l'aller-retour PHP ↔ base restitue un `UuidV7` identique ; les UUID générés successivement sont ordonnés dans l'ordre binaire.
- Mapping prévu : `#[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;` avec `Uuid::v7()` dans le constructeur (pas de `#[ORM\GeneratedValue]`).
- La base `civipass` a la collation par défaut `utf8mb4_0900_ai_ci` (insensible à la casse **et aux accents**), ce qui justifie D8.

## 3. Conventions communes

- **Modules** : `User` dans `src/Identity` ; `Municipality` et `MunicipalityMembership` dans `src/Municipality` ; `AuditLog` dans `src/Shared`. Dépendances : `Municipality → Identity` (relation du membership) ; `Shared` ne dépend d'aucun autre module (voir section 8).
- **Mapping** : attributs PHP, un mapping Doctrine par module, créé avec la première entité du module.
- **Tables** : `identity_user`, `municipality`, `municipality_membership`, `audit_log`.
- **Clé primaire** : `id`, type `uuid`, générée dans le constructeur. Un UUID n'est jamais un contrôle d'accès.
- **Horodatages** : `datetime_immutable`, UTC, via une horloge injectée (`Symfony\Component\Clock\ClockInterface`). PHP tourne en `Europe/Paris` et MySQL `DATETIME` n'a pas de fuseau : l'affichage en Europe/Paris relève de la couche UI.
- **Suppression** : aucune suppression physique ; clés étrangères en `ON DELETE RESTRICT`.
- **Classes** : `final`, constructeur imposant les invariants, pas de setters contournant le domaine.
- **Collation** : `email` et `inseeCode` en `utf8mb4_bin` (`options: ['collation' => 'utf8mb4_bin']`). Les recherches utilisent la valeur **déjà normalisée**.

## 4. Séparation statut administratif / abonnement / participation

| Notion | Porté par | Question | Document |
|---|---|---|---|
| **Statut administratif** | `Municipality.status` | La commune peut-elle être utilisée dans la plateforme ? | ce document |
| **Abonnement** | `MunicipalitySubscription` | La commune peut-elle **créer** un événement ? | document suivant |
| **Participation** | `EventParticipation` | La commune peut-elle agir **dans un événement donné** ? | LOT 02 |

Règles :
- Aucune de ces notions n'est déduite d'une autre ni stockée dans une autre entité.
- **Création d'événement (D9)** : statut `ACTIVE` **et** abonnement `ACTIVE`. Service `EventCreationPolicy`, testable sans entité `Event`, qui reçoit le statut communal et le statut d'abonnement (l'enum `SubscriptionStatus` est créée avec ce service ; l'entité d'abonnement vient plus tard).
- **Participation sur invitation** : une commune non abonnée peut participer si son statut administratif est `ACTIVE` et si les règles de participation (LOT 02) l'autorisent. Le LOT 01 ne code pas la participation ; il garantit seulement que l'absence d'abonnement n'est jamais un critère d'exclusion de la participation.
- Le droit effectif d'un agent combine les couches explicitement dans chaque cas d'usage, pas par héritage de statut.

## 5. `User`

Table `identity_user` — `App\Identity\Domain\User` (implémente `UserInterface`, `PasswordAuthenticatedUserInterface`, `EquatableInterface`).

| Attribut | Type Doctrine | Colonne MySQL | Null | Remarques |
|---|---|---|---|---|
| `id` | `uuid` | `BINARY(16)` | non | PK, UUID v7. |
| `email` | `string(180)` | `VARCHAR(180)` `utf8mb4_bin` | non | Toujours normalisé. Identifiant de connexion. |
| `password` | `string(255)` | `VARCHAR(255)` | non | Hash uniquement (hasher `auto`). |
| `firstName` | `string(100)` | `VARCHAR(100)` | non | |
| `lastName` | `string(100)` | `VARCHAR(100)` | non | |
| `active` | `boolean` | `TINYINT(1)` | non | Défaut `true`. |
| `superAdmin` | `boolean` | `TINYINT(1)` | non | Défaut `false`. Accesseur `isSuperAdmin()`. Superadmin CWS global. |
| `lastLoginAt` | `datetime_immutable` | `DATETIME` | oui | UTC. |
| `createdAt` / `updatedAt` | `datetime_immutable` | `DATETIME` | non | UTC. |

Contraintes : `PRIMARY(id)` ; `uniq_identity_user_email (email)` UNIQUE.

### Règles métier
1. **Normalisation de l'email** (`EmailAddress`) : trim, Unicode NFC, `mb_strtolower`. Pas de logique propre à un fournisseur.
2. **Unicité insensible à la casse** garantie par la normalisation **et** la collation binaire ; `josé@` et `jose@` restent distincts.
3. **Validité** : longueur ≤ 180, format valide, aucun espace ni caractère de contrôle.
4. **Mot de passe** : 12 caractères minimum, **aucune règle de complexité**, 4096 **octets** maximum (le hasher Symfony mesure en octets ; un mot de passe multioctet doit être refusé par la politique, pas échouer au hachage). Contrainte `Assert\Length` sur le DTO de création/changement ; le domaine ne reçoit qu'un hash. Pas de vérification de mots de passe compromis (appel réseau tiers).
5. **`getRoles()`** : `ROLE_USER` + `ROLE_SUPER_ADMIN` si `superAdmin`. **Aucun rôle communal.**
6. **Compte désactivé** : connexion refusée, sessions invalidées (`EquatableInterface` sur `email`, `password`, `active`, `superAdmin`), memberships ignorés.
7. **`lastLoginAt`** : mis à jour à chaque connexion réussie (écouteur ajouté avec l'authentification), requête ciblée sans toucher `updatedAt`. Donnée de compte, pas journal des connexions ; exclue de `EquatableInterface`.
8. **Changement de `superAdmin`** : uniquement par un superadmin ou la commande console, via `UserManager` (section 8). **Le dernier superadmin actif ne peut être ni rétrogradé ni désactivé, y compris par la console** (verrou pessimiste sur les superadmins actifs dans la transaction). **Récupération exceptionnelle** : si aucun superadmin actif n'existe (incident), la console peut en désigner un (`grantSuperAdmin`, audité `origin = CONSOLE`) ; il n'existe aucune opération de contournement de la protection. Aucun droit communal implicite pour un superadmin.
9. **Pas de compte sans mot de passe** ; **pas de suppression physique**.

## 6. `Municipality`

Table `municipality` — `App\Municipality\Domain\Municipality`.

| Attribut | Type Doctrine | Colonne MySQL | Null | Remarques |
|---|---|---|---|---|
| `id` | `uuid` | `BINARY(16)` | non | PK, UUID v7. |
| `name` | `string(150)` | `VARCHAR(150)` | non | |
| `inseeCode` | `string(5)` | `CHAR(5)` `utf8mb4_bin` | non | **Unique**, immuable. |
| `status` | enum `MunicipalityStatus` | `VARCHAR(20)` | non | Défaut `ACTIVE`. |
| `contactName` | `string(150)` | `VARCHAR(150)` | oui | |
| `contactEmail` | `string(180)` | `VARCHAR(180)` | oui | Normalisé comme `User.email`. |
| `contactPhone` | `string(16)` | `VARCHAR(16)` | oui | **E.164** (`+` + 15 chiffres au plus). |
| `addressLine` | `string(255)` | `VARCHAR(255)` | oui | |
| `postalCode` | `string(5)` | `CHAR(5)` | oui | |
| `city` | `string(100)` | `VARCHAR(100)` | oui | |
| `createdAt` / `updatedAt` | `datetime_immutable` | `DATETIME` | non | UTC. |

Contraintes : `PRIMARY(id)` ; `uniq_municipality_insee_code (inseeCode)` UNIQUE.

### Règles métier
1. **Code INSEE** : `^(\d{2}|2A|2B)\d{3}$`, majuscules, comparaison binaire, immuable.
2. Le nom n'est pas unique ; l'identité métier est le code INSEE.
3. **Statuts** : `ACTIVE` (normal) ; `SUSPENDED` (suspension temporaire et réversible : plus d'écriture par ses agents, lecture de l'historique conservée) ; `INACTIVE` (hors service : plus aucune action, historique conservé ; retour vers `ACTIVE` réservé au superadmin).
4. Le statut n'est ni l'abonnement ni une participation (section 4).
5. **Téléphone (D11)** : objet valeur `PhoneNumber` créé via un `PhoneNumberNormalizer`.
   - Entrées acceptées : séparateurs usuels (espaces, points, tirets, parenthèses) ; format national français (`01 23 45 67 89`) ; format international avec `+` ou `00` (`+33 1 23 45 67 89`, `0033…`, `+262…`) ; autres pays en E.164.
   - Sortie : toujours `+<indicatif><numéro>` sans séparateur ; l'original n'est pas conservé.
   - Un numéro non interprétable ou invalide est rejeté avec un message explicite.
   - **Dépendance à décider** (section 12) : la validation fiable de numéros internationaux et des numéros d'outre-mer (indicatifs +262, +590, +594, +596, etc., dont les numéros nationaux commencent par `0262`, `0590`…) suppose une bibliothèque telle que `giggsey/libphonenumber-for-php-lite`. Sans bibliothèque, seuls le format français métropolitain et la forme E.164 (`^\+[1-9]\d{6,14}$`) peuvent être vérifiés, sans contrôle de validité propre à chaque pays.
   - L'interface `PhoneNumberNormalizer` isole ce choix : on peut changer d'implémentation sans toucher au domaine.
6. **Contact** : tous les champs facultatifs ; email normalisé ; code postal `^\d{5}$` ; adresse cohérente seulement si `postalCode` et `city` sont renseignés ensemble.
7. **Écriture** : création, nom et statut réservés au superadmin ; l'admin de commune modifie le contact de **sa** commune via voter.
8. **Changement de statut** : uniquement par `MunicipalityManager::changeStatus()`, qui écrit l'audit (section 8). Aucun setter public.
9. **Suppression physique interdite**, y compris via Sonata.

## 7. `MunicipalityMembership`

Table `municipality_membership` — `App\Municipality\Domain\MunicipalityMembership`.

| Attribut | Type Doctrine | Colonne MySQL | Null | Remarques |
|---|---|---|---|---|
| `id` | `uuid` | `BINARY(16)` | non | PK, UUID v7. |
| `user` | `ManyToOne(User)` | `user_id BINARY(16)` | non | FK `identity_user.id`, `RESTRICT`. |
| `municipality` | `ManyToOne(Municipality)` | `municipality_id BINARY(16)` | non | FK `municipality.id`, `RESTRICT`. |
| `roles` | `json` | `JSON` | non | Liste de `MunicipalityRole`. |
| `active` | `boolean` | `TINYINT(1)` | non | Défaut `true`. |
| `createdAt` / `updatedAt` | `datetime_immutable` | `DATETIME` | non | UTC. |

`MunicipalityRole` : `MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR`. Pas de rôle de contrôleur, pas de hiérarchie.

Contraintes : `PRIMARY(id)` ; `uniq_membership_user_municipality (user_id, municipality_id)` UNIQUE ; `idx_membership_municipality (municipality_id)` (créé par la FK). L'index sur `user_id` est couvert par le préfixe de l'unicité. Relations `ManyToOne` unidirectionnelles, `fetch: LAZY`.

### Règles métier
1. Un seul membership par couple (utilisateur, commune), actif ou non ; la réactivation réutilise la ligne.
2. **JSON `roles`** : liste de chaînes uniques, triées, toutes dans `MunicipalityRole`. Valeur inconnue rejetée, y compris en lecture (erreur explicite, pas d'ignorance silencieuse).
3. Au moins un rôle pour un membership actif.
4. **Droit effectif** : `membership.active` **et** `user.active` **et** statut communal compatible (`ACTIVE` ; `SUSPENDED` = lecture seule). La distinction est portée par `MembershipAccessPolicy`, pas par le membership.
5. Permissions explicites, non hiérarchiques ; rôles communaux jamais exposés par `User::getRoles()`.
6. **Dernier administrateur actif** : impossible de désactiver un membership, ou de lui retirer `MUNICIPALITY_ADMIN`, s'il est le dernier membership actif portant ce rôle, sauf par le superadmin. Transaction + verrou pessimiste (`LockMode::PESSIMISTIC_WRITE`) sur la ligne `Municipality`, puis recomptage. Pas de colonne `version` (D6).
7. Pas d'auto-élévation : seul un `MUNICIPALITY_ADMIN` de la même commune ou le superadmin modifie les rôles.
8. Toute modification des rôles ou de `active` passe par `MembershipManager`, qui écrit l'audit (section 8) dans la même transaction. Aucun setter public.
9. Pas de suppression physique. Les requêtes par rôle filtrent par commune (index) puis par rôle en PHP ; bascule vers une table de jonction possible, isolée derrière `hasRole()` et le repository.

## 8. Audit minimal (D5, D10) : `AuditLog`

### Options étudiées

| Option | Verdict |
|---|---|
| Une table d'historique par entité auditée | Écartée : 3 tables aujourd'hui, davantage au LOT 07 ; duplication. |
| Colonne JSON d'historique dans chaque entité | Écartée : mutable, croissante, non interrogeable. |
| Écouteur Doctrine générique | Écartée : implicite, ne connaît pas l'acteur, difficile à tester. |
| **Table unique `audit_log`, écrite explicitement par les cas d'usage dans la même transaction** | **Retenue** : conforme à l'entité `AuditLog` de `data-model.md`, réutilisable au LOT 07 sans migration de structure. |

### Table `audit_log` — `App\Shared\Domain\AuditLog` (append-only)

| Attribut | Type Doctrine | Colonne MySQL | Null | Remarques |
|---|---|---|---|---|
| `id` | `uuid` | `BINARY(16)` | non | PK, UUID v7. |
| `occurredAt` | `datetime_immutable` | `DATETIME` | non | UTC. |
| `action` | enum `AuditAction` | `VARCHAR(60)` | non | Voir ci-dessous. Chaîne (pas de `ENUM` SQL) pour l'extension au LOT 07 sans migration. |
| `subjectType` | enum `AuditSubjectType` | `VARCHAR(40)` | non | `USER`, `MUNICIPALITY`, `MUNICIPALITY_MEMBERSHIP`. |
| `subjectId` | `uuid` | `BINARY(16)` | non | Identifiant de l'objet modifié. |
| `municipalityId` | `uuid` | `BINARY(16)` | oui | **Périmètre communal** de l'événement ; `NULL` pour un changement global (`superAdmin`). |
| `actorId` | `uuid` | `BINARY(16)` | oui | Auteur. `NULL` si `origin ≠ USER`. |
| `origin` | enum `AuditOrigin` | `VARCHAR(16)` | non | `USER`, `CONSOLE`, `SYSTEM`. |
| `previousValue` | `json` | `JSON` | oui | Ancienne valeur ; `NULL` pour une création. |
| `newValue` | `json` | `JSON` | non | Nouvelle valeur. |

Actions du LOT 01 (`AuditAction`) : `MEMBERSHIP_CREATED`, `MEMBERSHIP_ROLES_CHANGED`, `MEMBERSHIP_ACTIVATED`, `MEMBERSHIP_DEACTIVATED`, `MUNICIPALITY_STATUS_CHANGED`, `MUNICIPALITY_CONTACT_CHANGED`, `USER_SUPER_ADMIN_CHANGED`, `USER_ACTIVATION_CHANGED`. Les nouvelles actions n'exigent aucune migration (colonne `VARCHAR`).

Contenu des valeurs (minimal, **sans donnée personnelle**) :
- Membership : `{"roles":["INSTRUCTOR"],"active":true}`.
- Commune : `{"status":"ACTIVE"}`.
- Superadmin : `{"superAdmin":false}`.
- Activation d'un compte : `{"active":true}` (entrée globale, `municipalityId` NULL).
- Contact de commune : les six champs `contactName`, `contactEmail`, `contactPhone`, `addressLine`, `postalCode`, `city` avant/après. **Ce sont des coordonnées institutionnelles de la commune, mais elles peuvent désigner une personne** : la durée de conservation et la purge de l'audit sont à fixer au LOT 07.

Index : `PRIMARY(id)` ; `idx_audit_subject (subject_type, subject_id, occurred_at)` ; `idx_audit_municipality (municipality_id, occurred_at)` ; `idx_audit_actor (actor_id)`.

**Pas de clé étrangère ni de relation ORM** vers `User` ou `Municipality` : `Shared` reste indépendant des autres modules, et les identifiants restent valides puisque comptes et communes ne sont jamais supprimés physiquement. Contrepartie : l'intégrité référentielle est garantie par le domaine et par les tests, pas par la base.

### Mécanisme
- `App\Shared\Application\AuditTrail` (interface) : `record(AuditEvent $event): void`, avec `AuditEvent` objet valeur immuable. Implémentation Doctrine en infrastructure : elle **persiste sans flusher** ; la transaction appartient au cas d'usage.
- `App\Shared\Application\CurrentActorProvider` (interface) : fournit l'auteur courant ; implémentée dans `Identity` à partir du token de sécurité. Hors requête authentifiée : `origin = CONSOLE` (commande) ou `SYSTEM` (traitement différé), `actorId = NULL`.
- Les trois services qui écrivent : `MembershipManager`, `MunicipalityManager::changeStatus()`, `UserManager`. Ce sont les **seuls chemins** de modification ; ni Sonata ni les contrôleurs ne modifient ces champs directement.
- **Même transaction** : pas de changement sans trace, pas de trace sans changement (rollback testé).
- **Pas de trace pour un non-changement** : une modification qui ne change pas la valeur n'écrit rien.
- **Immuable** : pas de méthode de modification, pas de suppression via les services applicatifs.
- **Lecture** : superadmin (tout) ; `MUNICIPALITY_ADMIN` uniquement les lignes dont `municipalityId` est sa commune, **jamais** les lignes `municipalityId = NULL`.
- **Réutilisation au LOT 07** : ajout de nouvelles valeurs d'`AuditAction`, consultation, politique de conservation ; aucune modification de la structure.

### Limites connues
- Pas de protection contre un accès SQL direct à la base.
- Un contournement des services (modification directe de l'entité par du code futur) n'est pas empêché par la base ; il est détecté par un test d'architecture (absence de setters publics sur les champs audités).
- Pas de champ « motif » en V1.

## 9. Schéma relationnel (résumé)

```
identity_user (1) ──< municipality_membership >── (1) municipality
   id (uuid v7)          id, user_id, municipality_id,       id (uuid v7)
   email (unique, bin)   roles (json), active                inseeCode (unique, bin)
   password, active      unique(user_id, municipality_id)    status, contact*, address*
   superAdmin, lastLoginAt                                   contactPhone (E.164)

audit_log  (module Shared, sans FK)
   action, subjectType, subjectId, municipalityId?, actorId?, origin,
   previousValue?, newValue, occurredAt
```

## 10. Plan d'implémentation LOT 01.1 (après feu vert)

Le LOT 01.1 couvre fondations, entités, schéma, audit et règles métier. L'authentification (01.2) et Sonata (01.3) restent des lots séparés, soumis à validation, car ils modifient `security.yaml` et Sonata.

### Étape 0 — Prérequis (avant tout code)
- Décision sur la bibliothèque de téléphone (section 12).
- **Base de test** : l'environnement `test` ne charge pas `.env.local` et `.env.test` ne définit aucun `DATABASE_URL` ; tout test d'intégration échouerait (connexion refusée vers `127.0.0.1`). Il faut un `DATABASE_URL` pour `test` (dans `.env.test` ou `.env.test.local`) : modification de fichier d'environnement **soumise à votre validation**.
- Déclarer dans `composer.json` les composants déjà présents que le code utilisera (`symfony/clock`, `symfony/validator`) : pas de nouveau code tiers, seulement une déclaration explicite.

### Ordre de création des classes

| # | Classes | Dépend de | Tests |
|---|---|---|---|
| 1 | `Shared` : enums `AuditAction`, `AuditSubjectType`, `AuditOrigin` ; `AuditEvent` ; interfaces `AuditTrail`, `CurrentActorProvider` | — | Construction de `AuditEvent`, valeurs d'enums |
| 2 | `Identity` : `EmailAddress` | — | Normalisation (casse, espaces, Unicode), rejets |
| 3 | `Municipality` : enums `MunicipalityRole`, `MunicipalityStatus`, `SubscriptionStatus` ; `InseeCode` ; `PhoneNumber` + interface `PhoneNumberNormalizer` + implémentation | — | `2A004`, `2B033`, `97101`, formats invalides ; téléphones français, `+33`, `0033`, E.164 étranger, invalides |
| 4 | `Identity` : `User`, interface `UserRepository` | 1, 2 | `getRoles()` sans rôle communal, `EquatableInterface`, mot de passe (11 refusé / 12 accepté via DTO) |
| 5 | `Municipality` : `Municipality`, `MunicipalityMembership` (invariants de rôles), interfaces de repositories | 3, 4 | Rôles dédoublonnés/triés, valeur inconnue rejetée, membership actif sans rôle rejeté |
| 6 | `Shared` : `AuditLog` (entité) | 1 | Immuabilité |
| 7 | `config/packages/doctrine.yaml` : trois mappings (`Identity`, `Municipality`, `Shared`) | 4, 5, 6 | `lint:container`, `doctrine:mapping:info` |
| 8 | Infrastructure Doctrine : repositories, `DoctrineAuditTrail` | 7 | Intégration : unicité email (casse), collation binaire (`josé`/`jose`), unicité (user, commune), FK `RESTRICT` |
| 9 | **Migration unique** (`make:migration`), relue ligne par ligne ; **arrêt pour validation avant exécution** | 8 | Contrôle : `BINARY(16)`, collations, index, FK |
| 10 | `Municipality` : `MembershipManager`, `MunicipalityManager`, `Identity` : `UserManager`, `CurrentActorProvider` (implémentation) | 8 | Audit dans la même transaction, rollback, pas de trace sans changement, dernier administrateur (concurrence avec deux connexions), dernier superadmin, auto-élévation refusée |
| 11 | `EventCreationPolicy` | 3 | Matrice statut × abonnement (D9), non-abonnée non exclue de la participation |
| 12 | `MunicipalityContext`, `MembershipAccessPolicy`, voters | 10 | Isolation A/B avec identifiants forgés, droit effectif refusé si utilisateur / membership / commune inactifs, `SUSPENDED` en lecture seule |
| 13 | Lecture d'audit : repository filtré par commune, voter | 6, 12 | Admin A ne lit pas l'audit de B ; ne lit jamais les lignes globales ; superadmin lit tout |

### Outillage de test (D12)
Dans `tests/Support/` : `UserBuilder`, `MunicipalityBuilder`, `MembershipBuilder` (valeurs par défaut valides, surcharge explicite, horloge fixe) et `DatabaseTestCase` (purge des tables dans l'ordre des FK avant chaque test). Pas de rollback par transaction, car les tests de concurrence exigent deux connexions réelles.

### Fichiers touchés prévus
`src/Identity/**`, `src/Municipality/**`, `src/Shared/**`, `src/Migrations/Version*.php`, `config/packages/doctrine.yaml`, `composer.json` / `composer.lock`, `tests/**`, `.env.test` (selon votre décision).

## 11. Tests bloquants du LOT 01 (synthèse)

- Normalisation de l'email, unicité insensible à la casse, `josé@`/`jose@` distincts, recherche non normalisée sans résultat.
- `User::getRoles()` sans rôle communal ; mot de passe 11/12 caractères sans complexité imposée.
- Code INSEE et téléphones (formats français, `+33`, E.164 étranger, rejets).
- Unicité (user, commune) ; réactivation sans doublon ; rôles validés.
- Droit effectif refusé si utilisateur, membership ou commune inactifs ; `SUSPENDED` en lecture seule.
- Dernier administrateur de commune et dernier superadmin non retirables, y compris sous concurrence.
- Création d'événement : `ACTIVE` + `ACTIVE` seulement ; commune non abonnée non exclue de la participation.
- Chaque changement de rôle, d'activation, de statut communal ou de `superAdmin` produit exactement une ligne d'audit (auteur, UTC, ancienne et nouvelle valeur) dans la même transaction ; aucune ligne sans changement ; rollback sans ligne.
- Une ligne d'audit n'est ni modifiable ni supprimable via les services applicatifs.
- Isolation : agent de la commune A sans accès aux memberships ni à l'audit de B, identifiants forgés compris.
- `lastLoginAt` mis à jour sans modifier `updatedAt`.

## 12. Points restant à confirmer

1. **Bibliothèque de téléphone** : installer `giggsey/libphonenumber-for-php-lite` (recommandé : validation fiable des numéros internationaux et d'outre-mer, dépendance largement utilisée) ou accepter la version limitée sans dépendance (métropole + forme E.164 sans validation par pays) ?
2. **Base de test** : autorisez-vous l'ajout d'un `DATABASE_URL` pour l'environnement `test` ? Où : `.env.test` (versionné, valeur sans secret) ou `.env.test.local` (non versionné) ?
3. **Dernier superadmin** (règle 8 de la section 5) : validez-vous qu'il ne puisse pas être rétrogradé ou désactivé, sauf par la commande console ?
4. **Participation d'une commune `SUSPENDED`** : la considérer comme exclue de toute nouvelle participation (proposition), à figer au LOT 02.

## 13. Commune `SUSPENDED` : conséquences et vérification au LOT 02 (D16)

**Principe** : la suspension est temporaire et réversible. Elle suspend la **capacité d'agir**, jamais les données : aucune suppression ni modification d'historique, d'autorisation, de décision ou de QR.

### Effets
| Domaine | Commune `SUSPENDED` |
|---|---|
| Contrôle des autorisations de la commune (scan public, PWA) | **Refusé** tant que `SUSPENDED` ou `INACTIVE` (D17, implémenté avec Permit/Control). |
| Créer un événement | Interdit (`EventCreationPolicy::canCreateEvent`). |
| Rejoindre un **nouvel** événement (invitation, acceptation) | Interdit (`EventCreationPolicy::canJoinNewEvent`). |
| Événements **existants** : instruire, décider, délivrer, prolonger, modifier zones/véhicules, révoquer, inviter | Suspendus : droits d'écriture des agents refusés (`MembershipAccessPolicy`, lecture seule). |
| Consultation de l'historique, des dossiers et des décisions par ses agents | Maintenue (lecture seule). |
| Gestion des membres et du contact par l'administrateur de la commune | Refusée ; reste possible au superadmin (supervision explicite). |
| Autorisations **déjà délivrées** | Conservées en base, **non révoquées** ; **inutilisables au contrôle** tant que la commune est `SUSPENDED` ou `INACTIVE` (D17). |
| Zones, véhicules, QR, historique | Inchangés. |

### Vérification des autorisations au LOT 02 / LOT 06 (à implémenter, non décidé en LOT 01)
1. Le **scan public et la PWA** doivent lire le statut communal à chaque vérification et au moment de la synchronisation, au même titre que la sortie de participation et la clôture d'événement.
2. **Décision D17 (validée)** : une commune administrativement `SUSPENDED` ou `INACTIVE` **ne permet pas l'utilisation de ses autorisations au contrôle** (scan public et PWA). Cette invalidité est **temporaire** pour `SUSPENDED` : elle n'entraîne **aucune révocation définitive** et ne modifie ni l'état, ni la validité, ni les révisions des autorisations. Une commune réactivée (`ACTIVE`) **retrouve ses autorisations encore valides**, sous réserve des autres règles métier (échéance, zones actives, participation, clôture d'événement, révocation explicite). Pour `INACTIVE`, l'invalidité au contrôle s'applique de la même façon ; un retour à `ACTIVE` suit la règle de réactivation (superadmin, section 6). **Cette règle sera implémentée avec le module Permit/Control (LOT 05/06), pas dans le LOT 01.1** : le contrôle devra évaluer le statut communal **au moment de la vérification** (calculé, jamais copié dans l'autorisation) et l'inclure dans les données synchronisées de la PWA (voir le risque hors ligne ci-dessous).
3. Une suspension ne doit **jamais** déclencher d'invalidation automatique de masse : si une invalidation est voulue, elle passe par une révocation explicite, tracée.
4. Une commune réactivée (`ACTIVE`) retrouve ses capacités sans ressaisie ; les actions bloquées pendant la suspension ne sont ni rejouées ni perdues.
5. **Risque hors ligne** : une suspension ou une réactivation n'est connue d'une PWA qu'après synchronisation (même limite que les révocations, docs/architecture.md : avertissement à 12 h, blocage à 24 h). À documenter dans le modèle de menace du LOT 06.
6. Tests LOT 02 à prévoir : création et participation refusées ; écriture refusée sur événement existant ; lecture maintenue ; autorisations intactes (non révoquées) après suspension puis réactivation ; contrôle refusé pendant la suspension et rétabli à la réactivation (LOT 05/06) ; isolation des autres communes de l'événement.

## 14. Écarts entre la conception et l'implémentation (LOT 01.1)

- **`CurrentActorProvider` supprimé** : l'acteur est passé explicitement aux services (`?User $actor`, `null` = console) puis converti en `AuditActor`. Plus simple et testable ; l'acteur provient du token de sécurité dans les contrôleurs, jamais du client.
- **`SuperAdminManager` → `UserManager`** : regroupe création de compte, superadmin et (dés)activation, avec la protection du dernier superadmin.
- **Entités sans `readonly`** : Doctrine ne peut pas rafraîchir une propriété `readonly` (`refresh`/verrou sur une entité déjà chargée) ; l'immuabilité est assurée par l'absence de mutateurs.
- **`datetime_immutable` surchargé** (`UtcDateTimeImmutableType`) : écriture et relecture en UTC garanties, quel que soit le fuseau de l'objet PHP.
- **Transactions et EntityManager** : `EntityManager::wrapInTransaction()` ferme l'EntityManager à la moindre exception, ce qui est inacceptable pour un simple refus métier. Les services passent par `TransactionRunner` (`DoctrineTransactionRunner`) : un refus (`DomainRuleViolation`) provoque un rollback mais **laisse l'EntityManager ouvert** tant que l'opération n'a laissé aucune modification en attente ; une défaillance technique (ou un refus survenu après modification d'entités) ferme l'EntityManager, car son état n'est plus fiable. Les contrôles de droit et d'invariants doivent donc rester **avant** toute modification. Couvert par `EntityManagerResilienceTest`.
- **Index** : Doctrine génère aussi `IDX_…(user_id)`, redondant avec le préfixe de `uniq_membership_user_municipality` ; conservé tel que généré.
- **Services** : `services.yaml` exclut `src/*/Domain/` des services (entités, objets valeur) ; les interfaces sont reliées par `#[AsAlias]`. Un bloc `when@test` rend publics les services testés.
- **Sécurité** : seul le provider d'utilisateurs a été remplacé (`UserProvider`, recherche par email normalisé). Aucun authenticator : `/admin` répond 401 aux anonymes et 403 aux non-superadmins ; la connexion arrive au LOT 01.2.
- **Concurrence** : le verrou sur la ligne `municipality` est vérifié par un test à deux connexions (le second `SELECT … FOR UPDATE` expire) ; il n'existe pas de test de charge multi-processus.

## 14 bis. Corrections issues de la revue indépendante (révision 5)

| Réf. | Correction |
|---|---|
| C1 | `PlainPasswordPolicy` mesure le maximum en octets ; `UserManager::createUser` convertit aussi une `InvalidPasswordException` du hacheur en refus métier. |
| C2 / D18 | `UserManager::deactivate` interroge les `UserDeactivationGuard` (point d'extension d'Identity). `LastMunicipalityAdminGuard` (Municipality) verrouille, dans un ordre stable, **toutes** les communes `ACTIVE` administrées par le compte, puis refuse si l'une resterait sans administrateur effectif. Identity ne dépend pas de Municipality. |
| E1 | `DoctrineTransactionRunner` : l'EntityManager n'est conservé après un refus métier que s'il est prouvé sain (**aucun flush** pendant l'opération et **aucune modification en attente**), sinon il est fermé. L'imbrication, y compris dans une transaction externe, lève une `LogicException` explicite. Les services ne flushent plus eux-mêmes : le runner flushe une seule fois. |
| E2 / D20 | `MunicipalityManager::updateContact` : transaction, verrou et rafraîchissement de la commune, contrôle du droit sur l'état rafraîchi, audit `MUNICIPALITY_CONTACT_CHANGED` (aucune ligne si rien ne change). `changeStatus` lit désormais la valeur précédente sur l'état verrouillé et rafraîchi. |
| E3 / D20 | `UserManager::deactivate` / `activate` : transaction, verrou, audit `USER_ACTIVATION_CHANGED`. |
| M1 / D19 | `MembershipManager::assertNotLastAdmin` : une seule requête `COUNT` (`JSON_CONTAINS` sur les rôles, jointure sur `identity_user.active`) ; un membership déjà inopérant (compte désactivé) ne bloque pas. |
| M2 | Toutes les opérations de `UserManager` verrouillent et rafraîchissent l'utilisateur ciblé (`UserRepository::lock`) avant de lire son état. |
| M3 | `when@test` ne liste plus les services un par un : même `App\` resource, mêmes `#[AsAlias]`, seule la visibilité diffère (`public: true`). |
| M4 | `docs/testing.md` et cible `make test-db` (provisionnement reproductible de `civipass_test`). |
| Archi | Le test d'architecture couvre aussi `deactivate()` et `activate()` (appelables uniquement par `UserManager` et `MembershipManager`). |

**Rappel pour le LOT 01.2** (inchangé et à conserver explicitement) : `UserChecker` refusant les comptes inactifs ; authentification avec protection CSRF ; limitation des tentatives de connexion ; mise à jour de `lastLoginAt`.

## 15. Commandes

- Provisionner la base de test : `make test-db` (voir `docs/testing.md`).
- Tests : `make test` (PHPUnit sur `civipass_test`), contrôle complet : `make quality`.
- Les migrations sur la base de développement ne sont **pas** exécutées à ce stade (en attente de validation).
