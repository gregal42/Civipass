# LOT 05.5.3 — Zones d'une autorisation : cadrage détaillé

**Statut : LOT 05.5.3 LIVRÉ et VALIDÉ le 10 novembre 2026 (développement et recette automatisée : 2 738 tests, 18 217 assertions, aucune migration) ; recettes manuelles conservées dans la recette transversale (§18).** Références : [DM-119](decisions/metier/DM-119-revisions-des-droits-d-un-laissez-passer.md) §4.3 (D3, D4, D9), DM-104 (zones proposables), DM-116 §3, DM-106, DM-112, [lot-05-schema.md](lot-05-schema.md) §24 et §25 (socle des révisions, garde, notifications, périodes), DM-101 §3 (isolation).

## 1. Périmètre

Cinq opérations sur les **zones de l'autorisation d'UNE commune**, jamais sur la décision initiale ni sur les zones demandées par le citoyen : **ajout**, **retrait**, **réintégration**, interdiction de **retirer la dernière zone** (D4), **correction de libellé** (D9). Le QR ne change pas, les autres communes ne sont pas touchées, une autorisation révoquée refuse tout, la participation communale doit être ACTIVE (relue sous verrou), rôles INSTRUCTOR ou MUNICIPALITY_ADMIN (même garde que la révocation et les périodes).

## 2. Ce qui existe déjà et se réutilise

- `permit_authorization_zone` : `authorization_id`, `zone_id`, `zone_label` (**copie** du libellé au moment de l'ajout), `removed_at`, unicité `(authorization_id, zone_id)`. `PermitAuthorization::activeZones()` et `removedZones()`.
- Calcul des droits : une zone retirée est exclue des droits ordinaires et **visible** au contrôle public (« retirée le… », hors compteur) : déjà implémenté et testé au niveau du lecteur (`RightState::Removed`).
- Socle du 05.5.1 : `PermitRevisionGuard`, `PermitRevision` (immuable, états avant et après structurés), audit, historique avec ligne neutre pour les autres communes, notifications par révision (`revision_id`, `covered_revisions`).
- Règles DM-104 : `EventZone::mayOpenRights()` et `ProposableZones` (zone confirmée, active, non supprimée, validité configurée, participation opérationnelle ; en « périodes spécifiques », au moins un créneau utilisable).
- **Aucune opération d'écriture** n'existe sur ces zones : `PermitAuthorizationZone` n'a ni méthode de retrait ni de réintégration ; `removed_at` n'est écrit nulle part.

## 3. Règles fonctionnelles proposées

Notations : `A` autorisation de la commune ; `Z(A)` zones de `A` non retirées (`removed_at` nul) ; `E` zone de l'événement.

### 3.1 Ajout
- `E` appartient à **la participation de la commune qui agit** (une zone d'une autre commune, d'un autre événement ou inexistante : refus uniforme `OperationNotAllowed`).
- `E` est **proposable** (DM-104, relue sous verrou : `mayOpenRights()` et créneau utilisable), non déjà dans `Z(A)` ; une zone déjà retirée se **réintègre** (§3.3), elle ne se « rajoute » pas.
- `zone_label` = libellé **courant** de `E` à l'instant de l'ajout. La zone suit les périodes de `A` (toutes) et ses créneaux propres : un droit n'existe que là où zone, période et événement se recoupent.
- Il n'est pas exigé que le citoyen ait demandé cette zone (DM-116 §3 : l'autorisation évolue par révision) ; l'agent le voit dans le récapitulatif et le bénéficiaire en est informé.
- Événement non ACTIF (suspendu, clos) : **refusé** (l'ajout accroît des droits ; cohérent avec la prolongation).

### 3.2 Retrait et dernière zone (D4)
- Retrait **logique** : `removed_at` = instant de l'opération ; la ligne n'est jamais supprimée.
- **Interdit si l'opération laisse `Z(A)` vide** : message « utilisez la révocation ». Règle vérifiée sur l'**ensemble final** de l'opération (plusieurs retraits et ajouts dans une même opération : le résultat doit contenir au moins une zone).
- « Zone active » = zone **non retirée** de l'autorisation, quel que soit l'état opérationnel de la zone de l'événement (une zone suspendue provisoirement compte : la suspension est réversible et ne justifie pas de révoquer). À valider.
- Retrait autorisé pendant une suspension de l'événement (il retire des droits, comme le raccourcissement) ; refusé si l'événement est clos.

### 3.3 Réintégration (D3)
- Efface `removed_at` et **recopie le libellé courant** de `E` ; mêmes conditions que l'ajout (zone proposable, participation ACTIVE, événement ACTIF). L'historique est conservé par les révisions (`ZONE_REMOVED` puis `ZONE_REINSTATED`) ; la ligne de la zone ne garde que l'état courant (aucune suppression physique : règle 8 respectée par l'immutabilité des révisions, DM-119 C6).

