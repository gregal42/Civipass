# LOT 05.5.2 — Périodes d'autorisation : cadrage détaillé

**Statut : LOT 05.5.2 LIVRÉ ; cadrage validé le 10 novembre 2026 (arbitrages du §11) ; migration `Version20261110155643` appliquée aux bases `civipass_test` et `civipass` (développement) le 10 novembre 2026, aucune autre ; contrôle complémentaire des notifications réalisé (§14).** Références : [DM-119](decisions/metier/DM-119-revisions-des-droits-d-un-laissez-passer.md) §4.2 (D1, D2, option B2 retenue comme orientation), [lot-05-schema.md](lot-05-schema.md) §24 (socle du 05.5.1, réutilisé tel quel), DM-109 (durées), DM-112 (statut calculé), DM-113 (QR stable), DM-117 (notifications).

## 1. Périmètre

Trois opérations sur la **fenêtre d'UNE autorisation communale**, jamais sur la décision initiale : **prolongation** (D2), **reprise après expiration** (D2), **raccourcissement** (D1). Le QR ne change pas. Les autres communes ne sont pas touchées. Une autorisation révoquée refuse tout. Même garde que la révocation (`PermitRevisionGuard`) : INSTRUCTOR ou MUNICIPALITY_ADMIN de la commune titulaire, participation ACTIVE relue sous verrou, verrous événement → participation → laissez-passer → autorisation.

## 2. Modélisation B2 des périodes successives

### 2.1 Principe
Les colonnes de `permit_authorization` (`duration_mode`, `valid_from`, `valid_until`) désignent la **période COURANTE**. Les périodes **antérieures closes** vivent dans une table neuve. Les droits sont calculés sur l'**union** des périodes (closes + courante) : pour chaque zone, `créneaux de la zone ∩ période ∩ événement`, puis fusion. L'**interruption n'engendre aucun intervalle** : aucune validité, donc aucun rétablissement rétroactif. La décision initiale garde sa fenêtre d'origine, immuable.

### 2.2 Schéma définitif proposé (à valider)

**`permit_authorization_period`** — périodes closes d'une autorisation :

| Colonne | Type | Contrainte |
|---|---|---|
| `id` | BINARY(16) | clé primaire, UUID v7 |
| `authorization_id` | BINARY(16) NOT NULL | FK `permit_authorization(id)` `ON DELETE RESTRICT` |
| `position` | SMALLINT UNSIGNED NOT NULL | rang de la période close (1 = la première) |
| `duration_mode` | VARCHAR(20) NOT NULL | mode au moment de la clôture |
| `valid_from` | DATETIME NOT NULL | UTC, début inclus |
| `valid_until` | DATETIME NOT NULL | UTC, fin **exclue** ; jamais nulle (une période close a une fin) |
| `closed_at` | DATETIME NOT NULL | UTC, instant de l'opération |
| `closed_by_revision_id` | BINARY(16) NOT NULL | FK `permit_revision(id)` `ON DELETE RESTRICT` |

