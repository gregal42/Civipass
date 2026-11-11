# DM-119 — Révisions des droits d'un laissez-passer : révocation, fenêtres, zones, plaques, QR

- **Identifiant** : DM-119
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : **Proposée** (LOT 05.5.1 livré et validé le 10 novembre 2026 ; cadrage du 05.5.2 : [lot-05-5-2-cadrage.md](../../lot-05-5-2-cadrage.md)) (fiche en cours d'arbitrage). Chaque règle porte sa propre mention : **[Validée]** (arbitrage du commanditaire, LOT 05.5.0 puis autorisation du LOT 05.5.1, 10 novembre 2026) ou **[À valider]**. Aucune règle « À valider » n'est une décision.
- **Décisions remplacées** : Aucune. Complète DM-113 §4, DM-116 (§1 à §3) et DM-117 §1 ; précise l'application de DM-101, DM-106, DM-109, DM-112, DM-114, DM-115 et DM-118.

## 1. Contexte

DM-116 a validé le principe des révisions, DM-113 §4 celui du remplacement exceptionnel du QR, DM-117 la liste des notifications. Aucune de ces opérations n'existe en V1 (colonnes `revoked_at` et `removed_at` sans cas d'usage). Le LOT 05.4.4 a montré que la recette du contrôle public ne peut pas être complète sans elles.

## 2. Arbitrages validés

