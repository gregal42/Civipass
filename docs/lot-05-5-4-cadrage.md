# LOT 05.5.4 — Véhicules d'un laissez-passer : cadrage

**Statut : LOT 05.5.4 VALIDÉ le 10 novembre 2026 sur le périmètre du développement et de la recette automatisée (2 827 tests, 19 736 assertions, 3 recettes manuelles en attente ; `bin/qa full` EXIT 0 ; aucune migration). Arbitrages : §16 ; réalisation : §17 ; recette conservée : §18.**

Références : DM-103 (règles de plaque), DM-116 §1 (plaques partagées), DM-117 (notifications : jamais de plaque), DM-119 §4.4 et §8 (catalogue proposé), [lot-05-5-3-cadrage.md](lot-05-5-3-cadrage.md) (modèle à réutiliser), [lot-05-schema.md](lot-05-schema.md) §27.

## 1. Existant (vérifié dans le code)

- `PermitVehicle` (`permit_vehicle`) : `UNIQUE(permit_id, registration)`, `registration` VARCHAR(12) `utf8mb4_bin`, `format` (SIV/FNI), `position`, `removed_at` nullable (déjà en base, jamais écrit). Copie des plaques de la demande à la première émission ; **plaques portées par le laissez-passer, pas par une autorisation communale**.
- `Permit::activeVehicles()` : plaques non retirées. Les contrôleurs externes et la page du bénéficiaire lisent ces plaques (toutes communes confondues).
- `LicensePlate::fromInput()` : normalisation (majuscules, suppression des espaces et tirets) puis SIV `AB123CD` ou FNI ; sinon `DomainRuleViolation`. `PermitRequest::MAX_VEHICLES = 10`, `TravelMode` exclusif (véhicule ou pied/vélo).
- `PermitRevisionVehicle` (préparée au LOT 05.5.1, inutilisée) : `registration` nullable (anonymisable), `action` ADDED / REMOVED / REINSTATED. Les JSON `before/after` des révisions et l'audit **ne contiennent aucune plaque** (DT-037).
- `NotificationType::VehiclesChanged` existe (ordinaire), sans producteur ni gabarit.
- Outils réutilisables : `PermitRevisionGuard`, planificateur pur + service + contrôleur + gabarit, `batch_id`, couverture de notifications par révision, relance de `OperationNotAllowed` avant `DomainRuleViolation` dans les contrôleurs.

## 2. Opérations