Contraintes et index : `UNIQUE (authorization_id, position)` ; index de clés étrangères (`authorization_id` est couvert par l'unicité ; `closed_by_revision_id` indexé). Entité `PermitAuthorizationPeriod`, immuable (constructeur fermé, aucun mutateur, dépôt `add` et lecture).

**Migration unique** : un `CREATE TABLE` et deux clés étrangères. **Aucune reprise de données** (aucune autorisation n'a de période close à ce jour), aucune colonne modifiée, aucune suppression. `down()` : refus (`abortIf`) dès qu'une ligne existe (immutabilité), sinon `DROP`. Générée par `doctrine:migrations:diff --env=test`, **non exécutée avant votre validation**, puis appliquée à la base de test, puis à `civipass` sur accord explicite, comme au 05.5.1.

### 2.3 Invariants
1. Périodes ordonnées par `position` et **disjointes** ; `valid_until(n) < valid_from(n+1)` strictement (égalité = fusion, interdite : elle ne serait pas une interruption).
2. Seule la période **courante** peut être sans fin (« jusqu'à la clôture ») ; une période close a toujours une fin.
3. Une **reprise** exige que la période courante soit terminée (`valid_until ≤ maintenant`). Elle archive la courante (ligne close, `closed_by_revision_id` = la révision), puis fixe la nouvelle courante `[maintenant, nouvelle fin)`.
4. Un **raccourcissement** et une **prolongation** ordinaire ne modifient que la fin de la période **courante** : jamais une période close.
5. ~~Plafond de 10 périodes closes~~ : **supprimé** (arbitrage du 10 novembre 2026, voir §11 et §12).
6. Tout est écrit dans UNE transaction : autorisation, période archivée, révision, audit, intention de notification.

### 2.4 Impacts de code (liste complète)
Seuls consommateurs de la fenêtre d'une autorisation (relevé exhaustif de `window()` / `validFrom` / `validUntil`) :
- `PermitAuthorization` : `windows(): list<GrantWindow>` (closes puis courante) ; `window()` reste la courante.
- `GrantWindowCalculator::scheduledPeriods` / `effectivePeriods` : acceptent une **liste** de fenêtres ; l'appel à fenêtre unique (décision, aperçu) devient une liste d'un élément. `EffectivePeriods` fusionne déjà une liste d'intervalles.
- `PermitRightsEvaluator` (deux appels) et son test « expiré » : `$at >= fin` devient « après la fin de la **dernière** période ».
- `AuthorizationRights` : période courante + liste des périodes closes (historique).
- `PublicVerificationReader`, `PublicAuthorization`, `PermitExtension::authorizationPeriod` : affichent la période courante ; les périodes de zone de l'interruption n'apparaissent pas ; la période close n'est qu'historique. Ligne « Reprise le … » à valider (§7).
- `PermitIssuer` (audit du mode) : inchangé.

**Tests existants** : les 24 emplois SQL de `valid_from` / `valid_until` (7 fichiers) restent valables, la période courante restant dans les colonnes. Un **test d'architecture** impose que seule `PermitAuthorization::windows()` lise les colonnes de fenêtre.

## 3. Règles fonctionnelles

Notations : `P = [from, until)` période courante (`until` nul = jusqu'à la clôture) ; `now` instant de l'opération sous verrou ; `E` fin prévue de l'événement (nulle : sans fin). **Fin effective** = `min(until, E)` (pour `until` nul : `E`).

### 3.1 Saisie
Prolongation et reprise : **durée fixe ajoutée** (24, 48, 72 h, en temps écoulé, comme DM-109) à la fin courante (ou à `now` pour une reprise), **date et heure de fin locales** (fuseau de l'événement, avec le choix de l'occurrence pour une heure ambiguë, `LocalTimeResolver`), ou **« jusqu'à la clôture »**. Raccourcissement : date et heure de fin locales. Un aperçu serveur affiche la période résultante en heure locale avant confirmation.

### 3.2 Prolongation (période courante non expirée)
- Nouvelle fin **>** fin actuelle ; **≤ E** si `E` existe ; `valid_from` inchangé ; le mode devient `CUSTOM` (ou `UNTIL_CLOSURE`).
- Une autorisation déjà « jusqu'à la clôture » ne se prolonge pas (message : prolonger l'événement).
- Une autorisation non commencée (`from > now`) : seule la fin change.

### 3.3 Reprise après expiration (D2)
- Conditions : `until ≤ now`, **événement ACTIVE**, **participation ACTIVE**, non révoquée, `E > now` (sinon : prolonger d'abord l'événement).
- Nouvelle fin **>** `now`, **≤ E**. Résultat : période close = l'ancienne ; période courante `[now, nouvelle fin)`, `now` à la seconde.
- **Aucun droit pour l'interruption** `[ancienne fin, now)` : visible dans les tests, la page publique et l'historique.
- Motif **obligatoire** (§6).

### 3.4 Raccourcissement (D1, validé)
- Nouvelle fin **<** fin effective ; **>** `from` ; motif obligatoire ; **confirmation explicite** ; notification.
- **Date demandée passée** (`≤ now`) : **fin immédiate**, jamais antidatée : fin appliquée = `now` ; la date demandée est conservée dans la révision ; l'écran l'annonce.
- Si `now ≤ from` (non commencée) : la nouvelle fin doit rester **>** `from`, sinon refus (utiliser la révocation).
- Fin appliquée = `from` (démarrée à la seconde même) : refus (révoquer).
- Autorisation déjà expirée : refus (aucun effet). Mode résultant : `CUSTOM`.

### 3.5 Refus communs
Autorisation révoquée ; participation non ACTIVE ; commune non ACTIVE ; événement clôturé ; durée résultante < 1 minute (à valider) ; plafond de périodes closes atteint ; identifiants forgés (refus uniforme).

## 4. Notifications

| Opération | Type | Catégorie | Remarque |
|---|---|---|---|
| Prolongation, reprise | `PERMIT_EXTENDED` (existant) | ordinaire, regroupée (10 min / 60 min) | gabarit « Vos droits sont prolongés » (texte et HTML) |
| Raccourcissement | **`PERMIT_SHORTENED` (nouveau, 16 caractères)** | **à arbitrer** : ordinaire regroupée (DM-119 §5) ou **prioritaire** | recommandation : **prioritaire**, car il retire des droits plus tôt |

Contenu : prénom, référence, événement, **commune concernée**, **nouvelle fin en heure de l'événement** ; jamais le motif, le commentaire, une adresse, une plaque ou un lien. Composé à l'envoi à partir de l'état courant.

Deux points techniques à ne pas reproduire (leçon du 05.5.1) :
1. **Exactitude** : comme pour la révocation, un message de raccourcissement ne doit pas être écarté « inexact » parce que le laissez-passer n'est plus utilisable après une fin immédiate. Condition d'exactitude : « l'autorisation visée n'est pas révoquée ». Une prolongation exige un laissez-passer utilisable et une autorisation non révoquée.
2. **Couverture** (arbitrage 7 du 05.5.1) : une révision n'est supprimée que si un message envoyé l'a annoncée. Un message `PERMIT_EXTENDED` compose l'état courant et couvre les révisions des lignes `PERMIT_EXTENDED` en attente du même laissez-passer (`covered_revisions`), pour qu'un groupe donne un seul message ; il ne couvre jamais d'autres types.

Colonnes d'outbox : `revision_id` et `covered_revisions` existent déjà (05.5.1) : **aucune migration de notification**. Les noms de type tiennent dans la colonne (30 caractères).

## 5. Parcours agent
Écran « Droits du laissez-passer » : trois actions selon l'état de la période courante — **Prolonger** (période non expirée), **Reprendre** (expirée), **Raccourcir** (non expirée). Chaque action : formulaire avec saisie de la fin, **aperçu** de la période résultante en heure locale, motif (§6), confirmation explicite pour le raccourcissement, CSRF. L'historique affiche, pour les révisions de la commune, l'ancienne et la nouvelle période ; les autres communes voient la ligne neutre (« Période prolongée », « Reprise des droits », « Période raccourcie », commune, date). Participation non ACTIVE : consultation seule.

## 6. Catalogues de motifs proposés (à valider ; DM-119 §8)
- **Prolongation** (facultatif) : `NEED_EXTENDED`, `EVENT_EXTENDED`, `INITIAL_DURATION_INSUFFICIENT`, `BENEFICIARY_REQUEST`, `OTHER`.
- **Reprise** (obligatoire) : mêmes codes.
- **Raccourcissement** (obligatoire) : `NEED_ENDED`, `AREA_REOPENED`, `PUBLIC_SAFETY`, `ISSUED_IN_ERROR`, `BENEFICIARY_REQUEST`, `OTHER`.
Commentaire : 200 caractères au plus, obligatoire pour `OTHER`, anonymisable, jamais public (même règle que la révocation).

## 7. Décisions à valider avant l'implémentation
1. Schéma de `permit_authorization_period` (§2.2, §2.3) — le plafond de 10 périodes closes a été supprimé.
2. Notification de raccourcissement : **prioritaire** (recommandé) ou ordinaire regroupée.
3. Affichage public d'une reprise : ligne « Reprise le … » sous la période courante, ou rien (les zones n'affichent que des intervalles).
4. Durée minimale d'une période : 1 minute.
5. Prolongation d'une autorisation dont l'événement est suspendu : autorisée (les droits restent « événement non actif ») ou refusée. Recommandation : autorisée pour une prolongation ordinaire, refusée pour une reprise (D2 exige un événement actif).
6. Catalogues de motifs du §6.
7. Reprise : motif obligatoire (D2 ne le dit pas ; recommandé car exceptionnel).

## 8. Tests à écrire

**Unitaires** : invariants des périodes (ordre, disjonction, seule la courante sans fin, plafond) ; règles de §3 en table (état de départ → résultat ou refus) ; fin immédiate ; fenêtre unique ≡ liste d'un élément (non-régression du calculateur) ; immutabilité de `PermitAuthorizationPeriod` ; catalogues de motifs.

**Intégration (MySQL)** : chaque opération réelle puis lecture du MÊME QR (jeton stable) ; droits **par commune** (l'autre commune inchangée) et **par zone** ; créneaux de zone sur plusieurs périodes ; **interruption sans droit** (un instant de l'interruption → expiré/hors période) ; atomicité (échec d'audit → rien d'écrit) ; rôles et identifiants forgés ; participation suspendue ; révoquée refusée ; audit (code du motif, jamais le commentaire) ; ligne neutre pour les autres communes ; **concurrence réelle** (deux prolongations simultanées, prolongation contre révocation, reprise contre raccourcissement, prolongation contre modification de la fin de l'événement) ; interplay avec `EventLifecycleService::update`.

**Fuseaux horaires et changement d'heure** (Europe/Paris, 2026) : fin personnalisée à 02:30 le 29 mars (**heure inexistante** : refus clair) ; 02:30 le 25 octobre (**heure ambiguë** : choix de l'occurrence obligatoire, `First` et `Second` donnent deux instants UTC distincts) ; durée fixe de 24 h traversant le passage à l'heure d'été et d'hiver (temps écoulé, affichage local décalé) ; période courante et période close de part et d'autre d'un changement d'heure ; reprise à l'instant exact d'un début de créneau ; bornes `[début, fin)` à la seconde (début inclus, fin exclue) pour chaque période ; union de deux périodes avec une zone à périodes spécifiques à cheval sur la transition ; événement dans un fuseau sans changement d'heure (La Réunion) ; horloge injectée partout.

**Fonctionnels** : trois formulaires (aperçu local, CSRF, confirmation, saisies conservées, 403 et 404 uniformes, `no-store`) ; page publique après chaque opération ; page du bénéficiaire et PDF (PDF refusé / produit selon les droits vivants, non-régression 05.5.1) ; **notifications** : prolongation (groupée en un message), raccourcissement (y compris fin immédiate → message envoyé), couverture par révision, aucun motif dans aucun message.

## 9. Risques et dépendances
- **Risque de lecture double** (colonnes et table) : seul `windows()` doit lire ; test d'architecture.
- **Calcul des droits** : changement du calculateur partagé avec la décision (LOT 04) ; non-régression par les 879 tests unitaires et les tests de décision existants.
- **Heure ambiguë ou inexistante** : réutilisation stricte de `LocalTimeResolver` (aucune règle de fuseau nouvelle).
- **Événement modifié après coup** : une fin d'événement raccourcie écrête les droits sans modifier `valid_until` (comportement existant) ; une fin d'événement prolongée ne prolonge pas une fin fixe : l'écran l'explique.
- **Dépendances** : 05.5.1 (garde, révisions, outbox) ; aucune dépendance aux sous-lots suivants. La table `permit_revision_vehicle` reste inutilisée jusqu'au 05.5.4.

## 10. Découpage interne proposé (après validation)
1. Migration (revue, puis base de test) et entité `PermitAuthorizationPeriod`, `windows()`, calculateur et évaluateur à liste de fenêtres (non-régression d'abord).
2. Services de prolongation, reprise, raccourcissement ; révisions ; audit.
3. Notifications (types, gabarits, exactitude, couverture).
4. Écrans, aperçu, historique.
5. Lecteur public et page du bénéficiaire.
6. Tests de fuseaux, concurrence et recette automatisée ; `bin/qa full`.

## 11. Arbitrages du commanditaire (10 novembre 2026) et écarts avec le cadrage

1. **B2 validée** sur le principe : période courante dans l'autorisation, périodes antérieures dans `permit_authorization_period`.
2. **Aucun plafond** de périodes closes, ni fonctionnel ni arbitraire (confirmé le 10 novembre 2026 : le plafond technique de 500 envisagé est **supprimé**). Conservés : la **détection des usages inhabituels** (`ALERT_THRESHOLD = 20` : l'opération est écrite normalement et marquée « inhabituelle » dans l'audit, jamais bloquée) et des protections techniques qui ne retirent aucun besoin légitime (voir §12 : limites réelles de stockage et de performance).
3. `PERMIT_SHORTENED` : **prioritaire, non regroupée** (catégorie prioritaire, `available_at = occurred_at`) ; envoyée même après une fin immédiate qui retire tout droit.
4. **Page publique** : elle présente les droits et la période applicables au moment du contrôle (période courante) ; l'historique des reprises n'est pas exposé (la période close n'apparaît ni dans la période affichée ni dans les périodes de zone de l'interruption).
5. **Durée minimale d'une minute** pour toute nouvelle période programmée (reprise : fin ≥ maintenant + 60 s ; prolongation : fin ≥ ancienne fin + 60 s ; raccourcissement vers le futur : fin > maintenant + 60 s). Une **fin immédiate** reste possible. Les heures saisies le sont à la minute (`HH:MM`).
6. **Événement suspendu** (arbitrage CONFIRMÉ) : prolongation et reprise interdites ; **raccourcissement et révocation autorisés** ; participation non ACTIVE : tout refusé (habilitation et participation relues sous verrou) ; un événement clos refuse tout.
7. **Motifs** : prolongation ordinaire facultatif ; reprise obligatoire ; raccourcissement obligatoire. Catalogues du §6, commentaire de 200 caractères.

**Écart assumé** : une période qui se termine EXACTEMENT à l'instant de l'opération n'est pas « expirée » (fin exclue mais aucune interruption) : elle est prolongée, pas reprise ; la reprise exige `fin < maintenant` strictement (aucune période adjacente).

## 12. Limites réelles de stockage et de performance (aucun plafond arbitraire)

- **Stockage.** Une ligne de `permit_authorization_period` pèse de l'ordre de 100 à 150 octets avec ses index (clé primaire, unicité `(authorization_id, position)`, deux index de clés étrangères) : environ 10 Mo pour 65 535 périodes d'une seule autorisation (estimation ; les statistiques de la base de test, non à jour, ne permettent pas de la mesurer). La seule limite **physique** est le rang `position` en `SMALLINT UNSIGNED` : **65 535 périodes closes par autorisation**. Au-delà, `AuthorizationPeriodService::POSITION_STORAGE_LIMIT` donne un message clair (« Limite de stockage de l'historique des périodes atteinte ») au lieu d'une erreur SQL : ce n'est pas une règle métier, c'est la capacité du type. Une autorisation normale compte 0 à quelques périodes closes.
- **Attribution des positions.** `position = nombre de périodes closes + 1`, lu en base SOUS le verrou du laissez-passer ; l'unicité `(authorization_id, position)` garantit en dernier recours qu'aucun rang n'est attribué deux fois (une collision lèverait une erreur, jamais un doublon silencieux). Les rangs sont contigus, de 1 à N.
- **Absence de chevauchement.** Garantie par le domaine (`PermitAuthorization::resumeAt` exige une période courante terminée STRICTEMENT avant l'instant de reprise ; la nouvelle période commence à cet instant ; la prolongation et le raccourcissement ne touchent que la fin de la période courante) et vérifiée par les tests (positions contiguës, fin précédente < début suivant, interruption sans droit à la seconde près, reprises successives, concurrence réelle).
- **Performance (mesures indicatives, base de test, une évaluation des droits d'une autorisation).** 100 périodes closes : 3 ms ; 5 000 : 58 ms et +16 Mo ; 65 535 : 0,9 s et +198 Mo. Le coût est **linéaire** en nombre de périodes. **Protection technique mise en place** : un contrôle dont le droit est valide maintenant (le cas courant) n'est calculé que sur la période COURANTE et **ne lit jamais les périodes closes** (vérifié par test : la collection reste non initialisée) ; la page publique et le PDF ne listent que la période courante. Les périodes closes ne sont lues que hors de la période courante (droit non valide, historique), par une seule requête par autorisation. Un usage inhabituel est détecté (audit) bien avant tout risque de performance (seuil d'alerte 20).
- **Conséquence** : sans plafond, un historique de plusieurs dizaines de milliers de périodes pourrait rendre lente la lecture d'une autorisation DÉJÀ non valide (consultation, état), jamais celle d'un droit valide. Ce cas est irréaliste (une reprise par minute pendant 45 jours) et signalé bien plus tôt par l'alerte d'usage inhabituel.

## 13. Notifications : trois messages distincts (arbitrage du 10 novembre 2026)

| Opération | Type | Présentation |
|---|---|---|
| Prolongation d'une autorisation **encore en cours** | `PERMIT_EXTENDED` | « a prolongé la période de votre autorisation, qui était encore en cours » ; nouvelle échéance |
| **Reprise** après expiration | `PERMIT_RESUMED` | « a **réactivé** votre autorisation, qui avait pris fin » ; **nouvelle date de début** et **nouvelle échéance** ; « aucun droit n'est rétabli pour la période écoulée » |
| Raccourcissement | `PERMIT_SHORTENED` | prioritaire ; « a avancé la fin de vos droits » ; nouvelle fin (maintenant pour une fin immédiate) |

**Regroupement exact.** Un message décrit l'état courant d'UNE autorisation et ne couvre que les révisions en attente de CETTE autorisation (jamais celles d'une autre commune). Une reprise couvre ses propres révisions et les prolongations en attente ; un raccourcissement couvre les prolongations en attente (devenues sans objet) mais **jamais** une reprise (la réactivation resterait inconnue du bénéficiaire). Une prolongation en attente **attend** (`held`) qu'une reprise ou un raccourcissement en attente de la même autorisation soit traité, puis est supprimée comme déjà incluse : on n'annonce jamais « prolongé jusqu'à… » avant « réactivé » ou « fin avancée ». Une prolongation postérieure à un message envoyé part toujours.

**Raccourcissement rendu obsolète par une révocation.** La ligne est conservée à l'état `SUPPRESSED` avec le code explicite **`SUPERSEDED_BY_REVOCATION`** et une entrée d'audit `NOTIFICATION_SUPPRESSED` (type, autorisation, révision, motif ; jamais d'adresse). L'écran agent l'écrit : « Cette autorisation a été révoquée depuis : le raccourcissement est devenu sans objet et la révocation est annoncée à la place. » La révocation, elle, est envoyée.

## 14. Contrôle complémentaire des notifications (10 novembre 2026)

Tests ajoutés (`PeriodNotificationTest`, 20 tests, tous verts) et corrections associées :

| Exigence | Comportement et test |
|---|---|
| Échec **temporaire** d'une notification prioritaire | `PERMIT_SHORTENED` : réessais aux échéances de la politique existante (1, 5, 15, 60, 240 min), même `Message-ID`, un seul message reçu, état `SENT` avec le nombre de tentatives |
| Notification prioritaire **définitivement non distribuable** | refus SMTP définitif : `ABANDONED` / `SMTP_PERMANENT`, une seule tentative, jamais réessayée, ligne conservée et visible sur l'écran agent (« Échec d'envoi ») |
| **Reprise de traitement** après incident | message réservé par un distributeur mort : bail valide respecté, bail expiré repris, message envoyé UNE fois |
| **Absence de blocage indéfini** d'une prolongation | l'attente d'une reprise ou d'un raccourcissement en attente est **bornée à 24 h après la première tentative du message bloquant** (`NotificationOrdering::HOLD_LIMIT`) ; un bail jamais repris ne retient plus rien ; un blocage levé par abandon libère la prolongation |
| **Conservation de l'historique et des motifs de suppression** | lignes `SUPPRESSED` / `ABANDONED` avec leur code ; entrée d'audit `NOTIFICATION_SUPPRESSED` (type, autorisation, révision, motif) qui **survit à la purge** de l'outbox après 90 jours |
| **Cohérence du message quand l'échéance a changé** | `NotificationFacts::periodChangedSinceDecision()` compare la période décidée par la révision annoncée et la période d'aujourd'hui ; si elle diffère, le message ne dit plus « prolongé jusqu'à… » ni « réactivé… jusqu'au… » : il dit que la période « a évolué » et donne la situation d'AUJOURD'HUI (jamais une échéance périmée) |

## 15. Contrôles restant avant mise en production (LOT 05.5.2)

- **Recettes manuelles** : les 3 tests ignorés (navigateur, MailCatcher) ; réception réelle des trois messages (prolongation, réactivation, raccourcissement) dans un client de messagerie ; lecture réelle d'un QR après une reprise ; écrans « Prolonger / Reprendre / Raccourcir » à 390 px ; saisie d'un horaire ambigu (dernier dimanche d'octobre) dans le navigateur.
- **Risques résiduels** : (1) un retrait d'incident prolongé (dispatcher arrêté plusieurs jours) peut faire partir une prolongation avant la reprise qui la couvre (au-delà de 24 h de blocage) : le message reste exact car composé de l'état courant ; (2) mesures de performance indicatives (base de test, une évaluation) ; taille réelle de la table estimée ; (3) un message groupé décrit la dernière période : si plusieurs révisions se sont succédé, il est volontairement neutre (« a évolué ») ; (4) une fin immédiate est refusée pour une autorisation démarrée à la seconde même (règle assumée : révoquer) ; (5) conservation des données : hypothèses non validées (DM-119 §9).
