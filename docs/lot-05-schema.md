# LOT 05 — Laissez-passer : architecture et modèle (incrément 05.0 / 05.1)

> Décisions sources : DM-112 à DM-117, DT-046 à DT-050 ([registre](decisions/README.md)) ; rappels DM-105, DM-106, DM-109, DM-111, DM-035, DT-024, DT-041, DT-042, DT-044. Migration 05.1 : `Version20261109130000`.

## 1. Périmètre et découpage

| Incrément | Contenu | État |
|---|---|---|
| **05.0** | Architecture, arbitrages, documentation | livré |
| **05.1** | Module Permit : laissez-passer, jeton QR, autorisations, copies de zones et de plaques, création atomique par le port, `PermitRightsEvaluator`, commande de rattrapage | **terminé** (migration `Version20261109130000` appliquée sur `civipass`) |
| 05.2 | Jeton et page bénéficiaire, QR (SVG), PDF, écran agent « remettre le lien » | **terminé** (DM-118, DT-051), migration `Version20261109150000` appliquée sur test et sur `civipass` (LOT 05.2 bis) |
| 05.3 | Outbox, distributeur périodique, notifications (première approbation, nouvelle commune), cas sans e-mail | **TERMINÉ — clôture prononcée le 9 novembre 2026** (§23). Détail : **05.3.1 livré** (outbox, migration appliquée sur `civipass` le 9 novembre 2026) ; **05.3.2 livré** (chiffrement, lien système, purge ; migration appliquée sur `civipass` le 9 novembre 2026) ; **05.3.3 livré** (distributeur, supervision ; migration appliquée sur `civipass` le 9 novembre 2026) ; **05.3.4 livré** (ordre, regroupement, suivi agent, envoi manuel ; migration appliquée sur `civipass` le 9 novembre 2026) ; **05.3.5 livré** (anti-abus du renvoi, purge de l'outbox, recette interactive, documentation d'exploitation ; aucune nouvelle migration) |
| **05.4** | **Page publique de contrôle** (scan sans compte) : nom, prénom, plaques, zones, validité par zone, limitation de débit, en-têtes de protection. **L'habilitation temporaire des contrôleurs est retirée du périmètre** (DM-115) | **non commencé** |
| 05.5 | Révisions : prolongation, raccourcissement, zones, plaques, révocation, remplacement exceptionnel du QR, notifications associées | **en cours** : sous-lots 05.5.1 à 05.5.6 ([DM-119](decisions/metier/DM-119-revisions-des-droits-d-un-laissez-passer.md), Proposée). **05.5.1 (socle et révocation) : validé le 10 novembre 2026** (développement et recette automatisée) ; migration `Version20261110150151` appliquée aux bases `civipass_test` et `civipass` (développement). **05.5.2 : cadrage rédigé, en attente de validation** ([lot-05-5-2-cadrage.md](lot-05-5-2-cadrage.md)) |
| 05.6 | Recette transversale et clôture | à faire |

**Hors périmètre V1 du contrôle** : `ControllerActivation`, `ControllerDevice`, comptes ou sessions de contrôleur, PWA. La PWA hors ligne (LOT 06) est une évolution distincte, à redéfinir.

## 2. Modèle de données (05.1)

| Table | Contenu | Contraintes |
|---|---|---|
| `permit_permit` | `id`, `request_id`, `event_id`, `reference` (`LP-AAAA-NNNN`), `first_decision_id`, `created_at` | `UNIQUE(request_id)`, `UNIQUE(reference)` |
| `permit_verification_token` | `permit_id`, `token` (32 caractères base32, ≥ 160 bits), `created_at`, `retired_at`, `active_permit_id` | `UNIQUE(token)`, `UNIQUE(active_permit_id)` : valeur = `permit_id` tant que le jeton est actif, `NULL` une fois retiré (MySQL n'a pas d'index unique partiel) |
| `permit_authorization` | `permit_id`, `participation_id`, `municipality_id`, `decision_id`, `duration_mode`, `valid_from`, `valid_until`, `revoked_at` | `UNIQUE(permit_id, participation_id)`, `UNIQUE(decision_id)` |
| `permit_authorization_zone` | `authorization_id`, `zone_id`, `zone_label` (copie), `removed_at` | `UNIQUE(authorization_id, zone_id)` |
| `permit_vehicle` | `permit_id`, `registration`, `format`, `position`, `removed_at` | `UNIQUE(permit_id, registration)` |
| `permit_reference_counter` | `year`, `last_number` | clé : année ; incrément SQL atomique, verrouillé en dernier |

- Dates en UTC ; UUID v7 en `BINARY(16)` ; clés étrangères `RESTRICT` (jamais de suppression physique).
- La fenêtre de l'autorisation est **copiée** de la décision d'origine ; les révisions du 05.5 la modifieront sans toucher la décision. La révocation (`revoked_at`) est un fait administratif ; ses détails (auteur, motif) arrivent avec le 05.5.
- **Aucun statut de validité n'est stocké** (DM-112). L'identité n'est jamais copiée.

## 3. Création atomique

`ApprovedPartListener::partApproved(decision)` est appelé par `MunicipalDecisionApplier` dans la transaction de décision, après la décision et l'audit, pour l'instruction **et** la délivrance directe. `PermitIssuer` (module Permit) :

1. verrouille la ligne du dossier **si le dossier est déjà persisté** ;
2. relit le laissez-passer par une **lecture verrouillante** (sous REPEATABLE READ, une lecture simple ne verrait pas une création validée entre-temps) ;
3. le crée s'il n'existe pas (référence `LP-`, jeton, plaques) ;
4. ajoute l'autorisation de la commune (fenêtre de la décision) et copie les zones de la part ;
5. écrit l'audit (`PERMIT_ISSUED` à la création, sinon `PERMIT_AUTHORIZATION_ADDED`) : jamais le jeton.

Rejeu : l'autorisation existe déjà (`UNIQUE(decision_id)`), rien n'est recréé. Une exception annule l'approbation et la création ensemble. **Délivrance directe** : le port est appelé avant que le dossier soit persisté ; le laissez-passer référence le dossier en mémoire et le flush unique de la transaction écrit l'ensemble.

Ordre des verrous : événement (partagé) → participation → zones → part → **ligne du dossier** → compteurs (`DEM-` puis `LP-`) en dernier. La correction d'identité (04.4) prend déjà événement → participation → ligne du dossier : aucun cycle.

## 4. Calcul des droits effectifs

`PermitRightsEvaluator::evaluate(permit, instant)` renvoie, **par autorisation puis par zone**, un état parmi : `VALID`, `NOT_YET_VALID`, `EXPIRED`, `OUTSIDE_PERIOD`, `ZONE_SUSPENDED`, `PARTICIPATION_INACTIVE`, `COMMUNE_INACTIVE`, `EVENT_NOT_ACTIVE`, `EVENT_CLOSED`, `REVOKED`, `ZONE_NOT_AUTHORIZED` (zone demandée absente du laissez-passer).

Précédence des causes : révoquée → commune non ACTIVE (DM-035) → événement clôturé puis non actif → participation non ACTIVE → zone suspendue → état temporel. État temporel d'une zone active : avant la première période effective ou avant le début de la fenêtre → `NOT_YET_VALID` ; entre deux périodes → `OUTSIDE_PERIOD` (avec le prochain début) ; après la dernière période ou la fin de la fenêtre → `EXPIRED` ; dans une période → `VALID` (avec sa fin).

Le calcul réutilise `GrantWindowCalculator::effectivePeriods()` et `ZoneEffectivePeriodsResolver` (extrait de `MunicipalDecisionApplier`, comportement inchangé). Cinq durées compatibles : H24, H48, H72, CUSTOM (fin résolue dans le fuseau de l'événement), UNTIL_CLOSURE (aucune fin copiée : bornée par la fin ou la clôture de l'événement). **Différence assumée avec la décision** : la décision exige une intersection non vide au moment de décider ; le contrôle évalue l'état courant (zone suspendue ou commune inactive depuis).