| # | Décision | Statut |
|---|---|---|
| D1 | Raccourcissement individuel autorisé : motif **obligatoire**, **confirmation explicite**, historique, **notification**. Date demandée déjà passée : **fin immédiate**, sans antidatage. | **[Validée]** |
| D2 | Prolongation après expiration autorisée si l'événement et la participation restent actifs, **sans rétablissement rétroactif** des droits. Préférence fonctionnelle : conserver les périodes successives (§4.2). L'option A (écraser `valid_from`) n'est **pas** retenue. | **[Validée]** pour le principe ; **modélisation [À valider]** |
| D3 | Réintégration d'une zone retirée, historique conservé. | **[Validée]** |
| D4 | Interdiction de retirer la dernière zone : utiliser la révocation. | **[Validée]** |
| D5 | Interdiction d'ajouter une plaque en mode piéton/vélo. | **[Validée]** |
| D6 | Même plaque autorisée sur plusieurs laissez-passer, jamais deux fois dans un même laissez-passer. | **[Validée]** |
| D7 | Audit visible dans chaque commune concernée ; pas de notification d'agent supplémentaire en V1. Lecture retenue : **ligne neutre** pour les autres communes (type, date, commune concernée ; ni motif, ni donnée personnelle). | **[Validée]** |
| D8 | Après révocation de la dernière autorisation : le lien bénéficiaire reste consultable dans sa période de validité et affiche clairement « Droits révoqués » ; aucun PDF ne peut être présenté comme un laissez-passer valide ; le QR d'un ancien PDF renvoie à l'état courant. | **[Validée]** pour les principes ; comportement détaillé **[À valider]** (§6) |
| D9 | Correction explicite du libellé, tracée, sans notification si le périmètre ne change pas. | **[Validée]** |
| D10 | Conservation et anonymisation : les durées proposées sont des **hypothèses**, non des décisions. | **[Validée]** comme hypothèses (§9) |
| — | Plan des sous-lots 05.5.1 à 05.5.6 (QR dans un sous-lot distinct, la recette en 05.5.6). | **[Validée]** |
| — | Remplacement du QR : MUNICIPALITY_ADMIN uniquement ; motif codé obligatoire ; opération transactionnelle ; ancien jeton invalidé ; nouveau jeton créé ; autorisations et droits inchangés ; audit dans toutes les communes concernées ; notification prioritaire. **Aucune limite bloquante** d'un remplacement par heure. | **[Validée]** |
| — | Les motifs ne sont **jamais** exposés sur la page publique. | **[Validée]** |
| — | Aucune suppression physique nouvelle sans validation. | **[Validée]** |
| — | Catalogue de motifs de révocation (8 codes, libellés français à l'écran) ; commentaire de 200 caractères au plus, obligatoire pour `OTHER`, anonymisable, jamais public. | **[Validée]** (LOT 05.5.1) |
| — | PDF : suppression des mentions figées de validité, mention claire « seul le contrôle du QR fait foi » ; après disparition de tous les droits actifs et futurs, refus de génération d'un nouveau PDF avec une réponse métier compréhensible. | **[Validée]** (LOT 05.5.1) |
| — | Audit intercommunal : ligne neutre (type d'opération, date, commune concernée). | **[Validée]** (LOT 05.5.1) |
| — | Révisions : historique immuable, états avant/après structurés, sans donnée personnelle dans les JSON. | **[Validée]** (LOT 05.5.1) |
| — | Outbox : couverture des nouvelles autorisations distincte de celle des révisions ; une modification n'est jamais supprimée parce que son autorisation avait déjà été notifiée. | **[Validée]** (LOT 05.5.1) |
| — | QR remplacé : invalidation de l'ancien jeton, sans suppression physique nouvelle. | **[Validée]** (LOT 05.5.1) |
| — | Périodes successives : orientation **B2** retenue pour le LOT 05.5.2, migration à revoir séparément. | **[Validée]** pour l'orientation ; migration **[À valider]** |
| — | Conservation : durées = hypothèses jusqu'à validation de la politique RGPD. | **[Validée]** comme hypothèses |

## 3. Règles communes [À valider]

1. **Acteur** : INSTRUCTOR ou MUNICIPALITY_ADMIN de la commune titulaire de l'autorisation (adhésion active, écriture), participation ACTIVE, commune ACTIVE, autorisation de sa propre commune et non révoquée. Remplacement du QR : MUNICIPALITY_ADMIN seul. EVENT_MANAGER : aucune révision.
2. Une ressource forgée (autorisation, zone, plaque, laissez-passer d'une autre commune ou d'un autre événement) est refusée par le même refus uniforme `OperationNotAllowed`.
3. **Transaction unique** : modification, révision immuable, audit, intention de notification. Ordre de verrous : événement (partagé), participation, laissez-passer. Relecture de l'état sous verrou.
4. Le QR ne change jamais lors d'une révision ordinaire (DM-113 §3). La décision initiale n'est jamais modifiée (DM-106).
5. Une autorisation révoquée refuse toute révision (périodes, zones, libellé) : la révocation ne se contourne ni par prolongation, ni par réintégration, ni par nouvelle approbation (la part communale est terminale, DM-106).
6. Effet immédiat : aucun statut n'est stocké (DM-112).
7. Isolation communale (DM-101 §3, DM-116 §4) conservée (§7).

## 4. Opérations

### 4.1 Révocation (DM-116 §2) [Validée ; livrée au LOT 05.5.1, migration à valider]
Motif codé obligatoire (§8), horodatée, irréversible, les autres communes subsistent, la décision initiale n'est pas modifiée. Après la dernière révocation : D8 (§6).

### 4.2 Périodes d'autorisation : prolongation, reprise, raccourcissement

**Règles [Validées] (D1, D2)** : motif obligatoire et confirmation explicite pour un raccourcissement, avec notification ; une date de fin passée est remplacée par l'instant de l'opération (jamais antidatée ; la date demandée est conservée dans la révision) ; une reprise après expiration ne rétablit aucun droit pour la période écoulée.

**Règles de détail [À valider]**

| Cas | Règle |
|---|---|
| Prolongation d'une autorisation non expirée | La fin de la **période courante** est repoussée (> fin actuelle, ≤ fin de l'événement). |
| Prolongation après expiration | Événement ACTIVE et participation ACTIVE ; une **nouvelle période** commence à l'instant de l'opération (UTC, à la seconde) et se termine à la fin choisie (≤ fin de l'événement) ; la ou les périodes précédentes sont **conservées** ; aucun droit pendant l'interruption. |
| Raccourcissement | Nouvelle fin < fin effective ; > début de la période courante, sinon refus (révoquer) ; motif et confirmation ; fin passée = fin immédiate ; autorisation déjà expirée : refus. |
| Autorisation non commencée | Seule la fin change. |
| Fin d'événement | Une prolongation ne dépasse jamais la fin de l'événement ; au-delà, c'est la fin de l'événement qui se modifie (existant). |

**Modélisation des périodes successives (option B, étudiée).** Faisabilité : `EffectivePeriods` fusionne déjà une liste d'intervalles ; le calcul des droits d'une zone devient « pour chaque période de l'autorisation : créneaux de la zone ∩ période ∩ événement », puis fusion. L'autorisation d'une commune reste indépendante de celles des autres ; les créneaux de zone s'appliquent à chaque période ; la période de l'interruption n'engendre aucun intervalle ; le jeton n'est pas concerné.

Deux variantes :

| | **B2 — hybride (recommandée)** | B1 — table unique |
|---|---|---|
| Modèle | Les colonnes `valid_from`, `valid_until`, `duration_mode` de `permit_authorization` désignent la **période courante** ; une table `permit_authorization_period` conserve les périodes **antérieures closes** | Toutes les périodes dans la table ; les colonnes de l'autorisation deviennent la copie de la décision |
| Migration | Une table, **sans reprise de données**, réversible | Une table + reprise de toutes les autorisations existantes |
| Code touché | `PermitAuthorization::windows()` (liste), `GrantWindowCalculator::scheduledPeriods` (liste), `PermitRightsEvaluator` (2 appels), affichage | Idem, plus toutes les lectures et écritures des colonnes |
| Tests existants | Les 24 emplois SQL de `valid_from`/`valid_until` (7 fichiers) restent valables | À réécrire |
| Contre | Deux sources (colonnes + table) : seule la méthode `windows()` doit les lire (test d'architecture) | Un seul modèle, mais plus de churn |

Table proposée (B2) : `permit_authorization_period` — `id`, `authorization_id` (FK `RESTRICT`), `position` (SMALLINT), `duration_mode`, `valid_from`, `valid_until` (NOT NULL : une période close a une fin), `closed_at`, `closed_by_revision_id` (FK `RESTRICT`) ; unique `(authorization_id, position)`.

Invariants : périodes ordonnées et disjointes ; seule la courante peut être sans fin (« jusqu'à la clôture ») ; une reprise exige `valid_until` de la période courante ≤ maintenant ; la reprise archive la période courante puis fixe la nouvelle ; une fin immédiate clôt la courante à l'instant de l'opération. La décision initiale garde sa fenêtre d'origine, immuable.

Effets de lecture : `AuthorizationRights` et `PublicAuthorization` exposent la période courante, plus la liste des périodes closes pour l'historique ; sur le contrôle public, les périodes de zone de l'intervalle écoulé n'apparaissent pas.

**Migration : une**, créée au **05.5.2** (pas au 05.5.1, qui ne s'en sert pas), à faire revoir avant exécution (règle 3 du projet).

### 4.3 Zones (D3, D4, D9) [À valider pour les détails]
- Ajout : zone proposable (DM-104, `mayOpenRights()`), de la participation de la commune qui agit, non déjà active dans l'autorisation ; libellé copié à l'ajout.
- Retrait logique (`removed_at`), auteur et date dans la révision ; la zone reste visible au contrôle comme « retirée », hors compteur.
- Dernière zone : retrait refusé ; règle vérifiée sur l'ensemble final de l'opération.
- Réintégration : un nouvel ajout d'une zone retirée efface `removed_at` et recopie le libellé courant ; historique dans les révisions.
- Correction de libellé : par autorisation, ancien et nouveau libellé de la **même** zone affichés, confirmation « le périmètre est le même », révision `ZONE_LABEL_CORRECTED`, aucune notification. Un changement de lieu passe par retrait puis ajout. Aucune propagation automatique d'un renommage.

### 4.4 Plaques (DM-116 §1, D5, D6) **[Validé le 10 novembre 2026]** (livré au LOT 05.5.4 : [lot-05-5-4-cadrage.md](../../lot-05-5-4-cadrage.md))
Mode piéton/vélo : toute opération refusée (refus uniforme). Mode véhicule : de 1 à 10 plaques actives, formats SIV/FNI (DM-103), doublon interdit dans le laissez-passer seulement (même plaque libre sur d'autres laissez-passer). Plaques **communes** au laissez-passer : toute commune qui détient une autorisation **non révoquée**, participation ACTIVE, rôle INSTRUCTOR ou MUNICIPALITY_ADMIN (jamais EVENT_MANAGER), peut les modifier, après **confirmation explicite de la portée globale** (les autres communes concernées sont nommées avant confirmation) et validation serveur sous verrou ; jamais un identifiant d'autorisation fourni par le navigateur. Dernière plaque active : retrait **interdit**, remplacement atomique **permis**. Événement suspendu : retrait permis (sauf dernière plaque), ajout, réactivation et remplacement interdits ; une opération groupée qui contient une action interdite est refusée en entier. Opérations **groupées** atomiques, validées sur l'état final. Un remplacement = **deux révisions immuables** liées par le même `batch_id` (types `VEHICLE_REPLACED_OUT` et `VEHICLE_REPLACED_IN`, numéro de remplacement dans l'état) : jamais reconstitué par l'horodatage. Plaque retirée puis rajoutée = **réactivée** (la ligne est réutilisée ; l'ajout d'une plaque retirée est refusé). Plaques déclarées de la demande jamais modifiées. Audit sans valeur de plaque (DT-037) ; valeurs dans `permit_revision_vehicle`, anonymisables (**LOT 05.6, bloquant avant la production**). Notifications : ajout ou réactivation seuls ordinaires (`VEHICLES_CHANGED`), retrait, remplacement ou opération avec retrait prioritaires (`VEHICLES_REMOVED`), une seule intention par opération, jamais de plaque ni de motif dans le message.

### 4.5 Remplacement du QR (DM-113 §4) — sous-lot 05.5.5 **[Validé le 10 novembre 2026 ; livré : [lot-05-5-5-cadrage.md](../../lot-05-5-5-cadrage.md) §14 et §15]**
**[Validé]** : MUNICIPALITY_ADMIN seul, motif codé obligatoire, opération transactionnelle (ancien jeton retiré, nouveau créé, sous le verrou du laissez-passer), autorisations et droits inchangés, audit dans toutes les communes concernées, notification prioritaire, aucune limite bloquante. Le jeton n'apparaît jamais dans la révision, l'audit ni la notification. L'ancien QR répond « QR remplacé » (410, sans donnée) ; le lien bénéficiaire affiche le nouveau QR ; un lien bénéficiaire compromis se révoque séparément (DM-118).

**Dispositif de protection contre les abus, sans blocage [À valider]** :
1. **Friction, jamais refus** : récapitulatif d'effet (« le QR de toutes les communes et tous les QR imprimés cesseront de fonctionner ») et confirmation ; un second remplacement dans l'heure exige en plus la saisie de la référence `LP-` et un motif « répété », mais **n'est jamais refusé**.
2. **Double envoi** : un identifiant de formulaire à usage unique ; un double clic ne remplace qu'une fois ; deux remplacements concurrents se sérialisent sur le verrou.
3. **Visibilité** : audit dans toutes les communes titulaires et ligne neutre (§7) ; notification prioritaire au bénéficiaire à chaque remplacement.
4. **Alerte d'exploitation** : au-delà de 3 remplacements en 24 h sur un laissez-passer, ou de 10 par agent en 24 h, une ligne structurée (sans secret ni donnée personnelle) est écrite dans le journal et l'audit est marqué « inhabituel ». Aucune action n'est bloquée.
5. **Anti-automatisation** : limitation de débit HTTP par utilisateur, très au-dessus d'un usage humain.

## 5. Notifications [À valider]

| Opération | Type | Catégorie |
|---|---|---|
| Révocation | `AUTHORIZATION_REVOKED` (existant) | prioritaire |
| Prolongation, reprise | `PERMIT_EXTENDED` (existant) | ordinaire, regroupée |
| Raccourcissement (**validée**) | `PERMIT_SHORTENED` (nouveau) | ordinaire, regroupée |
| Zones ajoutées ou retirées | `ZONES_CHANGED` (existant) | ordinaire |
| Plaques ajoutées ou réactivées (**validée**) | `VEHICLES_CHANGED` (existant) | ordinaire, regroupée ; niveau laissez-passer |
| Plaques retirées ou remplacées (**validée**) | `VEHICLES_REMOVED` (nouveau) | prioritaire, jamais regroupée |
| Remplacement du QR (**validée**, 05.5.5) | `QR_REPLACED` | prioritaire ; niveau laissez-passer ; aucune plaque, aucun jeton ni lien dans le message |
| Correction de libellé | aucune | — |
| Remplacement du QR (**validée**) | `QR_REPLACED` (nouveau) | prioritaire ; nouveau lien émis à l'envoi |

**Validé le 10 novembre 2026 : le message de révocation peut nommer la commune concernée, jamais le motif ni le commentaire.** Messages composés à l'envoi à partir de l'état courant ; jamais un motif, une adresse, un secret ni une ancienne valeur de plaque. Sans e-mail : « aucun e-mail disponible », remise manuelle. Les noms de type tiennent dans la colonne (30 caractères). Un message de révocation n'est jamais supprimé comme « inexact ».

## 6. PDF et lien bénéficiaire (D8) [Validé ; livré au LOT 05.5.1]

**Constat** : le PDF est généré à la demande, sans stockage, et contient une **photographie** (identité, plaques, communes, zones, états, périodes) datée de la génération, avec la mention « les droits sont vérifiés au moment du contrôle », et un QR. Un PDF déjà téléchargé ne peut pas être rappelé ; il garde ses textes d'origine.

**Validé** : après révocation de toutes les autorisations, le lien reste consultable dans sa période de validité et affiche « Droits révoqués » ; aucun PDF n'est présenté comme valide ; le QR d'un ancien PDF renvoie à l'état courant (la page `/v/{jeton}` recalcule à chaque scan, ce qui est déjà le cas).

**À valider (comportement détaillé)**
1. **Génération** : quand aucune autorisation n'a de droit actif ou à venir (toutes révoquées, expirées ou événement clos), la route PDF refuse (page « Aucun PDF : droits révoqués » ou « droits terminés », HTTP 409) ; le lien affiche l'état.
2. **Contenu du PDF** : retirer l'écriture d'un état « valide » des zones ; garder commune, zone, périodes prévues et la mention « Photographie du {date}. Seul le QR code fait foi : scannez-le », en bandeau visible. Le PDF n'est ainsi jamais, par son texte, une preuve de validité.
3. **Anciens PDF** : QR vers l'état courant ; si le QR a été remplacé, « QR remplacé » (410) ; après purge du jeton, réponse « inconnu ». Le texte imprimé reste ce qu'il était : c'est une limite assumée et documentée, le contrôle se fait par le scan (DM-115).
4. **Remplacement du QR** : la page bénéficiaire affiche le nouveau QR et propose un nouveau PDF.

## 7. Audit et visibilité entre communes (D7)

**[Validé]** : audit dans chaque commune concernée ; pour les autres communes, **une ligne neutre** (type, date, commune concernée), sans motif ni donnée personnelle. Une commune voit le détail de ses propres révisions seulement (DM-101 §2 et §3). Plaques et QR : un audit dans chaque commune titulaire (révoquée ou non). Aucune valeur personnelle dans l'audit (DT-037). Le wording des lignes neutres est à valider au 05.5.1.

## 8. Catalogue de motifs de révocation [Validé] ; autres opérations [À valider]

Les motifs sont des **codes** (énumération par opération, libellés français), plus un **commentaire court facultatif** (200 caractères, jamais plus), **obligatoire** pour `OTHER`. Aucun motif ni commentaire n'est exposé sur la page publique, dans une notification ou dans l'audit visible d'une autre commune. L'écran déconseille d'y saisir un nom, une plaque ou des coordonnées. Le commentaire est anonymisable (§9).

| Opération | Codes proposés |
|---|---|
| Révocation | `FALSE_STATEMENT` (fausse déclaration), `ELIGIBILITY_LOST` (conditions non remplies), `PUBLIC_SAFETY` (sécurité ou ordre public), `ISSUED_IN_ERROR`, `DUPLICATE`, `MISUSE` (usage non conforme), `BENEFICIARY_REQUEST`, `OTHER` |
| Raccourcissement | `NEED_ENDED`, `AREA_REOPENED`, `PUBLIC_SAFETY`, `ISSUED_IN_ERROR`, `BENEFICIARY_REQUEST`, `OTHER` |
| Prolongation, reprise (motif obligatoire pour la reprise, facultatif sinon) | `NEED_EXTENDED`, `EVENT_EXTENDED`, `INITIAL_DURATION_INSUFFICIENT`, `BENEFICIARY_REQUEST`, `OTHER` |
| Ajout de zone | `NEW_NEED`, `OMITTED_AT_DECISION`, `BENEFICIARY_REQUEST`, `OTHER` |
| Retrait de zone | `NEED_ENDED`, `ZONE_CLOSED_OR_UNSAFE`, `ISSUED_IN_ERROR`, `BENEFICIARY_REQUEST`, `OTHER` |
| Réintégration de zone | `REMOVED_IN_ERROR`, `NEED_RETURNED`, `OTHER` |
| Correction de libellé | `TYPO`, `WORDING_CLARIFIED` (aucun commentaire requis) |
| Ajout de plaque (**validé** le 10 novembre 2026, facultatif) | `NEW_VEHICLE`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER` |
| Retrait de plaque (**validé**, obligatoire) | `VEHICLE_NO_LONGER_USED`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER` |
| Remplacement de plaque (**validé**, obligatoire) | `VEHICLE_CHANGED`, `ENTRY_ERROR`, `BENEFICIARY_REQUEST`, `OTHER` |
| Réactivation de plaque (**validé**, obligatoire) | `REMOVED_IN_ERROR`, `VEHICLE_BACK_IN_USE`, `BENEFICIARY_REQUEST`, `OTHER` |
| Remplacement du QR | `LOST`, `SHARED_OR_PUBLISHED`, `SUSPECTED_MISUSE`, `DEVICE_COMPROMISED`, `OTHER` |

Le code est stocké (`reason_code`, 30 caractères) ; le catalogue vit dans le code (une énumération par opération) : tout ajout passe par une livraison.

## 9. Conservation et anonymisation — **hypothèses**, non décisions [Validées comme hypothèses] — **ABANDONNÉES le 11 novembre 2026**

> **Note du 11 novembre 2026.** Les durées et l'anonymisation progressive de champs ci-dessous sont **remplacées** par le cycle de vie de [DM-120](DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) (clôture, archive intermédiaire cloisonnée, purge, suppression). La ligne « Lien bénéficiaire : purge existante » est **inexacte** : aucune purge ne touche `permit_access_token`. Le texte d'origine est conservé ci-dessous pour mémoire.

Aucune durée n'est implémentée au LOT 05.5. À faire valider par le commanditaire et le responsable de la protection des données avant la production (DM-117 §3). Pivot : fin effective de l'événement (DM-118 §4).

| Donnée | Finalité | Minimisation et mesure | Durée (hypothèse) | Mécanisme proposé |
|---|---|---|---|---|
| Identité, contact, adresse de la demande | instruire, contrôler, contester une décision | seule l'identité (nom, prénom) est copiée au contrôle ; adresse, e-mail et téléphone ne sont jamais publics | 6 mois | anonymisation sur place (remplacement des valeurs) ; décisions, références, compteurs conservés |
| Plaques (`permit_vehicle`, révisions, demande) | contrôle d'accès | exposées au scan par conception (DM-115) ; jamais dans l'audit | 3 mois | remplacement de la valeur par un marqueur unique par ligne ; format et position conservés |
| Jeton QR (actif, retirés) | contrôle | clair en base (régénération du QR) ; jamais journalisé | 30 jours | **neutralisation sur place** : valeur remplacée par son empreinte ; le QR répond « inconnu » ; **pas de suppression de ligne** |
| Lien bénéficiaire | accès au PDF et à la page | empreinte SHA-256 seule | 30 jours (DM-114, en vigueur) | purge existante |
| Révisions (structure : type, date, commune, avant/après sans donnée personnelle) | traçabilité des droits, litiges | aucune donnée personnelle hors plaques et commentaire | conservées avec le laissez-passer | aucune suppression |
| Commentaires de révision | justification interne | codé d'abord, 200 caractères | même échéance que l'identité | texte effacé sur place, code conservé |
| Outbox d'envoi | acheminer le message | aucune adresse copiée dans les intentions | 90 jours (provisoire, `exploitation-notifications.md`) | purge existante |
| Audit | traçabilité, sécurité | aucune valeur personnelle (DT-037) | longue, à fixer | — |

**Immutabilité et règle 8.** Une anonymisation **modifie** des champs désignés, elle **ne supprime pas** d'enregistrement : ni décision, ni autorisation, ni révision. Elle est réservée à un service unique, journalisé (`anonymized_at`), qui ne touche que les colonnes personnelles listées. Hors de ce service, les révisions n'ont aucun mutateur (test dédié). Les purges déjà en vigueur (liens, outbox) sont conservées ; **toute nouvelle suppression physique est exclue sans validation** (d'où la neutralisation du jeton plutôt que sa suppression). À confirmer : que la règle 8 (« pas de suppression physique d'une décision, d'une autorisation ou de son historique ») est bien satisfaite par une anonymisation sur place.

Le mécanisme est un préalable à la production : proposé au LOT 05.6. Le schéma prépare l'anonymisation (champs nullables, `anonymized_at`). Point technique à traiter alors : la contrainte `UNIQUE(permit_id, registration)` impose un marqueur distinct par ligne.

## 10. Schéma des révisions [Validé dans son principe] — migration **préparée, non exécutée**, à valider avant exécution

**`permit_revision`** (immuable, hors anonymisation) : `id`, `permit_id` (FK `RESTRICT`), `authorization_id` (NULL pour plaques et QR, FK `RESTRICT`), `municipality_id` (commune qui agit), `actor_user_id`, `type` (`AUTHORIZATION_REVOKED`, `PERIOD_EXTENDED`, `PERIOD_RESUMED`, `PERIOD_SHORTENED`, `ZONE_ADDED`, `ZONE_REMOVED`, `ZONE_REINSTATED`, `ZONE_LABEL_CORRECTED`, `VEHICLE_ADDED`, `VEHICLE_REMOVED`, `VEHICLE_REINSTATED`, `VEHICLE_REPLACED_OUT`, `VEHICLE_REPLACED_IN` (une révision par plaque), `TOKEN_REPLACED`), `occurred_at` (UTC), `batch_id`, `reason_code` VARCHAR(30), `reason_text` VARCHAR(**200**) NULL, `before_state` et `after_state` (JSON structurés, sans donnée personnelle ; jamais un jeton), `anonymized_at` NULL. Index `(permit_id, occurred_at, id)` et `(authorization_id, occurred_at)`.

**`permit_revision_vehicle`** : `id`, `revision_id`, `vehicle_id`, `action` (`ADDED`, `REMOVED`, `REINSTATED`), `registration` NULL (`utf8mb4_bin`, anonymisable), `format`, `position`.

**Migration du 05.5.1** : ces deux tables seulement. **Migration du 05.5.2** : `permit_authorization_period` (§4.2, option B2), sous réserve de validation. L'outbox pourrait demander une colonne de couverture des révisions (C5), à confirmer au 05.5.1. Aucune colonne ajoutée aux tables existantes.

## 11. Contradictions et points signalés

| # | Point | Traitement |
|---|---|---|
| C1 | DM-116 §3 et la matrice de `lot-05-schema.md` §6 ne prévoient ni raccourcissement ni correction de libellé. | D1 et D9 **complètent** DM-116 (validés) ; matrice à mettre à jour au 05.5.2. |
| C2 | DM-117 §1 ne liste ni raccourcissement ni remplacement du QR. | Notifications `PERMIT_SHORTENED` et `QR_REPLACED` **validées dans leur principe** ; DM-117 sera complété à la livraison. |
| C3 | D7 face à DM-101 §3 (historiques des autres communes masqués). | **Résolu** : ligne neutre (validée). |
| C4 | D2 face à la fenêtre unique copiée (`lot-05-schema.md` §2) : sans modélisation, la période écoulée serait rétablie. | Option A **écartée** ; **B2 validée** sur le principe (10 novembre 2026), implémentée au 05.5.2 ; migration à valider. |
| C5 | DT-049 révisée §21 : la suppression `ALREADY_INCLUDED` par identifiants d'autorisation supprimerait à tort un message de révision. | **Résolu au LOT 05.5.1** (code livré ; colonnes `revision_id` et `covered_revisions` dans la migration en attente) : la règle ne vaut que pour `AUTHORIZATION_ADDED` ; une révision n'est redondante que si un message envoyé l'a annoncée. |
| C6 | Règle 8 du projet face à la réintégration (`removed_at` effacé) et à l'anonymisation. | Conforme si révisions immuables et anonymisation sur place seulement (§9) ; **à confirmer**. |
| C7 | D8 face à `SystemAccessLinks::usable()`. | Cohérent (pas de nouveau lien système) ; test à écrire. |
| C8 | DM-113 §4 réserve le remplacement du QR à MUNICIPALITY_ADMIN, les autres révisions s'ouvrent à INSTRUCTOR. | Intentionnel (validé). |
| C9 | Le PDF actuel était une photographie qui écrivait des états de zone (« Valide actuellement »…) : un ancien PDF restait lisible comme valide. | **Résolu au LOT 05.5.1** (validé) : plus aucun état ni date de validité dans le PDF, bandeau « seul le contrôle du QR code fait foi », refus de génération (HTTP 409) quand il ne reste aucun droit actif ni à venir. Limite assumée : un PDF déjà téléchargé garde son texte ; son QR renvoie à l'état courant. |
| C10 | DM-113 ne fixe aucune fréquence pour le remplacement du QR ; l'hypothèse d'une limite horaire est **abandonnée**. | Dispositif non bloquant (§4.5), **à valider**. |

## 12. Plan des sous-lots [Validé]

Chaque sous-lot livre son service, son écran agent, son gabarit de notification, ses tests (rôles, identifiants forgés, concurrence, isolation, lecteur public, QR stable) et sa documentation. Aucun commit ni migration sans validation.

| Sous-lot | Contenu | Migration |
|---|---|---|
| **05.5.1** | Socle (tables de révisions, garde commun, écran « Droits » avec lignes neutres), révocation, motifs, notification prioritaire, couverture des révisions dans l'outbox (C5), D8 (page, PDF) | **oui** (2 tables) |
| **05.5.2** | Périodes (B2) : prolongation, reprise, raccourcissement (fin immédiate), gabarits, mise à jour de la matrice des permissions | **oui** (`permit_authorization_period`, si B2 validée) |
| **05.5.3** | Zones : ajout, retrait, réintégration, dernière zone, correction de libellé | non |
| **05.5.4** | Plaques (**livré**, aucune migration) | non |
| **05.5.5** | Remplacement du QR, dispositif anti-abus (**livré**, aucune migration) | non |
| **05.5.6** | Recette de bout en bout (matrice du 05.4.4 rejouée avec les vraies opérations) | non |

Hors 05.5 : mécanisme de conservation et d'anonymisation (§9), au LOT 05.6, préalable à la production. La recette mobile du 05.4.4 reprend après le 05.5.6.

## 13. Points restant à valider

1. ~~Migration du LOT 05.5.1~~ : **validée et appliquée** aux bases de test et de développement le 10 novembre 2026 (aucune autre base).
2. Pour le 05.5.2 : migration `Version20261110155643` de `permit_authorization_period` (B2) : **appliquée aux bases de test et de développement** (10 novembre 2026). Arbitrages du 10 novembre 2026 : B2 validée sur le principe ; **aucun plafond** (détection d'usage inhabituel, protections de performance et limite physique documentée : [lot-05-5-2-cadrage.md](../../lot-05-5-2-cadrage.md) §11 à §13) ; suspension : prolongation et reprise interdites, raccourcissement et révocation autorisés (confirmé) ; trois messages distincts (prolongation, réactivation, raccourcissement) ; `PERMIT_SHORTENED` prioritaire ; page publique = période applicable seulement ; durée minimale d'une minute ; prolongation et reprise refusées pendant une suspension ; motifs validés.
3. Règles de détail des autres opérations : **zones (05.5.3), plaques (05.5.4) et remplacement du QR (05.5.5) validées et livrées** ; voir [lot-05-5-3-cadrage.md](../../lot-05-5-3-cadrage.md) §15, [lot-05-5-4-cadrage.md](../../lot-05-5-4-cadrage.md) §16 et [lot-05-5-5-cadrage.md](../../lot-05-5-5-cadrage.md) §14.
4. Catalogues de motifs : période, zones, plaques et **remplacement du QR** validés (§8).
5. Durées de conservation (§9) et interprétation de la règle 8 pour l'anonymisation sur place. **Jetons retirés** : conservés pour répondre HTTP 410 ; durée à fixer par le LOT 05.6 (bloquant avant la production). **Les plaques conservées dans `permit_revision_vehicle` (et dans `permit_vehicle` pour les lignes retirées) doivent être traitées par le mécanisme de conservation et d'anonymisation du LOT 05.6 : point BLOQUANT avant la mise en production** (rappelé dans [lot-05-5-4-cadrage.md](../../lot-05-5-4-cadrage.md) §17).

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)
- [DM-113](DM-113-jeton-de-verification-du-qr-stable-et-remplacement-exceptionnel.md), [DM-116](DM-116-modifications-de-droits-plaques-partagees-et-revocation-communale.md), [DM-117](DM-117-notifications-du-beneficiaire-et-conservation-des-donnees.md)

## Écarts documentaires signalés

Voir §11 (C1 à C10). Aucun document n'est modifié silencieusement.