### 3.4 Correction de libellé (D9)
- Concerne une zone **active ou retirée** de `A` dont le libellé copié diffère du libellé courant de `E`.
- Elle **n'accepte pas de texte libre** : elle **synchronise** le libellé copié avec le libellé courant de la **même** zone (on ne peut pas faire écrire à l'autorisation un libellé arbitraire). L'écran montre l'ancien et le nouveau libellé et exige la confirmation « le périmètre est le même ».
- Révision `ZONE_LABEL_CORRECTED` (ancien et nouveau libellé : non personnels), **aucune notification** ; un changement réel de lieu passe par retrait puis ajout.
- Permise pendant une suspension (elle ne modifie aucun droit), refusée sur un événement clos et sur une autorisation révoquée.
- Aucune propagation automatique d'un renommage de zone : le libellé reste celui de la décision tant qu'un agent ne le corrige pas (comportement actuel, voir le rapport du 05.4.4).

### 3.5 Opérations groupées
Une opération peut ajouter, retirer et réintégrer **plusieurs zones** (un seul formulaire, une seule transaction, un seul `batch_id`). Un motif commun est saisi. Les contrôles (appartenance, proposabilité, dernière zone) portent sur chaque zone puis sur l'ensemble.

## 4. Verrous, concurrence et sécurité