## 5. Scan public (LOT 05.4, DM-115)

Toute personne qui scanne le QR accède, sans compte, à une page qui affiche **uniquement** : nom, prénom ; plaques (si des véhicules sont associés) ; zones autorisées ; dates et périodes de validité ; état par zone calculé en temps réel ; référence `LP-` ; événement. **Jamais** : adresse, e-mail, téléphone, justificatifs, autres données de la demande. Une zone inactive ou une autorisation révoquée est identifiable ; un laissez-passer n'est pas présenté comme entièrement valide si seules certaines zones le sont.

**Risque résiduel RGPD** : nom, prénom et plaques sont exposés à toute personne qui dispose du QR (photo, partage). Le QR n'est pas une preuve d'identité. Mesures : jeton opaque ≥ 160 bits, aucun accès par `DEM-`/`LP-`, `noindex`, `no-store`, `no-referrer`, limitation de débit, aucun jeton dans les journaux, aucun journal systématique des scans. À valider avant la production : base légale, texte de confidentialité du dépôt (qui doit mentionner cette exposition), durée de conservation.

## 6. Matrice des permissions du LOT 05

| Action | Public (QR) | Bénéficiaire (lien) | INSTRUCTOR | MUNICIPALITY_ADMIN | EVENT_MANAGER | Autre commune |
|---|---|---|---|---|---|---|
| Page de contrôle (nom, plaques, zones, validité) | oui | — | oui | oui | oui | oui |
| Page personnelle et PDF | non | oui | non | non | non | non |
| Voir, générer ou révoquer des liens (05.2) | non | non | oui | oui | uniquement pour ce qu'il a délivré directement | non (sauf commune titulaire d'une autorisation du laissez-passer) |
| Prolonger, modifier zones (05.5) | non | non | oui (sa part) | oui (sa part) | non | non |
| Modifier les plaques (05.5) | non | non | oui | oui | non | non |
| Révoquer une autorisation (05.5) | non | non | oui (sa commune) | oui (sa commune) | non | non |
| Remplacer le QR (05.5) | non | non | non | oui | non | non |
| Détails d'autorisation côté agent | — | — | sa commune | sa commune | sa commune | non |

Toute écriture exige une adhésion active, une participation ACTIVE (règle du 04.5) et une revérification sous verrou. Aucune habilitation d'appareil contrôleur en V1.

## 7. Modifications futures (05.5)

Décision initiale (immuable) → droits courants (autorisation, zones, plaques) → **révisions immuables** (prolongation, zones, plaques, révocation, remplacement de jeton ; auteur, date, avant/après) → audit. Plaques au niveau du laissez-passer, modification autorisée à INSTRUCTOR et MUNICIPALITY_ADMIN (participation ACTIVE, confirmation de l'effet global, audit dans chaque commune concernée). Le QR ne change jamais lors de ces opérations ordinaires.

## 8. Notifications (05.3, DM-117 / DT-049)

Outbox transactionnelle (ligne écrite dans la transaction métier, clé de dédoublonnage unique) et distributeur périodique par commande Symfony, hors transaction, avec bail, tentatives et abandon. Sans e-mail exploitable : aucun envoi, cas tracé, lien remis en main propre. Aucune dépendance Messenger Doctrine. Toute modification Docker (processus périodique) est soumise à validation.

## 9. QR et PDF (05.2, DT-050)

`endroid/qr-code` en direct, `dompdf` derrière une interface, PDF à la demande sans stockage, mention « droits vérifiés au scan », ressources distantes désactivées. Versions à vérifier avant installation.

## 10. Protection des journaux nginx

**Remplacé** : la proposition initiale (un `map` sur `$request_uri`) ne masquait pas les chemins réécrits par `try_files` ni les variantes encodées, et ne couvrait pas le journal d'erreur. La solution testée (nginx 1.30 et 1.31, vrai logrotate) est dans [`ops/nginx/`](../ops/nginx/README.md) : journal d'accès sans secret ni requête (`log_format civipass`), journal d'erreur brut tourné chaque heure et filtré (le fichier actif reste brut jusqu'à la rotation : risque documenté), hôtes inconnus refusés, redirection vers le domaine configuré. Préparée et testée, **pas encore appliquée à un serveur**.

## 11. Matrice de conservation (durées à fixer avant la production)