| Opération | Effet | Révision(s) |
|---|---|---|
| Ajout | nouvelle ligne `PermitVehicle` (position = max + 1), ou **réactivation** de la ligne retirée de même plaque (contrainte d'unicité) | 1 `VEHICLE_ADDED` (action ADDED, ou REINSTATED si réactivation) |
| Retrait | `removed_at` posé (retrait logique, jamais de suppression) | 1 `VEHICLE_REMOVED` |
| Remplacement | retrait + ajout dans **une** transaction, état final validé | 2 lignes `permit_revision_vehicle` sous une révision `VEHICLE_REPLACED` (ou 2 révisions au `batch_id` commun, voir A8) |
| Opération groupée | plusieurs ajouts/retraits, état final validé | 1 révision par plaque, `batch_id` commun, 1 intention de notification |

Le QR (jeton de vérification) **ne change jamais** ; la page publique et le PDF relisent les plaques actives à chaque affichage. Remplacer le QR reste le LOT 05.5.5.

## 3. Normalisation et validation

- Toute saisie passe par `LicensePlate::fromInput()` (aucune règle dupliquée). Rejet sans écho de la saisie complète dans les journaux ; message d'erreur existant.
- Comparaison des doublons sur la plaque **normalisée**, y compris contre les plaques retirées (réactivation, pas de nouvelle ligne).
- Maximum **10 plaques actives** par laissez-passer, vérifié sur l'**état final** de l'opération (un remplacement ou un groupe qui retire puis ajoute reste valide à 10).
- Aucun doublon actif sur un même laissez-passer. La même plaque reste **autorisée sur d'autres laissez-passer** (DM-103, D6) : aucune contrainte globale, aucun message qui révélerait l'existence de la plaque ailleurs.

## 4. Exclusivité pied/vélo ↔ véhicule

Le mode de déplacement est fixé par la demande et **non modifiable** par ce lot. Mode pied/vélo : toute opération sur les plaques est refusée (`OperationNotAllowed`, 404 uniforme). Changer de mode n'est pas une opération du LOT 05.5 (à cadrer à part si le besoin apparaît).

## 5. Conséquences sur les autres communes

Les plaques sont **partagées** : un ajout ou un retrait vaut pour toutes les communes titulaires d'une autorisation non révoquée. Aucun droit communal (zones, période) n'est modifié ; seule la liste des véhicules contrôlables change. Chaque commune concernée voit :
- une ligne d'historique **neutre** (« les véhicules du laissez-passer ont été modifiés par une autre commune », sans plaque, sans motif, sans auteur) ;
- la liste des plaques actives à jour (déjà visible aujourd'hui).
La commune auteure voit le détail (plaque, motif, auteur).

## 6. Historique immuable

- Une `PermitRevision` par opération (type, motif de catalogue, commentaire ≤ 200 car., acteur, commune agissante, `batch_id`), JSON `before/after` **sans plaque** (compteurs seulement : nombre de plaques actives avant/après).
- Détail dans `permit_revision_vehicle` (plaque, action, position). Cette table est la seule à porter des plaques ; elle est **anonymisable** à la purge (mécanisme du LOT 05.6, non livré : à rappeler dans les risques).
- Audit `AuditTrail` dans la même transaction, sans plaque, avec ligne neutre pour les autres communes.
- Jamais de suppression physique ; la réactivation d'une plaque retirée conserve la ligne et ajoute une révision.

## 7. Notifications au bénéficiaire

- Jamais de plaque dans un message (DM-117). Le message dit seulement que « les véhicules de votre laissez-passer ont été modifiés » et renvoie vers le lien personnel.
- Couverture par **laissez-passer** (les plaques sont de niveau laissez-passer) et non par autorisation ; une notification par laissez-passer et par fenêtre de regroupement. Règles existantes réutilisées : regroupement 10/60 min, suppression `ALREADY_INCLUDED` / `SUPERSEDED_BY_REVOCATION`, libellé neutre « a évolué » si l'état a changé entre décision et envoi.
- Nom de la commune auteure : autorisé comme pour les zones (DM-119), sans motif ni plaque.
- Type et priorité : voir A6.

## 8. Habilitation

Validé par DM-116 §1 : INSTRUCTOR ou MUNICIPALITY_ADMIN d'une commune qui détient une autorisation **non révoquée** sur le laissez-passer, participation ACTIVE, confirmation explicite de l'effet global, audit dans chaque commune concernée. Rôle d'auteur sur la commune agissante (jamais sur un identifiant fourni par le client sans relation vérifiée). Refus uniforme 404 hors périmètre. Voir A1 et A3 pour l'extension éventuelle aux agents non habilités.

## 9. Concurrence et ordre de verrous

Ordre global conservé : événement (partagé) → participation → (zones : **non utilisées ici**) → laissez-passer → ligne d'autorisation. L'opération verrouille le laissez-passer, **relit** ses plaques et son mode après verrou, puis valide l'état final (maximum, doublon, dernier véhicule). Scénarios de test parallèles (`ParallelPhp`) : véhicules ↔ révocation de la commune agissante ; véhicules ↔ révocation d'une autre commune ; véhicules ↔ période/zone ; deux communes modifiant les plaques en même temps ; course sur la dernière plaque ; course sur un doublon ; course sur le plafond de 10 plaques ; double soumission du même formulaire.

## 10. Migration

**Aucune migration attendue** : `permit_vehicle.removed_at`, `position`, `permit_revision_vehicle` et les colonnes de couverture de l'outbox existent. À **revérifier avant tout code** (schéma et mapping), et à présenter séparément si un besoin apparaît (par ex. un type de notification de plus de 30 caractères ; peu probable). Le commentaire `@phpstan-ignore property.unusedType` de `removedAt` sera retiré.

## 11. Plan de tests

- Domaine pur : planificateur (ajout, retrait, remplacement, groupe ; plafond 10 sur état final ; doublon ; dernier véhicule ; mode pied/vélo ; réactivation).
- Normalisation : SIV et FNI valides/invalides, variantes d'espaces, tirets, casse, tirets Unicode, FNI 2A/2B.
- Services : révisions, `permit_revision_vehicle`, audit neutre inter-communes, QR inchangé (jeton identique avant/après), même plaque sur deux laissez-passer.
- Habilitations avec identifiants forgés : autre commune sans autorisation, autorisation révoquée, participation non ACTIVE, rôle insuffisant, laissez-passer d'un autre événement.
- Suspension de l'événement (A5), notifications (A6, jamais de plaque, regroupement, suppression), page publique, PDF (`PdfInspector`) et page bénéficiaire après modification.
- Concurrence (§9). Tests d'architecture : pas de `DELETE` sur `permit_vehicle`, pas de plaque dans l'audit ni les JSON de révision ni les journaux.
- `bin/qa full` obligatoire, `verify-docs.sh`, validation Doctrine.

## 12. Découpage proposé

1. **05.5.4a** domaine (planificateur pur, normalisation, règles) + tests.
2. **05.5.4b** service transactionnel + garde + révisions + audit + concurrence.
3. **05.5.4c** notifications (type, gabarit, couverture par laissez-passer) + résilience.
4. **05.5.4d** interface (écran, confirmation de l'effet global, historique) + recette automatisée.
Un seul lot livrable si la validation le préfère ; la division sert surtout à limiter le risque de la partie notification.

## 13. Risques

- Effet **global** d'une modification communale sur le contrôle des autres communes (A1 à A3).
- Donnée personnelle (plaque) persistée dans `permit_revision_vehicle` : purge/anonymisation dépend du LOT 05.6, non livré.
- Notification mal calibrée : trop de bruit (ajouts ordinaires) ou retard d'un retrait (A6).
- Course sur le plafond ou la dernière plaque si la relecture sous verrou est omise.
- Réactivation vs unicité : une implémentation naïve (insertion) échouerait sur `uniq(permit_id, registration)`.
- Fuite d'information : message d'erreur qui révélerait qu'une plaque existe sur un autre laissez-passer (à éviter).

## 14. Arbitrages (propositions du cadrage ; décisions : §16)

### A1 — Qui peut modifier les véhicules d'un laissez-passer multi-communes ?
- **Option 1** : tout INSTRUCTOR / MUNICIPALITY_ADMIN d'une commune ayant une autorisation non révoquée (DM-116 §1). Conséquence : simple, cohérent avec « plaques partagées » ; effet global assumé par confirmation + audit partout.
- **Option 2** : seulement la commune **émettrice** (première autorisation). Conséquence : protège les autres, mais crée une commune « propriétaire » absente du modèle, bloque si elle révoque ou se retire.
- **Option 3** : MUNICIPALITY_ADMIN seulement. Conséquence : réduit la surface, ralentit l'exploitation.
- **Recommandation : option 1** (déjà validée en DM-116 §1), avec confirmation explicite de l'effet global.

### A2 — Une commune peut-elle retirer une plaque utilisée par une autre commune ?
- Les plaques ne sont pas rattachées à une commune : la question est donc « un retrait vaut-il pour toutes ? ».
- **Option 1** : oui, global, avec ligne neutre dans les autres communes. Conséquence : un retrait peut priver un véhicule de contrôle dans une commune qui ne l'a pas décidé.
- **Option 2** : retrait global seulement si aucune autre commune non révoquée n'a de droit en cours, sinon refus. Conséquence : bloque des corrections légitimes (plaque erronée).
- **Option 3** : retrait par commune (plaque par autorisation). Conséquence : changement de modèle, migration, hors périmètre V1.
- **Recommandation : option 1**, car le modèle validé est « plaques partagées » ; la protection passe par A3.

### A3 — Comment empêcher la modification non autorisée des droits d'une autre commune ?
Les plaques ne sont pas un droit communal, mais elles conditionnent leur exercice. Mesures proposées, cumulables :
- (a) garde serveur : autorisation non révoquée + participation ACTIVE de la commune agissante, relation vérifiée sous verrou ;
- (b) aucune opération sur zones/période d'une autre commune possible depuis cet écran (le service ne reçoit aucun identifiant d'autorisation du client) ;
- (c) confirmation explicite de l'effet global (case + libellé « toutes les communes ») ;
- (d) ligne d'historique neutre et notification de l'événement dans les autres communes.
- **Recommandation : (a)+(b)+(c)+(d)**. Option plus stricte possible : envoi d'une notification interne aux administrateurs des autres communes (à arbitrer, hors V1 sauf demande).

### A4 — Retirer la dernière plaque en mode véhicule ?
- **Option 1** : interdit (« jamais zéro plaque en mode véhicule », DM-103/DM-119 proposés) ; il faut remplacer (ajout + retrait dans la même opération) ou révoquer. Conséquence : invariant simple, pas de laissez-passer « véhicule » inutilisable.
- **Option 2** : autorisé. Conséquence : laissez-passer sans véhicule contrôlable, état ambigu.
- **Recommandation : option 1.** Le test de l'invariant se fait sur l'état final (un remplacement reste possible).

### A5 — Suspension de l'événement
- **Option 1** (analogie zones) : ajout et réactivation refusés, **retrait autorisé** sauf dernière plaque (A4), remplacement refusé (il ajoute).
- **Option 2** : tout refusé. Conséquence : aucune correction d'une plaque erronée pendant la suspension.
- **Option 3** : tout autorisé. Conséquence : étend les droits pendant la suspension, contraire à la règle validée pour zones/période.
- **Recommandation : option 1.**

### A6 — Quels changements déclenchent une notification prioritaire ?
- **Option 1** : retrait et remplacement = prioritaires et non groupés (le bénéficiaire peut être contrôlé avec une plaque qui n'est plus valide) ; ajout = ordinaire groupé. Nécessite un type prioritaire dédié (ex. `VEHICLES_REMOVED`) en plus de `VEHICLES_CHANGED`.
- **Option 2** : tout ordinaire. Conséquence : un retrait peut être notifié avec retard (jusqu'à 60 min).
- **Option 3** : tout prioritaire. Conséquence : bruit pour de simples ajouts.
- **Recommandation : option 1**, cohérente avec `ZONES_REMOVED`. Aucune plaque dans le message ; couverture par laissez-passer.

### A7 — Opérations groupées ?
- **Option 1** : oui (atomiques, état final validé, une révision par plaque, `batch_id` commun, une intention de notification). Conséquence : indispensable au remplacement et à la correction de plusieurs plaques d'un coup.
- **Option 2** : non, opérations unitaires seulement. Conséquence : le remplacement de la dernière plaque devient impossible (A4) sans cas particulier.
- **Recommandation : option 1**, avec un plafond naturel de 10 plaques par opération.

### A8 — Modélisation du remplacement dans l'historique
- **Option 1** : une révision `VEHICLE_REPLACED` portant deux lignes `permit_revision_vehicle` (REMOVED + ADDED). Lisible (« une plaque remplacée »).
- **Option 2** : deux révisions au `batch_id` commun (comme les zones). Cohérent avec 05.5.3 mais moins lisible.
- **Recommandation : option 2** (cohérence avec le modèle déjà livré et le `batch_id`), avec un libellé d'écran qui regroupe l'affichage.

### A9 — Motifs de modification
Catalogue proposé (DM-119 §8, à confirmer) : ajout (optionnel : NEW_VEHICLE), retrait (obligatoire : VEHICLE_SOLD / PLATE_ERROR / OTHER), remplacement (obligatoire : PLATE_CHANGE / PLATE_ERROR). Commentaire libre ≤ 200 caractères, **sans plaque dans le commentaire** (avertissement d'interface ; la plaque est portée par la table dédiée).
- **Recommandation : adopter ce catalogue** après validation de la liste par l'utilisateur.

### A10 — Réactivation d'une plaque retirée
- **Option 1** : autorisée, réactive la ligne, révision REINSTATED. Même règles que l'ajout (plafond, suspension).
- **Option 2** : interdite, il faut en ajouter une autre. Impossible à cause de `uniq(permit_id, registration)` sans changer le modèle.
- **Recommandation : option 1.**

## 15. Ce qui était demandé à la validation

Valider ou amender A1 à A10 (recommandations : A1 option 1, A2 option 1, A3 (a)–(d), A4 option 1, A5 option 1, A6 option 1, A7 option 1, A8 option 2, A9 catalogue, A10 option 1), puis autoriser le développement 05.5.4. Aucun code ni migration n'est écrit avant.

## 16. Décisions du commanditaire (10 novembre 2026)

| Arbitrage | Décision |
|---|---|
| A1 habilitations | Toute commune avec une autorisation **non révoquée**, participation **ACTIVE**, rôle INSTRUCTOR ou MUNICIPALITY_ADMIN (jamais EVENT_MANAGER), **confirmation explicite** de la portée globale, validation serveur **sous verrou** |
| A2 / A3 portée | Plaques communes : modification **annoncée avant confirmation**, **atomique**, **auditée**, **ligne neutre** dans l'historique des autres communes, ni motif ni commentaire exposés ; jamais d'identifiant d'autorisation étrangère fourni par le navigateur |
| A4 dernière plaque | Retrait de la dernière plaque active **interdit** ; remplacement atomique **autorisé** |
| A5 suspension | Retrait permis (sauf dernière plaque) ; ajout, réactivation et remplacement **interdits** ; opération groupée contenant une action interdite : **refus intégral** |
| A6 notifications | Ajout : ordinaire ; retrait, remplacement, opération groupée avec retrait : **prioritaires** ; **une** intention par opération groupée ; aucune plaque ni motif dans le message ; déduplication, exactitude et notifications obsolètes conservées |
| A7 / A8 groupes et remplacement | Opérations groupées atomiques ; remplacement = **deux révisions** liées par le même `batch_id`, avec un **type** qui permet de le reconstituer ; ordre jamais fondé sur le seul horodatage ; état final de tout le laissez-passer vérifié |
| A9 motifs | Catalogue de DM-119 §8, **vérifié** (voir ci-dessous) : ajout facultatif ; retrait, remplacement, réactivation obligatoires ; `OTHER` : commentaire obligatoire (200 caractères) ; **aucune plaque** dans un commentaire |
| A10 réactivation | Autorisée ; ligne réutilisée ; révisions historiques conservées |
| RGPD | Les plaques de `permit_revision_vehicle` relèvent du mécanisme du **LOT 05.6** : **bloquant avant la production** |

**Vérification de cohérence du catalogue de motifs (DM-119 §8).** Le catalogue proposé mêlait `VEHICLE_ADDED` et `VEHICLE_REMOVED` (qui répètent le type de la révision, pas un motif) et un seul jeu de motifs pour quatre opérations. Il est remplacé par **un catalogue par opération**, comme pour les zones : ajout (`NEW_VEHICLE`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER`), retrait (`VEHICLE_NO_LONGER_USED`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER`), remplacement (`VEHICLE_CHANGED`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER`), réactivation (`REMOVED_IN_ERROR`, `VEHICLE_BACK_IN_USE`, `BENEFICIARY_REQUEST`, `OTHER`). Tous tiennent dans `reason_code` (30 caractères). Écart consigné dans [CONTRADICTIONS.md](revisions/CONTRADICTIONS.md) (L5-19).

## 17. Réalisation (LOT 05.5.4) : choix d'implémentation à relire

- **Aucune migration** : `permit_vehicle.removed_at`, `permit_revision_vehicle` et les colonnes de couverture de l'outbox existaient. Schéma Doctrine validé.
- **Domaine** : `VehicleChangePlanner` (pur : état final, plafond 10, doublons, dernière plaque, mode, suspension, réactivation, remplacement), `VehicleChange`, `VehiclePlan`, `VehicleRowState`, `VehicleChangeKind`, quatre énumérations de motifs. `PermitVehicle::retire()` / `reinstate()` (retrait logique, jamais de suppression).
- **Service** `PermitVehicleService` (aperçu sans verrou, écriture sous verrou). Verrous : événement (partagé) → participation → laissez-passer → **lignes de plaques** (`PermitRepository::lockVehicles`, lecture verrouillante : un `SELECT` simple verrait un instantané périmé en `REPEATABLE READ`) → autorisation. Aucune zone n'est verrouillée.
- **Révisions** : `permit_revision.authorization_id` NULL (les plaques appartiennent au laissez-passer), commune agissante renseignée ; états `{activeVehicles, pair}` sans plaque ; la plaque est écrite dans `permit_revision_vehicle` **seulement**. Types `VEHICLE_ADDED`, `VEHICLE_REMOVED`, `VEHICLE_REINSTATED`, `VEHICLE_REPLACED_OUT`, `VEHICLE_REPLACED_IN` ; `pair` relie la sortie et l'entrée de chaque remplacement.
- **Audit** : une ligne détaillée par plaque pour la commune agissante (identifiants, position, code de motif ; jamais la plaque ni le commentaire) ; **une ligne neutre** `PERMIT_VEHICLES_CHANGED_BY_OTHER` par autre commune titulaire, **révoquée ou non** (acteur « système », aucune identité d'agent).
- **Choix à relire** : (1) l'ajout d'une plaque déjà retirée est **refusé** (« réactivez-la ») ; un remplacement dont la nouvelle plaque est une plaque retirée est refusé de même (on réactive puis on retire, dans une opération groupée) ; (2) la détection d'une plaque dans un commentaire refuse les formes SIV (avec ou sans séparateurs), FNI à 3-4 chiffres et toute plaque du laissez-passer ou de l'opération (un commentaire du type « 100 km 35 » est refusé : faux positif accepté par prudence) ; (3) le message au bénéficiaire **ne nomme pas la commune** (les plaques sont communes et la couverture se fait par laissez-passer) ; (4) intention de notification **sans autorisation** (`authorization_id` NULL) ; exactitude : au moins une autorisation non révoquée porte encore une zone visible ; (5) une commune dont l'autorisation est révoquée **ne voit plus les plaques** et ne peut plus les modifier ; (6) un laissez-passer à pied ou à vélo n'a ni panneau ni écran de plaques (404 uniforme).
- **Notifications** : `VEHICLES_CHANGED` (ordinaire, regroupé), `VEHICLES_REMOVED` (prioritaire) ; couverture par laissez-passer, un retrait couvre les ajouts en attente, un ajout attend (au plus 24 h) un retrait en attente ; suppression `INACCURATE` + audit si plus aucune autorisation non révoquée. Gabarits `vehicles_changed` et `vehicles_removed` (texte et HTML) sans plaque ni motif.
- **Écran** : « Modifier les plaques » (`/communes/{id}/demandes/{id}/droits/vehicules`), panneau « Plaques du laissez-passer » sur l'écran des droits, historique détaillé pour la commune qui a agi (plaque, motif, auteur, numéro de remplacement), ligne neutre « Véhicules modifiés » pour les autres.
- **RGPD (BLOQUANT avant la production)** : les plaques conservées dans `permit_revision_vehicle` (et les lignes retirées de `permit_vehicle`) devront être traitées par le mécanisme de conservation et d'anonymisation du **LOT 05.6** (DM-119 §9 : remplacement de la valeur par un marqueur unique par ligne, format et position conservés). Tant que ce mécanisme n'existe pas, aucune mise en production.
- **Tests** : `VehicleChangePlannerTest` (18), `PermitVehicleServiceTest` (35), `VehicleNotificationTest` (9), `VehicleConcurrencyTest` (9 : plaques contre révocation, deux communes, dernière plaque, doublon, plafond de dix, réactivation, zones), `PermitVehicleScreenTest` (11 : écran, CSRF, rôles, 404, suspension, page publique, page bénéficiaire, PDF réel, QR inchangé, ligne neutre), `VehicleRevisionArchitectureTest` (7).
- **Recette transversale (05.5.6)** : lecture réelle des plaques au scan sur téléphone, réception réelle des messages `VEHICLES_*`, parcours « un agent d'une commune retire une plaque que l'autre commune utilisait ».

## 18. À conserver pour le LOT 05.5.6 (validé le 10 novembre 2026)

Ces éléments ne bloquent pas le cadrage du 05.5.5 mais ne doivent pas disparaître de la roadmap :
1. Vérifier l'**historique neutre d'une commune révoquée** (elle reçoit la ligne neutre `PERMIT_VEHICLES_CHANGED_BY_OTHER` mais ne voit plus les plaques : contrôler l'écran réel).
2. Vérifier la **référence du laissez-passer** dans les notifications `VEHICLES_*` (les gabarits actuels ne la nomment que dans l'objet : à confirmer dans un client réel).
3. **Lecture réelle des plaques** lors d'un scan sur téléphone (page publique et PDF).
4. **Réception réelle** des notifications `VEHICLES_CHANGED` et `VEHICLES_REMOVED`.
5. **Parcours multicommunal** : une commune retire une plaque utilisée par une autre (annonce de portée, ligne neutre, notification prioritaire).
6. **Faux positifs du détecteur de plaques dans les commentaires** (ex. « 100 km 35 ») : mesurer sur de vrais commentaires et ajuster, sans jamais relâcher le refus d'une vraie plaque.