- Ordre de verrous : événement (partagé) → participation → **zones concernées par ordre d'identifiant** → laissez-passer → autorisation (les zones se verrouillent avant le laissez-passer, comme dans `MunicipalDecisionApplier`). Le garde commun reçoit la liste des zones à verrouiller. Une zone suspendue, supprimée ou rendue non proposable pendant la saisie est vue sous verrou.
- Zones, participation et relations relues **côté serveur** : jamais d'identifiant de zone, de commune ou d'événement cru (règle 5). Test avec zones forgées (autre commune, autre événement, zone supprimée, identifiant inexistant ou mal formé).
- Concurrence à éprouver : ajout contre suspension de la zone, deux opérations simultanées sur la même zone (une seule aboutit), retrait contre retrait de la dernière zone, ajout contre révocation (la révocation l'emporte), opération contre opération de période.

## 5. Révisions, audit, historique

- Types de révision ajoutés : `ZONE_ADDED`, `ZONE_REMOVED`, `ZONE_REINSTATED`, `ZONE_LABEL_CORRECTED`. **Une révision par zone modifiée**, regroupées par `batch_id` ; états avant et après structurés (identifiant de zone, libellé, présence), sans donnée personnelle.
- Audit : `PERMIT_ZONE_ADDED`, `PERMIT_ZONE_REMOVED`, `PERMIT_ZONE_REINSTATED`, `PERMIT_ZONE_LABEL_CORRECTED` (identifiants et code de motif ; jamais le commentaire).
- Historique de l'écran « Droits » : détail pour la commune (zones, libellés, motif, auteur), **ligne neutre** pour les autres communes (« Zones modifiées », date, commune), jamais de nom de zone d'une autre commune.

## 6. Lecteur public, page du bénéficiaire, PDF

- **Contrôle public** : une zone ajoutée ou réintégrée apparaît et compte aussitôt (« N zones actives sur M ») ; une zone retirée apparaît dans « Zones retirées » avec sa date, hors compteur (existant). Une zone réintégrée n'y figure plus comme retirée.
- **Page du bénéficiaire** : zones actives avec leur état ; le PDF ne liste que les zones actives de chaque autorisation non révoquée (déjà le cas), sans état figé.
- Le libellé affiché au public est la copie de l'autorisation (corrigée si l'agent l'a corrigée).

## 7. Notifications

| Opération | Type | Catégorie |
|---|---|---|
| Ajout, réintégration (droits accrus) | `ZONES_CHANGED` (existant) | ordinaire, regroupée |
| Retrait (droits retirés), ou opération mixte | **`ZONES_REMOVED` (nouveau)** | **prioritaire**, jamais regroupée (comme le raccourcissement) |
| Correction de libellé | aucune | — |

Le message décrit l'état **courant** (zones actuelles de la commune) et nomme les zones ajoutées ou retirées de l'opération (libellés, non personnels) ; jamais de motif, de commentaire, d'adresse ni de plaque. Exactitude : même règle que les périodes (un retrait reste exact même s'il ne reste plus de droit ; il devient obsolète seulement si l'autorisation est révoquée, avec `SUPERSEDED_BY_REVOCATION` et audit). Couverture : par révision **et par autorisation** (jamais d'une commune à l'autre) ; un retrait couvre les ajouts en attente de la même autorisation (état final), un ajout ne couvre jamais un retrait. Si les zones ont changé entre la décision et l'envoi, le message dit que la liste « a évolué » et donne la liste d'aujourd'hui. Les attentes de regroupement restent **bornées** (24 h après la première tentative du message bloquant).

## 8. Schéma et migration

**Aucune migration prévue.** `permit_authorization_zone` porte déjà `removed_at` et `zone_label` ; les types de révision et d'audit sont des chaînes ; les types de notification tiennent dans la colonne (30 caractères) ; `revision_id` et `covered_revisions` de l'outbox existent. Deux points à vérifier avant d'écrire du code :
1. `permit_revision.type` (VARCHAR(32)) accepte `ZONE_LABEL_CORRECTED` (20 caractères) : oui.
2. Aucune contrainte ne s'oppose à la réintégration (unicité `(authorization_id, zone_id)` : la ligne existe déjà, on efface `removed_at`).
Si l'écriture d'un identifiant de zone dans l'audit ou l'historique devait nécessiter une colonne (par exemple un index de recherche), ce serait une migration à présenter séparément ; ce n'est pas prévu.

## 9. Parcours agent
Depuis « Droits du laissez-passer » : une section **Zones de votre autorisation** (zones actives avec leur état, zones retirées) et trois actions : **Modifier les zones** (cocher des zones proposables à ajouter ou réintégrer, décocher pour retirer, avec **aperçu** de la liste résultante et de l'effet sur le bénéficiaire, confirmation, motif), **Corriger un libellé** (ancien / nouveau, confirmation « même périmètre »). La dernière zone n'a pas de case de retrait : le message renvoie à la révocation. Mêmes protections que 05.5.1 et 05.5.2 : CSRF, `no-store`, saisies conservées, 403 et 404 uniformes.

## 10. Catalogues de motifs proposés (à valider ; DM-119 §8)
- **Ajout** (facultatif) : `NEW_NEED`, `OMITTED_AT_DECISION`, `BENEFICIARY_REQUEST`, `OTHER`.
- **Retrait** (obligatoire) : `NEED_ENDED`, `ZONE_CLOSED_OR_UNSAFE`, `ISSUED_IN_ERROR`, `BENEFICIARY_REQUEST`, `OTHER`.
- **Réintégration** (obligatoire) : `REMOVED_IN_ERROR`, `NEED_RETURNED`, `OTHER`.
- **Correction de libellé** : `TYPO`, `WORDING_CLARIFIED` (code seul, pas de commentaire).
Commentaire : 200 caractères, obligatoire pour `OTHER`, anonymisable, jamais public.

## 11. Décisions à valider avant l'implémentation
1. Une zone **suspendue** compte comme « active » pour D4 (on peut retirer une autre zone en laissant celle-là) : oui proposé.
2. Ajout et réintégration **refusés pendant la suspension** de l'événement ; retrait et correction **permis** : proposé (cohérent avec les périodes).
3. **Correction par synchronisation** avec le libellé courant, sans texte libre : proposé.
4. **Une révision par zone**, regroupées par `batch_id` ; une notification par opération : proposé.
5. **`ZONES_REMOVED` prioritaire** (retrait ou opération mixte) et `ZONES_CHANGED` ordinaire (ajout, réintégration) : proposé.
6. Catalogues du §10 et caractère obligatoire des motifs.
7. Opérations **groupées** (plusieurs zones dans un seul formulaire) : proposées.
8. Ajout d'une zone **non demandée** par le citoyen : permis.

## 12. Tests à écrire
**Unitaires** : planificateur de zones pur (ensemble final, dernière zone, appartenance, proposabilité fournie en entrée, états d'événement) ; catalogues ; immutabilité des révisions de zones ; mutateurs de domaine (`retire`, `reintegrate`, `relabel`) sans méthode inverse non voulue.
**Intégration MySQL** : chaque opération réelle puis lecture du MÊME QR (jeton stable, décision inchangée) ; droits **par commune et par zone** (autre commune inchangée) ; périodes et créneaux sur une zone ajoutée ; zone retirée visible au public hors compteur, réintégrée non ; dernière zone refusée, y compris en opération groupée ; zones forgées (autre commune, autre événement, supprimée, suspendue non proposable, identifiant inexistant) ; atomicité (échec d'audit : rien d'écrit) ; audit sans commentaire ; ligne neutre pour les autres communes ; révocation irréversible (aucune opération sur une autorisation révoquée) ; participation non ACTIVE.
**Concurrence réelle MySQL** : deux ajouts de la même zone, ajout contre suspension de zone, retrait contre retrait de la dernière zone, ajout contre révocation, zones contre période.
**Notifications** : ajout (message), retrait (prioritaire, y compris quand il ne reste qu'une zone), regroupement par autorisation, retrait obsolète après révocation (`SUPERSEDED_BY_REVOCATION`), exactitude quand la liste a changé avant l'envoi, aucune fuite de motif.
**Fonctionnels** : écran, aperçu sans écriture, confirmation, CSRF, saisies conservées, 403 et 404 uniformes, page publique, page du bénéficiaire et PDF après chaque opération.

## 13. Risques et dépendances
- **Relation zone / période / créneaux** : une zone ajoutée peut n'ouvrir aucun droit immédiat (créneaux futurs) : l'écran et le message le disent.
- **Réintégration et `removed_at`** : l'état courant seul est en base, l'historique est dans les révisions : la lecture publique ne doit jamais s'appuyer sur `removed_at` pour une zone réintégrée.
- **Ordre des verrous** : l'ajout des verrous de zones dans le garde est le point délicat (risque d'interblocage avec les services de zones) ; test de concurrence dédié.
- **Notifications** : nouveau type prioritaire et nouvelles règles de couverture ; même vigilance qu'aux 05.5.1 et 05.5.2 (exactitude, couverture par autorisation, attentes bornées).
- **Dépendances** : 05.5.1 et 05.5.2 ; aucune dépendance aux sous-lots suivants.

## 14. Découpage interne proposé (après validation)
1. Domaine (mutateurs, planificateur pur, types de révision, motifs) et tests unitaires.
2. Garde étendu aux verrous de zones, service d'opération, audit, révisions.
3. Notifications (`ZONES_REMOVED`, gabarits, exactitude, couverture).
4. Écrans, aperçu, historique.
5. Lecteur public, page du bénéficiaire, PDF.
6. Tests d'intégration, de concurrence et de recette ; `bin/qa full`.

## 15. Arbitrages du commanditaire (10 novembre 2026) et réalisation

**Validé** : ajout dans la seule commune de l'agent, zones proposables non déjà rattachées ; zone suspendue = zone rattachée pour D4 (aucun droit utilisable) ; ajout et réintégration interdits pendant la suspension de l'événement, retrait et correction permis ; réintégration sans effacer l'historique ; correction par synchronisation avec le référentiel après confirmation « périmètre inchangé » (aucun texte libre) ; ajout d'une zone non demandée permis (audit, demande citoyenne inchangée) ; **opérations groupées** (transaction atomique, état final, jamais d'autorisation sans zone, une révision par zone, un `batch_id`, une seule intention de notification, aucun effet partiel) ; motifs (ajout facultatif, retrait et réintégration obligatoires, correction par code seul) ; `ZONES_CHANGED` ordinaire (ajout ou réintégration sans retrait), **`ZONES_REMOVED` prioritaire dès qu'une opération comporte un retrait**, opération mixte décrivant tout.

**Réalisé** : `ZoneChangePlanner` (pure), `AuthorizationZoneService` (aperçu, modification groupée, correction de libellés), mutateurs de domaine (`addZone`, `retireZone`, `reinstateZone`, `relabelZone`), révisions `ZONE_ADDED`, `ZONE_REMOVED`, `ZONE_REINSTATED`, `ZONE_LABEL_CORRECTED`, audits `PERMIT_ZONE_*`, écrans « Modifier les zones » et « Corriger un libellé », gabarits `zones_changed` et `zones_removed`, exactitude et couverture des notifications.

## 16. Ordre global des verrous (vérifié avec les services existants)

| Service | Ordre |
|---|---|
| Zones de l'événement (`EventZoneService`, validité, suspension) | événement (partagé) → participation → zone |
| Décision municipale (`MunicipalDecisionApplier`) | événement → participation → zones → part → demande → compteur → laissez-passer |
| Remise de lien, notification manuelle | événement → participation → laissez-passer |
| Révocation, périodes (05.5.1 et 05.5.2) | événement → participation → laissez-passer → autorisation |
| **Zones d'une autorisation (05.5.3)** | événement (partagé) → participation → **zones concernées par identifiant croissant** → laissez-passer → autorisation (relue sous verrou) |
| Clôture de l'événement | événement (exclusif) → participations |

Les zones se verrouillent toujours AVANT le laissez-passer (comme dans la décision) : aucun cycle. Preuves par exécution réelle sur MySQL : modification contre révocation (dans les deux sens), contre suspension de zone (dans les deux sens), deux modifications du même laissez-passer, deux communes en parallèle, deux retraits qui videraient l'autorisation, modification contre approbation de l'autre commune (moteur de décision) : aucun interblocage, aucune mise à jour perdue.

## 17. Contrôles restant avant mise en production (LOT 05.5.3)
- **Recettes manuelles** : écrans « Modifier les zones » et « Corriger un libellé » à 390 px ; réception réelle des messages `ZONES_CHANGED` et `ZONES_REMOVED` ; lecture réelle d'un QR après un retrait ; PDF ouvert dans un lecteur réel après une modification ; cohérence des libellés avec le référentiel sur un événement réel.
- **Risques résiduels** : (1) une zone ajoutée peut n'ouvrir aucun droit immédiat (période ou créneaux) : l'écran et le message le disent ; (2) `removed_at` n'est que l'état courant : l'historique des retraits et réintégrations est dans les révisions (immuables) ; (3) un message d'ajout peut être retenu au plus 24 h derrière un retrait bloqué, puis part avec l'état courant ; (4) un message groupé décrit la situation d'aujourd'hui quand la liste a évolué (formulation neutre) ; (5) la correction de libellé exige la confiance de l'agent sur « périmètre inchangé » : elle est auditée, tracée dans l'historique et sans notification, mais non vérifiable par la machine ; (6) conservation et anonymisation : hypothèses non validées (DM-119 §9).

## 18. À conserver dans la recette transversale (LOT 05.5.6) — demandes du 10 novembre 2026

1. **Reconstitution d'une succession ajout / retrait / réintégration / retrait d'une même zone** : l'historique de l'écran « Droits » et les révisions doivent raconter la séquence exacte, avec l'état de la ligne (`removed_at`) à chaque étape. Un test d'intégration le vérifie déjà pour ajout → retrait → réintégration ; la recette ajoute le second retrait et la lecture dans l'interface.
2. **Plusieurs révisions à la même seconde** (limite **acceptée pour la V1** le 11 novembre 2026, décision D2 : [lot-05-5-6-recette.md](lot-05-5-6-recette.md) §6) : l'ordre de l'historique est `occurred_at` puis `id` (UUID v7). Dans un même processus, les UUID v7 sont croissants, donc l'ordre est exact ; entre deux processus qui écrivent dans la même seconde, l'ordre relatif n'est pas garanti. La recette doit vérifier le comportement observé (deux agents, même seconde) et trancher s'il faut un numéro de séquence monotone par laissez-passer (ce serait une migration, à présenter alors séparément). Les états `attachedZones` de chaque révision permettent déjà de reconstituer l'état final d'une opération.
3. **Distinction visuelle entre zone rattachée et zone effectivement accessible** : l'écran affiche « Rattachée » et l'état des droits en minuscules ; à contrôler (libellés, couleurs, contraste, pictogrammes) pour qu'une agente distingue d'un coup d'œil « rattachée mais suspendue / hors période » et « droit utilisable maintenant » ; à vérifier aussi sur la page publique et celle du bénéficiaire.
4. **Contrôles sur téléphone réel** : écrans à 390 px, scan réel du QR (iOS et Android) après une modification, PDF ouvert dans un lecteur réel.
5. **Réception réelle des notifications** `ZONES_CHANGED` et `ZONES_REMOVED` (et celles des LOT 05.5.1 et 05.5.2) dans un client de messagerie réel.