> **Mise à jour du 11 novembre 2026.** Cette matrice est remplacée par le cycle de vie de [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) et la matrice des données de [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §4 (clôture, archive intermédiaire, purge par dossier, suppression).

| Donnée | Finalité | Contrainte | Mécanisme prévu | Durée |
|---|---|---|---|---|
| Demandes (identité, contact, plaques déclarées) | Instruction, preuve | Minimisation, droit à l'effacement | Anonymisation de l'identité après la durée retenue, décisions conservées | **à décider** |
| Laissez-passer, autorisations, zones, plaques copiées | Contrôle, historique | Traçabilité (CLAUDE.md règle 8) | Conservation de l'historique, purge des plaques à l'issue | **à décider** |
| Jeton de vérification | Contrôle | Plus de finalité après l'événement | Retrait puis purge | **à décider** |
| Liens bénéficiaires | Accès au document | Expire 30 jours après la fin effective de l'événement | Purge des empreintes expirées | 30 j après la fin (DM-114) |
| Révisions (05.5) | Historique des droits | Traçabilité | Anonymisation des plaques | **à décider** |
| Notifications (outbox) | Envoi | Pas de donnée personnelle copiée | Purge des lignes envoyées | **à décider** |
| Audit | Traçabilité | Aucune valeur personnelle (DT-037) | Conservation longue | **à décider** |

**Aucune mise en production du LOT 05 ne sera validée sans politique de conservation et mécanisme de purge adaptés.**

## 12. Dépendances entre modules

```
Request (port ApprovedPartListener) ◄── implémenté par ── Permit (PermitIssuer)
Permit ──lit──► Request (décision, part, plaques), Event (participation, zones, calculateurs), Municipality (statut), Shared (audit, transactions)
Request ne dépend pas de Permit.
```

## 13. Limites connues du 05.1

- Aucune route, page, PDF, e-mail ni écran : tout cela relève des 05.2 à 05.5.
- Les autorisations ne portent pas encore auteur ni motif de révocation (05.5).
- Les approbations antérieures au 05.1 n'ont de laissez-passer qu'après exécution de `app:permit:backfill` (développement seulement, sur accord).
- La migration `Version20261109130000` est appliquée sur la base de test et, avec l'accord du propriétaire, sur la base de développement `civipass` (9 novembre 2026). Elle n'a pas été appliquée ailleurs ; `app:permit:backfill` n'a **pas** été exécutée.
- Contrainte technique : une lecture verrouillante d'une clé absente prend un verrou d'intervalle et deux créateurs concurrents s'interbloqueraient ; `PermitIssuer` prend donc d'abord le verrou du compteur `LP-` (qui sérialise les créateurs) avant de relire sous verrou. Le même motif existait dans la relecture d'idempotence du dépôt et de la délivrance directe (LOT 04.3) : **reproduit** (deux communes, clés différentes : `DEADLOCK`) puis **corrigé** au LOT 05.1 bis (§ 15).

## 14. Préparation du LOT 05.2

Réutiliser : `PermitRightsEvaluator` (état affiché), `VerificationToken` (URL du QR), `Permit::reference()`. À créer : jeton bénéficiaire (empreinte SHA-256), page personnelle, QR, PDF, écran agent ; installer `endroid/qr-code` et `dompdf` après vérification des versions et accord.

## 15. Correctif LOT 04.3 (interblocage) et compatibilité hors ligne

### 15.1 Interblocage de l'empreinte d'idempotence (corrigé)
- **Cause.** Dépôt et délivrance directe : après les verrous de participation, la relecture verrouillante de l'empreinte `(événement, clé)` — clé absente — prend un verrou d'intervalle ; le compteur `DEM-` n'était verrouillé qu'ensuite. Deux créateurs de participations différentes (donc non sérialisés par les verrous de participation) tenaient chacun un verrou d'intervalle, l'un attendait le compteur de l'autre, qui attendait l'insertion de l'empreinte : cycle, `DEADLOCK` (conflit réessayable, mais évitable). Reproduit par `DirectIssuanceConcurrencyTest::testTwoCommunesIssuingAtTheSameTimeWithDifferentKeysNeverDeadlock`.
- **Correctif minimal.** `RequestReferenceGenerator::lock()` verrouille la ligne du compteur de l'année **sans l'incrémenter**, juste avant la relecture verrouillante. C'est le verrou que `next()` prenait déjà, simplement acquis plus tôt : l'ordre reste événement → participation → zones → (compteur) → insertions, les créateurs sont sérialisés et deux verrous d'intervalle ne coexistent plus. Aucune règle d'idempotence modifiée ; un rollback n'a consommé aucun numéro.

### 15.2 Contrôle hors ligne futur (principe, rien n'est développé)
- **Contrôle en ligne** : public, sans compte ni habilitation (DM-115).
- **Contrôle hors ligne** : PWA avec synchronisation préalable des données de l'événement ; le résultat est calculé à partir des données locales et l'**heure de la dernière synchronisation est toujours affichée**.
- **Synchronisation complète** : accès temporairement autorisé, sans compte permanent obligatoire (mécanisme à définir au LOT 06).
- **Même QR** en ligne et hors ligne : le QR ne contient que l'URL publique `…/<jeton>` ; il n'est ni différent, ni signé, ni enrichi pour le hors ligne.
- **Extraction sans réseau** : `VerificationToken::extract()` (fonction pure, testée) retrouve le jeton de 32 caractères base32 dans le jeton seul ou dans une URL (majuscules d'un lecteur, paramètres, ancre, slash final tolérés ; toute autre valeur : `null`). La PWA pourra donc rechercher le laissez-passer dans sa copie locale sans requête.
- **Contraintes à respecter par le LOT 06** : le jeu synchronisé contient les jetons et donc le même périmètre de données que la page publique (DM-115), jamais adresse, e-mail, téléphone ni justificatifs ; les états sont recalculés localement avec les mêmes règles (`PermitRightsEvaluator`, précédence et intervalles semi-ouverts) à partir de fenêtres, zones, états de participation/commune/événement synchronisés ; une copie locale périmée ne peut qu'être signalée (avertissement/blocage de fraîcheur à définir), jamais présentée comme à jour ; la révocation d'un jeton ou d'une autorisation n'est connue hors ligne qu'à la synchronisation suivante (limite à afficher).

## 16. LOT 05.2 — liens bénéficiaire, page, QR et PDF (livré)

- **Modèle** : `permit_access_token` (migration `Version20261109150000`, appliquée sur la base de test et, le 9 novembre 2026, sur `civipass`) : empreinte SHA-256 unique, création, expiration (instantané), révocation, émetteur (agent, commune). Voir DM-118.
- **Services** : `PermitAccessService` (voir, émettre, révoquer ; droits, verrous event → participation → lien, audit), `BeneficiaryPassReader` (lecture par secret, réponse unique), `VerificationUrlFactory`, ports `QrCodeRenderer` (endroid) et `PassPdfRenderer` (dompdf). Réutilisables par les notifications du LOT 05.3 sans couplage : `issue()` retourne le secret une fois ; rien n'est stocké en clair.
- **Parcours bénéficiaire** : lien → page (identité, plaques éventuelles, QR, droits par commune et par zone avec états en français, mention « situation à… ») → PDF. Aucun JavaScript ; plein écran du QR par `:target`.
- **Parcours agent** : fiche d'une demande approuvée (ou confirmation de délivrance directe) → « Laissez-passer et lien du bénéficiaire » → générer (lien affiché **une seule fois**, bouton Copier) / révoquer ; liste des liens (état, dates, commune émettrice) sans secret.
- **Limites** : le QR imprimé pointe vers `/v/{jeton}` dont la page est au LOT 05.4 (404 d'ici là) ; pas de notification (05.3) ; durées de conservation à décider ; `trusted_proxies: private_ranges` conditionne la fiabilité des limiteurs par IP (voir `docs/revisions/AVIS-COMPOSER-2026-11.md`).

## 17. LOT 05.2 bis — sécurisation et clôture

- **Fin effective** (DM-118 §4) : le plus tôt de la fin planifiée et de la clôture ; clôture tardive sans effet ; cohérent avec `GrantWindowCalculator`.
- **Symfony 8.1.11** (24 paquets, ciblé) : `composer audit` sans avis. **Proxy de confiance** : Traefik seul (`SYMFONY_TRUSTED_PROXIES`, `docs/authentication.md`), plus de `private_ranges`.
- **Recette navigateur réelle** sur la base de développement (Chrome, mobile 390 px et ordinateur) : parcours agent et bénéficiaire, PDF, QR décodé, permissions. Défaut trouvé et corrigé : Turbo refusait la réponse 200 du formulaire d'émission (`data-turbo="false"`). La route `/v/{jeton}` reste absente : **le contrôle public n'est pas fonctionnel** (LOT 05.4).

## 18. LOT 05.3.1 — outbox transactionnelle (DT-049 révisée)

- **Module** `src/Notification` (`Domain` : `NotificationOutbox`, `OutboxStatus`, `NotificationType`, `SchedulePolicy`, dépôt ; `Infrastructure` : dépôt Doctrine, `OutboxIntentRecorder`). Aucune commande, aucune UI, aucun SMTP, aucun chiffrement.
- **Port** `Permit\Application\NotificationIntentRecorder` (+ `Permit\Domain\NotificationIntent`, `NotificationKind`) ; Permit ne dépend pas de Notification, Notification ne dépend que du port (testé).
- **Table** `notification_outbox` : `id`, `status`, `type`, `deduplication_key` (`UNIQUE`, `utf8mb4_bin`), `permit_id`, `authorization_id`, `occurred_at`, `available_at`, `attempts`, `leased_until`, `processed_at`, `last_error_code`. Index `(status, available_at)` (distributeur), `processed_at` (purge), `permit_id`. Pas de clé étrangère (module isolé, pas de suppression physique en amont).
- **Intégration** : `PermitIssuer::issue()` enregistre l'intention APRÈS la création et l'audit, dans la transaction de décision (instruction et délivrance directe). Première approbation → `PERMIT_ISSUED` (disponible immédiatement) ; commune suivante → `AUTHORIZATION_ADDED` (disponible 10 min après). Rejeu → rien (garde du créateur + vérification de clé).
- **NO_RECIPIENT** : posé à l'enregistrement si la demande n'a pas d'e-mail exploitable ; ligne définitive, jamais reprise.
- **Rattrapage** : `PermitBackfill` appelle `issue(..., notify: false)`.
- **Limites** : le regroupement (fusion, plafond 60 min, suppression d'un message redondant), la réservation par bail, les réessais, la purge et la supervision relèvent des 05.3.2 et suivants ; les colonnes `leased_until`, `attempts`, `last_error_code` sont préparées mais non alimentées.

## 19. LOT 05.3.2 — secrets chiffrés et liens automatiques (DT-049 révisée)

- **Chiffrement** : libsodium XChaCha20-Poly1305 IETF, nonce de 24 octets aléatoire par chiffrement, clé active du trousseau. Données associées : `civipass.notification.secret.v1|{outbox}|{type}|{laissez-passer}` : un secret copié sur une autre ligne, ou une ligne dont le type ou le laissez-passer a changé, ne se déchiffre plus.
- **Trousseau** : `NOTIFICATION_KEYRING="id:base64[,id:base64]"` (clés de 32 octets), `NOTIFICATION_ACTIVE_KEY_ID`. Définis dans `.env.local` ou l'environnement du serveur, **jamais** dans Git (`.env` ne contient que des valeurs vides ; un test le vérifie). Génération : `php -r 'echo base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), PHP_EOL;'`. Contrôle avant activation : `bin/console app:notifications:check-crypto` (code de sortie non nul au moindre défaut, aucune valeur de clé affichée).
- **Rotation** : (1) ajouter la nouvelle clé au trousseau **sans** changer la clé active, déployer ; (2) passer `NOTIFICATION_ACTIVE_KEY_ID` à la nouvelle clé, déployer ; (3) attendre au moins 48 h (durée maximale d'un secret) puis retirer l'ancienne clé ; (4) `check-crypto`. Une clé retirée trop tôt rend les secrets restants illisibles : le message échoue sans lien transmis, la remise manuelle reste possible.
- **Table `notification_secret`** : `id`, `outbox_id` (`UNIQUE`), `access_token_id` (`UNIQUE`), `key_id`, `nonce` (24 octets), `ciphertext`, `created_at`, `expires_at` (+48 h). Pas de clé étrangère (module isolé).
- **Cycle d'un lien automatique** : `SecretProvisioner::provision()` (hors approbation) → verrou de la ligne d'outbox → trousseau utilisable ? → laissez-passer encore utilisable ? → lien `SYSTEM` + secret chiffré + effacement de l'anomalie, dans UNE transaction → (05.3.3 : envoi) → `SecretPurger` : secret supprimé physiquement dès que le message est définitif ou à 48 h ; lien révoqué par le système si le message n'a jamais été transmis (tout état sauf `SENT`) ; un message encore actif dont le secret échoit passe en `SECRET_EXPIRED`.
- **Pertinence** (`SystemAccessLinks::isUsable`) : faux si le délai d'accès (fin effective + 30 jours) est dépassé ou si toutes les zones sont dans un état définitif (expirée, révoquée, événement clos, zone non autorisée).
- **Codes d'anomalie** (`last_error_code`, jamais un message) : `CRYPTO_UNAVAILABLE`, `CRYPTO_UNAVAILABLE_EXPIRED`, `NOT_RELEVANT`, `SECRET_EXPIRED`.
- **Limites** : la fenêtre de 7 jours est comptée depuis `occurred_at` (date de l'événement métier, identique à la création de l'intention) ; l'agent constate l'échec par l'état `ABANDONED` et son code, **sans écran dédié** (supervision au 05.3.3) ; aucun appelant périodique du provisionnement avant le distributeur.

## 20. LOT 05.3.3 — distributeur et supervision (DT-049 révisée)

### États et transitions de l'outbox
| De | Vers | Cause |
|---|---|---|
| (création) | `PENDING` | approbation, adresse présente ; échéance `available_at` (0 min première approbation, 10 min commune supplémentaire) |
| (création) | `NO_RECIPIENT` | aucune adresse à l'approbation (définitif) |
| `PENDING` | `RESERVED` | réservation sous verrou de ligne, bail de 10 min, **essai compté** (`attempts`, `first_attempt_at`) |
| `RESERVED` | `RESERVED` | bail expiré : reprise par un autre passage (essai compté) |
| `RESERVED` | `SENT` | message accepté par le serveur SMTP, bail encore détenu |
| `RESERVED` | `PENDING` | erreur SMTP temporaire : `available_at` = +1, 5, 15, 60, 240 min, code `SMTP_TEMPORARY` |
| `RESERVED` | `PENDING` | clé indisponible au moment d'envoyer : **essai non consommé**, `CRYPTO_UNAVAILABLE` |
| `PENDING`, `RESERVED` | `ABANDONED` | `SMTP_PERMANENT`, `ADDRESS_INVALID`, `SMTP_EXHAUSTED` (6 essais), `SMTP_WINDOW_EXPIRED` (24 h), `NOT_RELEVANT`, `LINK_REVOKED`, `SECRET_UNAVAILABLE`, `SECRET_EXPIRED` (48 h), `CRYPTO_UNAVAILABLE_EXPIRED` (7 jours) |
| `PENDING`, `RESERVED` | `NO_RECIPIENT` | adresse disparue avant l'envoi (aucun envoi automatique ultérieur) |

`SENT`, `ABANDONED` et `NO_RECIPIENT` sont définitifs : le secret chiffré est alors supprimé ; un lien automatique jamais transmis (tout état sauf `SENT`) est révoqué par le système ; un lien d'agent n'est jamais touché.

### Déroulement d'un message
1. état courant du laissez-passer (utilisable ? destinataire ?) ; 2. lien automatique et secret chiffré (`SecretProvisioner`, jamais pour un message déjà tenté) ; 3. réservation ; 4. relecture de l'état courant, secret et lien actif ; 5. composition et envoi **hors transaction, sans verrou** ; 6. état final sous verrou, **seulement si le bail est encore détenu** (sinon `lease_lost`, rien n'est écrasé) ; 7. purge du secret.

### Fenêtre de concurrence résiduelle (assumée)
Aucun verrou métier n'est tenu pendant l'appel SMTP. Entre la dernière lecture de l'état (étape 4) et l'acceptation du message par le serveur SMTP (quelques centaines de millisecondes en régime normal, plus en cas de lenteur du serveur), une révocation, une expiration ou la révocation du lien par un agent peuvent survenir : le message part alors avec un contenu exact quelques instants plus tôt. Le lien et le contrôle public affichent toujours les droits COURANTS ; un lien révoqué répond comme un lien inconnu. Cette fenêtre ne peut pas être fermée sans tenir un verrou pendant un appel réseau, ce qui est exclu.

### Garantie de livraison
Au moins une fois, jamais « exactement une fois ». Un arrêt brutal après l'acceptation SMTP laisse le message `RESERVED` ; à l'expiration du bail il est renvoyé (doublon possible, même Message-ID, même lien, même secret conservé). Un réessai ne crée jamais de lien.

### Erreurs SMTP
Seul le code de réponse est lu (le message d'exception peut citer l'adresse). 4xx, réseau, délai, réponse inattendue : temporaire. 5xx : permanent, **sauf** 530, 534, 535, 538 (authentification ou chiffrement exigé : défaut de configuration, donc réessayable). Adresse invalide (RFC) : jamais réessayée.

### Supervision (`app:notifications:status`)
Affiche les volumes par état et par code d'anomalie, l'âge du plus ancien message en attente, le retard du plus ancien message échu, les réservations bloquées, les abandons récents, les secrets échus non purgés, les messages en attente de clé et l'état du trousseau. Seuils : `--max-due-age` (900 s), `--max-waiting-age` (86 400 s), `--max-failed` (0) sur `--failed-window` (86 400 s), `--lease-grace` (300 s). Sortie : **0** OK ; **1** avertissement (abandons récents) ; **2** critique (trousseau inutilisable, messages en attente de clé, réservation bloquée, message échu non traité, secret échu non purgé). Ni adresse, ni secret, ni identifiant de message.

### Exploitation (PROPOSÉE, non installée)
Aucun cron n'est configuré par ce lot. Proposition pour l'hôte, après accord :
```
* * * * * cd /chemin/civipass && php bin/console app:notifications:dispatch --env=prod >> /var/log/civipass/notifications.log 2>&1
*/5 * * * * cd /chemin/civipass && php bin/console app:notifications:status --env=prod || echo "notifications: anomalie" 
```
Chaque passage purge aussi les secrets ; `app:notifications:purge-secrets` reste disponible seul. Avant activation : `app:notifications:check-crypto`. Plusieurs passages simultanés sont sûrs (verrou de ligne, bail) ; un passage ne dépasse pas `--max-seconds` (50 s par défaut).

### Contenu des messages
Gabarits texte et HTML sobres (`templates/notification/`), sans image, sans ressource externe, sans pixel de suivi. Première approbation : prénom, référence LP, événement, communes et zones, lien bénéficiaire. Commune supplémentaire : extension des droits, communes et zones actuelles, **aucun lien**. Jamais de plaque, d'adresse postale, de nom de famille ni de motif. Expéditeur : `MAILER_FROM`. Message-ID : `{identifiant du message}@{domaine de MAILER_FROM}` (stable à chaque réessai).

### Recette MailCatcher (9 novembre 2026)
`MailCatcherRecetteTest` (hors `make quality`, `CIVIPASS_RECETTE_MAILCATCHER=1`) : vrai Symfony Mailer et vrai serveur SMTP. Deux messages reçus (première approbation avec lien, commune supplémentaire sans lien) ; panne SMTP simulée (aucun serveur) puis reprise : même Message-ID, même lien, aucun nouveau lien. La boîte de développement n'est jamais vidée.

### Limites
Le message de commune supplémentaire est envoyé indépendamment de la première mise à disposition (le regroupement et la suppression d'un message redondant relèvent du 05.3.4) ; aucun écran agent de suivi ; aucun déclenchement manuel ; le plafond de 24 h court depuis le premier essai réellement tenté, pas depuis la création.

## 21. LOT 05.3.4 — ordre, regroupement, suivi agent, envoi manuel (DT-049 révisée)

### Règles d'ordre et de suppression
La mise à disposition (`PERMIT_ISSUED`, automatique ou demandée par un agent) passe toujours en premier. Une extension (`AUTHORIZATION_ADDED`, et plus tard les changements du LOT 05.5) est décidée **sous le verrou de sa ligne**, les messages de mise à disposition du laissez-passer étant lus sous verrou partagé :

| Situation | Décision |
|---|---|
| un message de mise à disposition est `PENDING` ou `RESERVED` | l'extension **attend** (jamais supprimée parce que le premier est en attente) |
| aucun message de mise à disposition n'a été `SENT` (abandonné, sans destinataire, ou inexistant) | `SUPPRESSED` / `FIRST_NOT_DELIVERED` : annoncer « en plus de votre lien » serait faux ; la remise manuelle reste possible |
| un message `SENT` (mise à disposition ou extension) listait déjà l'autorisation | `SUPPRESSED` / `ALREADY_INCLUDED` (redondante) |
| l'autorisation n'est pas dans un message envoyé (changement postérieur à la composition) | l'extension suit son cours |
| à l'envoi, l'autorisation est révoquée ou le laissez-passer inutilisable | `SUPPRESSED` / `INACCURATE` (inexacte) |

La redondance se prouve par les **identifiants** des autorisations listées dans le message envoyé (`covered_authorizations`), jamais par une comparaison d'horloge (un commit tardif ferait perdre une information). Sans identité d'autorisation, aucune suppression pour redondance. Chaque suppression est **auditée** (`NOTIFICATION_SUPPRESSED` : type, autorisation, motif ; jamais d'adresse). Une suppression n'est pas un échec : état `SUPPRESSED`, distinct de `ABANDONED`. L'ordre est revérifié sous verrou au moment de la réservation. Les révocations et la mise à disposition ne sont pas soumises à l'attente des extensions.

### Regroupement
Un changement ordinaire rejoint le groupe des changements ordinaires encore `PENDING` du même laissez-passer ; tout le groupe reçoit la même échéance **`min(dernier changement + 10 min, premier changement + 60 min)`**, fonction pure des dates d'événement (indépendante de l'ordre d'arrivée, testée en permutation et avec six processus concurrents). Un message envoyé ferme le groupe. Première mise à disposition et révocation : jamais regroupées ni retardées ; à l'envoi, la révocation passe avant tout. Contrat du port d'enregistrement : l'appelant tient le verrou du laissez-passer (verrou de la demande à l'approbation ; le LOT 05.5 devra prendre le verrou du laissez-passer).
**Structure réutilisable (LOT 05.5)** : `NotificationType` porte déjà `PERMIT_EXTENDED`, `ZONES_CHANGED`, `VEHICLES_CHANGED` (ordinaires) et `AUTHORIZATION_REVOKED` (prioritaire) avec leur politique ; aucun producteur ni gabarit avant le 05.5 (le compositeur refuse un type sans gabarit). La clôture normale d'un événement ne produit aucune notification.

### Écran agent
Panneau « Notification par e-mail » de l'écran « Laissez-passer » : « En attente d'envoi », « Envoyé au serveur de messagerie » (le serveur a accepté le message, la réception n'est pas garantie), « Aucun e-mail disponible », « Échec d'envoi », « Non envoyé » (avec le motif en français), et l'indicateur « Remise manuelle possible » quand aucun message de mise à disposition n'a atteint le bénéficiaire. Jamais de secret, d'empreinte, de code interne, d'adresse ni de tentative. Une extension n'est montrée qu'à la commune qui porte l'autorisation (les autres communes ne sont ni nommées ni comptées). Mêmes droits que les liens (INSTRUCTOR, MUNICIPALITY_ADMIN, EVENT_MANAGER pour sa propre délivrance directe).

### Envoi manuel
Bouton « Renvoyer par e-mail » (POST, CSRF) : exige participation ACTIVE (relue sous verrou), rôle, adresse e-mail et laissez-passer utilisable ; enregistre une **nouvelle intention** (`PERMIT_ISSUED_MANUAL:{laissez-passer}:{formulaire}`), audit `PERMIT_NOTIFICATION_REQUESTED` ; **aucun SMTP dans la requête** ; le nouveau lien et son secret sont créés à l'envoi (jamais un secret supprimé) ; aucun lien existant n'est révoqué. Double clic : même identifiant de formulaire = une seule intention ; requêtes concurrentes : verrou du laissez-passer et un seul message de mise à disposition en attente à la fois (`ALREADY_PENDING`).

### Recettes
- **MailCatcher** (`MailCatcherRecetteTest`) : vrai Symfony Mailer ; le domaine des liens est **`DEFAULT_URI`** de la configuration réelle (vérifié : `http://civipass.cws.gregory.localhost`), jamais une valeur de test.
- **Navigateur** (Chrome, `var/recette/`) : pages agent réelles dans six états, rendues en bureau et à 390 px. Défaut trouvé et corrigé : à 390 px le libellé du bouton débordait de la carte (raccourci en « Renvoyer par e-mail »).

### Limites
Plusieurs communes ajoutées dans un même groupe : le premier message d'extension envoyé liste toutes les communes et couvre leurs autorisations, les suivantes du groupe sont supprimées comme redondantes (un seul message par groupe). Une commune ajoutée après l'envoi suit son cours. Aucun anti-abus sur le nombre de renvois manuels (audités ; un seul en attente à la fois). La recette navigateur est un rendu de pages réelles, pas une session interactive (les envois et formulaires sont couverts par les tests fonctionnels).

## 22. LOT 05.3.5 — recette finale, sécurisation opérationnelle, exploitation

Exploitation : voir [`exploitation-notifications.md`](exploitation-notifications.md) (clés, rotation, SMTP, distributeur, cron en exemple, supervision, purges, diagnostic, reprise, points ouverts avant production).

### Protection contre les renvois abusifs
`ManualRequestPolicy` (fonction pure) : un seul message de mise à disposition en attente par laissez-passer, **5 minutes** minimum entre deux demandes ACCEPTÉES, **3 demandes acceptées au plus sur 24 heures glissantes** (une demande vieille d'exactement 24 h sort de la fenêtre) ; un double clic (même identifiant de formulaire) n'est compté qu'une fois ; retour explicite à l'agent (`TOO_SOON`, `DAILY_LIMIT_REACHED`, `ALREADY_PENDING`) avec rappel de la remise du lien en main propre, **jamais limitée** (procédure exceptionnelle de l'agent habilité, LOT 05.2). Seuls les renvois acceptés sont audités. Concurrence : les lectures des demandes déjà acceptées se font **sous verrou partagé** (une lecture simple, sous REPEATABLE READ, ne verrait pas la demande qu'une autre transaction vient de valider) et sous le **verrou du laissez-passer**. Pour éviter un interblocage avec l'enregistrement d'une extension (verrous de plage sur `permit_id`), l'approbation d'une commune supplémentaire prend ce même verrou (`PermitIssuer`, après la ligne de la demande ; DT-046). Valeurs provisoires.

### Purge de l'outbox (90 jours, provisoire)
`app:notifications:purge-outbox` : messages en état **définitif** dont le traitement date de plus de 90 jours ; jamais un message actif, jamais un secret encore présent, jamais un lien ; transaction et verrou de ligne par message. **Idempotence préservée sans conserver la ligne** : le rejeu du port d'approbation s'arrête avant l'enregistrement (`UNIQUE(permit_id, participation_id)`) et le rattrapage n'enregistre rien (test de rejeu après purge) ; les clés manuelles portent un identifiant de formulaire à usage unique. **Arbitrage signalé** : un message de mise à disposition `SENT` est CONSERVÉ tant que son laissez-passer est utilisable, car il prouve qu'une extension n'est pas « sans premier message remis » (sinon une extension tardive serait supprimée à tort) ; ces seules lignes (identifiants et dates) dépassent donc 90 jours. Alternative si la politique RGPD l'exige : une table de couverture minimale par autorisation (clé de couverture sans ligne de message), à arbitrer.

### Défaut trouvé par la recette interactive
Avec l'horloge **réelle** (microsecondes), le bail relu en base (`DATETIME`, à la seconde) ne correspondait plus : le message était accepté par le relais SMTP mais restait `RESERVED` (`lease_lost`), son secret n'était pas supprimé et il aurait été renvoyé à l'expiration du bail. Tous les tests automatiques passaient (horloge simulée sans microsecondes). **Corrigé** (bail tronqué à la seconde, comparaison par horodatage) avec un test de non-régression à horloge à microsecondes. Leçon : toute recette doit utiliser l'horloge réelle.

### Recette navigateur authentifiée (application de développement, 9 novembre 2026)
Chrome réel piloté par script, comptes de recette dédiés (mots de passe aléatoires hors dépôt, aucun compte existant modifié), laissez-passer LP-2026-0014 : **36 contrôles sur 36** à la seconde passe (première passe : 30/36, défaut ci-dessus). Couvre : visiteur anonyme redirigé ; connexion d'un agent habilité ; accès au laissez-passer ; panneau de notification ; CSRF (jeton forgé refusé, rien créé) ; envoi manuel par le formulaire (intention en attente, audit, aucun SMTP dans la requête, aucun secret) ; double clic ; second renvoi refusé ; distribution réelle vers MailCatcher (domaine `DEFAULT_URI`, Message-ID, aucun pixel, lien fonctionnel, secret supprimé) ; état affiché « Envoyé au serveur de messagerie » ; limite des 5 minutes ; mobile 390 px (émulation d'appareil, aucun débordement) ; gestionnaire seul refusé (403) ; isolation entre communes (404 uniforme, GET et POST) ; aucun secret ni empreinte dans les écrans ; supervision sans donnée personnelle.
Captures : `var/recette/navigateur-*.png` (non versionnées). **Registre des données de recette** : `var/recette/ledger.json` ; script de nettoyage **non exécuté** : `var/recette/cleanup.sql` (comptes, adhésions, liens, intentions et audits de recette ; à relire : l'audit d'un historique ne se supprime normalement pas, règle 8 de CLAUDE.md).

### Scénarios de la liste de vérification
Première approbation et envoi du lien ; deuxième commune avant / après le premier envoi ; regroupement ; premier message abandonné ; absence d'adresse ; adresse corrigée puis renvoi manuel (aucun envoi automatique non sollicité) ; clé indisponible puis restaurée ; échec SMTP puis réessai ; révocation avant envoi ; lien expiré avant l'envoi ; purge concurrente avec le distributeur ; renvoi soumis aux limites : tous couverts par des tests automatisés (dont concurrence MySQL réelle) ; plusieurs aussi en recette.

## 23. Clôture du LOT 05.3 (9 novembre 2026) et backlog

**Le LOT 05.3 est terminé** (fonctionnellement et techniquement), après la recette interactive, la correction du bail (horloge réelle), les tests de concurrence MySQL et `make quality` (2 173 tests, 14 786 assertions). Aucune migration n'est en attente sur la base de développement (16 exécutées, dernière : `Version20261109213016`).

### État des lots
| Lot | État |
|---|---|
| 05.1 | terminé |
| 05.2 | terminé |
| 05.3 | **terminé** |
| 05.4 (page publique de contrôle) | **non commencé** |
| 05.5 (révisions) | **non commencé** |
| 06 (PWA hors ligne) | **non commencé** |

### Données de recette conservées (décision du 9 novembre 2026)
Conservées provisoirement dans la base de développement, **sans modification** : les trois comptes de recette dédiés et leurs adhésions, les deux messages de notification, les deux liens système, les lignes d'audit correspondantes, et le registre `var/recette/ledger.json`. Les messages de la boîte MailCatcher de développement sont conservés. **`var/recette/cleanup.sql` ne doit PAS être exécuté** : il supprime des lignes d'audit, ce qui exige une procédure spécifique et une validation préalable (voir le backlog).

### Backlog (aucun développement engagé)
1. **Compteur de secrets supprimés** : le rapport du distributeur (`app:notifications:dispatch`) n'inclut pas les purges faites message par message juste après un état définitif (il ne compte que la purge de fin de passage) ; défaut d'affichage, sans effet sur le comportement.
2. **Preuve minimale de première notification** : étudier une table de couverture réduite (par autorisation, sans ligne de message) pour permettre la purge complète de l'outbox à 90 jours, y compris les messages de mise à disposition `SENT` aujourd'hui conservés tant que le laissez-passer est utilisable (§22).
3. **Politique définitive de conservation (RGPD)** : durées de l'outbox (90 jours provisoire), des secrets chiffrés (48 h), des autres données (matrice §11).
4. **Protection des jetons dans les URL des journaux nginx et Traefik** : proposition en §10, non appliquée (infrastructure) ; concerne `/laissez-passer/{secret}` et `/v/{jeton}`.
5. **SMTP de production et supervision** : choix du fournisseur, expéditeur, SPF/DKIM/DMARC, TLS, test HTTPS de bout en bout, planification effective du distributeur, de la supervision et des purges, et supervision de ces commandes planifiées ([`exploitation-notifications.md`](exploitation-notifications.md) §13).
6. **Nettoyage contrôlé des comptes de recette avant production**, sans supprimer indûment les audits : définir une procédure spécifique (par exemple désactivation des comptes et conservation de l'audit, ou anonymisation) validée préalablement ; ne pas utiliser `var/recette/cleanup.sql` en l'état.

## 24. LOT 05.5.1 — socle des révisions et révocation d'une autorisation (DM-119)

**Statut : VALIDÉ le 10 novembre 2026 sur le plan technique et fonctionnel automatisé** (2 554 tests, 17 021 assertions, 3 recettes manuelles restantes). Migration appliquée à `civipass_test` puis à `civipass` (développement) le 10 novembre 2026 ; aucune autre base.

### Socle commun
- **Historique immuable** `PermitRevision` (`permit_revision`) : permit, autorisation (nulle pour plaques et QR), commune qui agit, auteur, type, date UTC, `batch_id`, `reason_code`, `reason_text` (200 caractères, anonymisable), états `before_state` / `after_state` (JSON structurés, sans donnée personnelle ni jeton), `anonymized_at`. Constructeur fermé, aucun mutateur, dépôt limité à `add` et `forPermit` (test d'architecture). `PermitRevisionVehicle` (`permit_revision_vehicle`) : table préparée pour le 05.5.4 (valeur de plaque nullable, anonymisable).
- **Garde commun** `PermitRevisionGuard` : `locate` (adhésion effective, rôle INSTRUCTOR ou MUNICIPALITY_ADMIN, autorisation de SA commune ; refus uniforme sinon ; EVENT_MANAGER refusé) et `lock` (dans la transaction : participation ACTIVE relue sous verrou, rôles relus, verrou du laissez-passer, autorisation relue sous verrou). Ordre des verrous : événement → participation → laissez-passer → autorisation. Réutilisé par toutes les révisions suivantes.
- **Écran agent « Droits du laissez-passer »** (`/communes/{id}/demandes/{id}/droits`, `PermitRightsController`) : l'autorisation de la commune et ses zones, les actions, l'historique (détail pour les révisions de SA commune ; **ligne neutre** — type, date, commune — pour celles des autres : ni motif, ni commentaire, ni auteur lus). `no-store`, CSRF sur toute écriture.

### Révocation
`AuthorizationRevocationService` : motif codé obligatoire (`RevocationReason`, 8 codes validés, libellés français), commentaire de 200 caractères au plus (obligatoire pour `OTHER`, blanc = nul), **confirmation explicite** ; dans UNE transaction : autorisation révoquée (`PermitAuthorization::revoke`, sans méthode inverse), révision immuable, audit `PERMIT_AUTHORIZATION_REVOKED` (code du motif, **jamais** le commentaire), intention de notification prioritaire. **Idempotente** (seconde demande : `AlreadyRevoked`, rien d'écrit) et **irréversible** (toute nouvelle approbation de la même commune est refusée : part terminale, DM-106). Les autres communes et le QR ne changent pas ; la page publique reflète la révocation au scan suivant ; le motif n'y figure jamais.

### Notification `AUTHORIZATION_REVOKED`
Prioritaire (jamais regroupée ni retardée). Gabarits texte et HTML : nomment la commune qui retire son autorisation, listent les autres communes (ou disent qu'il ne reste aucun droit), ne contiennent ni motif, ni commentaire, ni lien. **Le distributeur ne l'écarte plus comme « inexacte »** quand le laissez-passer n'a plus aucun droit : pour une révocation, l'exactitude est « l'autorisation visée est bien révoquée » (`NotificationDispatcher::accurate`).

### Couverture des révisions dans l'outbox (DM-119 C5)
`notification_outbox.revision_id` (révision qui a produit la ligne) et `covered_revisions` (révisions annoncées par un message envoyé). `NotificationOrdering` : la suppression « déjà incluse » d'une **nouvelle commune** se prouve toujours par les autorisations listées ; celle d'une **révision** se prouve **uniquement** par un message envoyé qui a annoncé cette révision. Une modification n'est donc jamais supprimée parce que son autorisation avait déjà été notifiée. Un message de révocation couvre sa seule révision et aucune autorisation.

### Lien bénéficiaire et PDF après révocation (D8)
- Le lien reste consultable dans sa période de validité (accès inchangé) ; la page affiche « **Droits révoqués** » quand toutes les autorisations le sont, « Droits terminés » quand il ne reste aucun droit actif ni à venir.
- **PDF** : plus aucun état, date ni mention de validité (« Valide actuellement », périodes…) ; liste des communes et des zones, plaques, QR, et bandeau « **Seul le contrôle du QR code fait foi** ». **Aucun nouveau PDF** quand il ne reste aucun droit actif ni à venir : réponse 409 lisible (« Droits révoqués : aucun PDF » ou « Droits terminés : aucun PDF »).
- **Anciens PDF** (non rappelables) : leur QR renvoie à l'état courant (`/v/{jeton}` recalcule à chaque scan) ; si le QR a été remplacé (05.5.5) il répond « QR remplacé » (410) ; leur texte imprimé ne vaut pas preuve (il ne contient plus de validité).

### Migration `Version20261110150151` (appliquée aux bases de test et de développement)
Tables `permit_revision` et `permit_revision_vehicle` ; colonnes `notification_outbox.revision_id` et `covered_revisions` (facultatives). Aucune donnée existante lue ni modifiée ; aucune suppression physique. **Retour arrière** : `down()` refuse (`abortIf`) dès qu'une révision existe — supprimer la table détruirait un historique immuable ; on restaure alors une sauvegarde. Générée par `doctrine:migrations:diff --env=test`, inspectée, puis appliquée à `civipass_test` (9 requêtes, schéma en phase avec le mapping), puis à `civipass` (développement, local, `APP_ENV=dev`) après sauvegarde complète (mysqldump, 35 tables) : version Doctrine à jour, schéma conforme, **aucune donnée perdue** (779 lignes avant, 780 après : la seule différence est la ligne de version ; les deux tables neuves sont vides ; les 2 lignes de `notification_outbox` ont `revision_id` et `covered_revisions` nuls). Aucune autre base migrée.

### Tests (tous exécutés, verts)
`PermitRevisionFoundationTest` (unitaire) ; `AuthorizationRevocationTest`, `RevocationNotificationTest` (intégration) ; `RevocationConcurrencyTest` (processus PHP concurrents sur MySQL) ; `PermitRightsScreenTest` (fonctionnel) ; `BeneficiaryPdfTest` (**PDF réellement généré**, lu par `tests/Support/PdfInspector` : texte écrit, absence de mention figée de validité, QR intégré identique pixel pour pixel à celui de l'URL de vérification avec contrôle négatif, refus de génération 409 sans droit actif ni à venir, autorisation révoquée non listée). Le nettoyage de `TestDatabase` purge les deux tables de révisions. Le PDF ne liste plus une autorisation révoquée.

### Contrôles restant avant mise en production (LOT 05.5.1)
- **Recettes manuelles** : les 3 tests ignorés (navigateur, MailCatcher) ; lecture réelle d'un QR (caméra iOS et Android, DM-115) ; consultation de l'écran « Droits » et du formulaire de révocation à 390 px ; ouverture d'un PDF téléchargé dans un lecteur réel ; réception réelle du message de révocation (MailCatcher puis SMTP de recette).
- **Risques résiduels** : le commentaire de révocation est un texte libre (avertissement à l'écran, visible de la seule commune qui l'a saisi, anonymisable) ; un PDF déjà téléchargé garde son texte d'origine et ne peut pas être rappelé (son QR renvoie à l'état courant) ; le QR du PDF est vérifié par égalité de pixels avec le rendu de l'URL de vérification, pas par un décodeur ; `PdfInspector` ne comprend que ce que dompdf produit (un changement de version se verra par un échec de test).
- **Notification de révocation** (décision du 10 novembre 2026) : elle **peut nommer la commune concernée** ; elle ne doit **jamais exposer le motif** ni le commentaire (garanti par test).
- Conservation et anonymisation (DM-119 §9) : hypothèses à valider avant la production (LOT 05.6).

## 25. LOT 05.5.2 — périodes d'autorisation (B2) : prolongation, reprise, raccourcissement

**Statut : LIVRÉ (validé le 10 novembre 2026 sur le plan du développement et de la recette automatisée) ; migration `Version20261110155643` appliquée aux bases `civipass_test` et `civipass` (développement), aucune autre. Contrôle complémentaire des notifications : [lot-05-5-2-cadrage.md](lot-05-5-2-cadrage.md) §14. Prochain : [cadrage du LOT 05.5.3](lot-05-5-3-cadrage.md) (en attente de validation).**

- **Modèle B2** : `PermitAuthorization` porte la période COURANTE (colonnes) et `windows()` renvoie toutes les périodes (closes puis courante) ; `PermitAuthorizationPeriod` (table `permit_authorization_period`) conserve les périodes closes, immuables. `GrantWindowCalculator` calcule `créneaux ∩ période ∩ événement` pour chaque période puis fusionne : l'interruption ne donne aucun droit. Seule `windows()` lit les périodes closes (test d'architecture).
- **Règles** : `AuthorizationPeriodPlanner` (pure, aperçu et écriture identiques) ; `AuthorizationPeriodService` (verrous événement → participation → laissez-passer → autorisation, une transaction : période, éventuelle période archivée, révision, audit sans motif, intention de notification).
- **Écrans** : « Prolonger / Reprendre » et « Raccourcir » depuis « Droits du laissez-passer » : aperçu sans écriture puis confirmation, historique avec l'ancienne et la nouvelle période pour la commune, ligne neutre pour les autres.
- **Notifications** : trois messages distincts — `PERMIT_EXTENDED` (prolongation d'une autorisation en cours), `PERMIT_RESUMED` (réactivation après expiration : nouveau début et nouvelle échéance), `PERMIT_SHORTENED` (prioritaire, jamais regroupé, exact même sans droit restant). Couverture par révision ET par autorisation ; un raccourcissement obsolète après une révocation est conservé `SUPPRESSED` / `SUPERSEDED_BY_REVOCATION` avec audit (voir cadrage §13).
- **Migration** (présentée avant exécution) : une table, deux clés étrangères `RESTRICT`, unicité `(authorization_id, position)` ; aucune donnée existante touchée ; `down()` refusé dès qu'une période close existe.
- **Plafond** : aucun (arbitrage du 10 novembre 2026) ; détection d'usage inhabituel (seuil 20) et protections de performance ; limite physique du type `SMALLINT UNSIGNED` (65 535) documentée (cadrage §12).
- **Tests** : unitaires, intégration MySQL, notifications, concurrence réelle et fonctionnels exécutés (voir le rapport du LOT).

### Contrôles restant avant mise en production (LOT 05.5.2)
Recettes manuelles (3 tests ignorés ; réception réelle des trois messages ; QR après une reprise ; écrans à 390 px ; horaire ambigu dans le navigateur) et risques résiduels : voir [lot-05-5-2-cadrage.md](lot-05-5-2-cadrage.md) §15. Ils s'ajoutent à ceux du 05.5.1 (§24).

## 26. LOT 05.5.3 — zones d'une autorisation (ajout, retrait, réintégration, correction de libellé)

**Statut : LIVRÉ (développé, testé et documenté le 10 novembre 2026) ; AUCUNE migration.** Cadrage, arbitrages, ordre global des verrous et validations préproduction : [lot-05-5-3-cadrage.md](lot-05-5-3-cadrage.md) §15 à §17.

- **Règles** (`ZoneChangePlanner`, pure) : ajout limité aux zones proposables de la participation de la commune et non déjà rattachées (une zone retirée se réintègre) ; retrait logique (`removed_at`), jamais la dernière zone rattachée (une zone suspendue compte comme rattachée mais ne donne aucun droit utilisable) ; ajout et réintégration refusés pendant la suspension de l'événement, retrait et correction permis ; correction de libellé par SYNCHRONISATION avec le libellé courant de la zone, sans texte libre, après confirmation « périmètre inchangé ».
- **Opérations groupées** : une transaction, état final contrôlé, une révision par zone (`batch_id` commun), un audit par zone, UNE intention de notification ; un refus (zone forgée, dernière zone, événement suspendu pour une opération mixte) annule tout.
- **Sécurité** : zones relues sous verrou (appartenance, proposabilité) ; identifiants forgés, étrangers, inexistants ou mal formés : refus uniforme (404) ; une zone d'une autre commune n'est jamais montrée ni nommée dans l'historique d'une autre commune (ligne neutre « Zones modifiées »).
- **Notifications** : `ZONES_CHANGED` (ajout ou réintégration, ordinaire, regroupée) ; `ZONES_REMOVED` (au moins un retrait, prioritaire, décrit aussi les ajouts et réintégrations de l'opération) ; correction de libellé : aucune ; couverture par révision et par autorisation ; un retrait couvre les ajouts en attente, un ajout attend (au plus 24 h) un retrait en attente ; retrait obsolète après révocation : `SUPPRESSED` / `SUPERSEDED_BY_REVOCATION` avec audit ; message neutre (« a encore évolué ») et liste d'aujourd'hui si les zones ont changé avant l'envoi.
- **Public** : le contrôle reflète aussitôt les droits (zone ajoutée comptée, zone retirée à part et hors compteur) ; aucun motif exposé ; QR, décision et demande citoyenne inchangés ; autres communes inchangées.

### Recette transversale (LOT 05.5.6) : éléments conservés du LOT 05.5.3
Validé le 10 novembre 2026 (développement et recette automatisée). Restent à couvrir en recette : la reconstitution d'une succession ajout / retrait / réintégration / retrait d'une même zone ; le comportement de plusieurs révisions à la même seconde (ordre `occurred_at` puis `id`, voir [lot-05-5-3-cadrage.md](lot-05-5-3-cadrage.md) §18) ; la distinction visuelle entre zone rattachée et zone effectivement accessible ; les contrôles sur téléphone réel (QR, PDF) ; la réception réelle des notifications.

## 27. LOT 05.5.4 — véhicules d'un laissez-passer (livré, **aucune migration**)

Arbitrages validés le 10 novembre 2026 : [lot-05-5-4-cadrage.md](lot-05-5-4-cadrage.md) §16 ; réalisation et choix à relire : §17. Ajout, retrait, réactivation et remplacement de plaques (communes au laissez-passer), opérations groupées atomiques validées sur l'état final, maximum de dix, doublons interdits sur un même laissez-passer, dernière plaque non retirable mais remplaçable, mode pied/vélo refusé, suspension de l'événement (retrait seul), confirmation de la portée globale, ligne neutre pour les autres communes, deux révisions liées par `batch_id` pour un remplacement. Les plaques vivent dans `permit_revision_vehicle` (jamais dans l'audit ni les états de révision) : **anonymisation par le LOT 05.6, bloquante avant la production**.

Validé le 10 novembre 2026 (développement et recette automatisée). Recette transversale (05.5.6) conservée : historique neutre d'une commune révoquée, référence du laissez-passer dans les notifications `VEHICLES_*`, lecture réelle des plaques au scan, réception réelle des messages, parcours multicommunal de retrait, faux positifs du détecteur de plaques ([lot-05-5-4-cadrage.md](lot-05-5-4-cadrage.md) §18).

## 28. LOT 05.5.5 — remplacement du QR compromis (livré, **aucune migration**)

Arbitrages validés le 10 novembre 2026 : [lot-05-5-5-cadrage.md](lot-05-5-5-cadrage.md) §14 ; réalisation, constat sur la page publique après clôture et choix à relire : §15. Remplacement atomique du jeton actif (ancien jeton retiré définitivement, HTTP 410 ; nouveau jeton unique ; jamais deux QR valides), MUNICIPALITY_ADMIN seul, idempotence persistante par identifiant d'opération (`batch_id`), remplacement rapproché (référence ressaisie), signalement sans blocage, notification prioritaire `QR_REPLACED` (fusion des seules notifications en attente), ligne neutre pour les autres communes. Aucun jeton dans l'audit, les révisions, l'outbox ni les journaux. **Jetons retirés conservés (410) : durée de conservation par le LOT 05.6, bloquante avant la production.** **Correctif du 10 novembre 2026 (décision définitive)** : événement définitivement clos = la vérification publique n'affiche qu'un statut neutre « Événement terminé », côté serveur dans le lecteur métier ([lot-05-5-5-cadrage.md](lot-05-5-5-cadrage.md) §16, DM-115 amendé).
