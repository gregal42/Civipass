# LOT 05.5.5 — Remplacement du QR compromis : cadrage

**Statut : LOT 05.5.5 VALIDÉ le 10 novembre 2026 sur le périmètre du développement et de la recette automatisée (2 894 tests, 21 024 assertions, `bin/qa full` EXIT 0 au second passage) ; correctif de confidentialité après clôture : §16 ; stabilité : §17 ; AUCUNE migration.**

Références : DM-113 §4 (remplacement exceptionnel), DM-115 (exposition publique du QR), DM-118 (lien bénéficiaire), DM-119 §4.5 et §5 (règles déjà validées : MUNICIPALITY_ADMIN seul, motif codé obligatoire, opération transactionnelle, droits inchangés, notification prioritaire, **aucune limite bloquante**), [lot-05-5-4-cadrage.md](lot-05-5-4-cadrage.md) (modèle à réutiliser).

## 1. Existant (vérifié dans le code)

- `VerificationToken` (`permit_verification_token`) : 160 bits en 32 caractères base32, **en clair** (nécessaire pour régénérer le QR et le PDF), `UNIQUE(token)` et `UNIQUE(active_permit_id)` : un seul jeton actif par laissez-passer (`active_permit_id` = identifiant du laissez-passer tant que le jeton est actif, NULL une fois retiré ; plusieurs NULL permis). `retired_at` et le retrait sont déjà prévus (deux `@phpstan-ignore` « LOT 05.5 » à retirer). `Permit::activeToken()`.
- `PublicVerificationReader` renvoie déjà `Replaced` pour un jeton retiré ; le contrôleur répond **410 sans aucune donnée** (`verification_replaced.html.twig`), et ce cas **ne compte pas comme un échec** dans la limitation de débit.
- Le QR, le PDF et la page du bénéficiaire sont **recalculés à chaque affichage** à partir du jeton actif : le lien bénéficiaire (`permit_access_token`, DM-118) montre donc le nouveau QR sans autre travail. Un PDF déjà téléchargé garde l'ancien QR (il ne peut pas être rappelé : il porte la mention « seul le contrôle du QR code fait foi »).
- Outils réutilisables (05.5.1 à 05.5.4) : `PermitRevisionGuard`, révisions immuables (`TOKEN_REPLACED` figure déjà dans la liste de DM-119 §10), `batch_id`, ligne neutre, `AuditTrail` (ligne neutre « système » par autre commune titulaire), outbox prioritaire, couverture par laissez-passer, verrou `permits->lock()` puis relecture verrouillante, `ParallelPhp`.
- Le garde actuel accepte INSTRUCTOR **et** MUNICIPALITY_ADMIN : il faut un mode « administrateur seul ».

## 2. Opération

Une seule opération : **remplacer le jeton actif du laissez-passer**. Dans une transaction, sous le verrou du laissez-passer :
1. relire (lecture verrouillante) le jeton actif ;
2. générer un nouveau jeton, vérifier l'absence de collision, **retirer l'ancien** (`retired_at`, `active_permit_id` NULL) **puis** insérer le nouveau (`active_permit_id` = laissez-passer) ;
3. écrire la révision `TOKEN_REPLACED`, l'audit, l'intention de notification.

Conservés tels quels : le laissez-passer, sa référence `LP-`, ses autorisations, zones, périodes, plaques, la demande citoyenne, les liens bénéficiaires. **Jamais deux QR valides en même temps** : l'ancien est retiré et le nouveau créé dans la même transaction ; aucune autre transaction ne voit un état intermédiaire (lecture publique : un jeton est « actif » ou « retiré », jamais les deux ni aucun avant le commit).

Point technique à traiter explicitement : l'index unique `active_permit_id` interdit d'insérer le nouveau jeton tant que l'ancien est actif, et l'ordre d'écriture de Doctrine n'est pas garanti. L'ancien jeton doit donc être mis à jour et **flushé avant** l'insertion du nouveau (test dédié).

## 3. Habilitations et périmètre

- **MUNICIPALITY_ADMIN seul** (INSTRUCTOR et EVENT_MANAGER refusés), d'une commune qui détient une autorisation **non révoquée** sur le laissez-passer, participation **ACTIVE** relue sous verrou, appartenance et rôle relus sous verrou. Le garde reçoit un mode « administrateur seul » ; refus uniforme 404 hors périmètre (commune, demande, laissez-passer d'un autre événement), 403 rôle absent, jamais un identifiant d'autorisation fourni par le navigateur.
- Effet **global** : le QR est commun à toutes les communes. L'écran le dit, nomme les autres communes concernées et exige une confirmation (comme les plaques).
- États : événement **suspendu** → autorisé (une compromission est plus grave quand les droits sont suspendus, et rien n'est modifié) ; événement **clos**, période **expirée** ou aucune autorisation non révoquée → voir arbitrage Q2.

## 4. Atomicité, concurrence, idempotence

- Ordre de verrous : événement (partagé) → participation → laissez-passer → ligne du jeton → autorisation (aucune zone, aucune plaque).
- **Révocation et remplacement simultanés** : sérialisés par le verrou du laissez-passer. Révocation d'abord : le remplacement relit l'autorisation révoquée et est refusé (si c'était la dernière autorisation non révoquée de la commune agissante). Remplacement d'abord : la révocation suit normalement. Deux remplacements simultanés : le second s'exécute après le premier et remplace **le nouveau jeton** (deux remplacements successifs, deux révisions) — sauf si c'est un double envoi du même formulaire (ci-dessous).
- **Collisions** : espace de 2^160 ; sous verrou, lecture du jeton candidat avant insertion, jusqu'à 5 tirages ; au-delà, erreur technique journalisée **sans le jeton** (jamais atteint en pratique). `UNIQUE(token)` reste le garde-fou final.
- **Idempotence (Q3)** : le formulaire porte un identifiant d'opération UUID généré à l'affichage ; il devient le `batch_id` de la révision. Sous verrou, si une révision `TOKEN_REPLACED` de CE laissez-passer porte déjà ce `batch_id`, l'opération est un **succès sans effet** (double clic, rejeu du navigateur). Aucune migration : `batch_id` existe. Un identifiant fourni par le client n'a d'effet que dans le périmètre de ce laissez-passer (jamais de collision ni de fuite entre laissez-passer).

## 5. Audit et historique

- Motif **codifié obligatoire** : `LOST`, `SHARED_OR_PUBLISHED`, `SUSPECTED_MISUSE`, `DEVICE_COMPROMISED`, `OTHER` (DM-119 §8, commentaire de 200 caractères obligatoire pour `OTHER`, **aucun jeton ni plaque** dans le commentaire : refus d'un commentaire qui contient un jeton, 32 caractères base32).
- Révision immuable `TOKEN_REPLACED` (`authorization_id` NULL, commune agissante, acteur, motif, `batch_id`). États **sans jeton** : numéro d'ordre du remplacement (1, 2, …), identifiants des lignes de jeton retiré et créé (UUID internes, non secrets), indicateur « rapproché » (§7). Les remplacements successifs se reconstituent par le numéro d'ordre, les identifiants de lignes de jeton, `permit_verification_token.created_at` / `retired_at` et le `batch_id`.
- Audit détaillé pour la commune agissante (identifiants, motif, numéro d'ordre, indicateur) ; **ligne neutre** « QR remplacé » dans l'historique de chaque autre commune titulaire, **révoquée ou non**, sans motif, auteur ni valeur.
- **Jamais un jeton** dans un journal, une exception, un audit, un état de révision ou une notification : le journal applicatif est déjà épuré (`SecretScrubbingLogger`) ; test d'architecture qui interdit la valeur du jeton dans ces structures ; erreurs sans valeur de jeton.

## 6. Notifications

- Type `QR_REPLACED` (11 caractères), **prioritaire** (catégorie révocation), non regroupé, niveau **laissez-passer** (aucune autorisation, comme les plaques), exact tant qu'au moins une autorisation non révoquée existe.
- Message : « le QR code de votre laissez-passer a été remplacé ; les anciens QR imprimés ou téléchargés (PDF) ne fonctionnent plus ; ouvrez votre lien personnel pour afficher le nouveau QR ; seul le contrôle du QR code fait foi ». **Ni jeton, ni lien de QR, ni motif, ni commentaire**, et il ne nomme pas la commune (QR commun). Aucun message ne présente un ancien QR comme valide.
- **Remplacements successifs (Q8)** : une notification déjà envoyée n'en couvre jamais une nouvelle (le QR que le bénéficiaire vient de télécharger est de nouveau invalide) ; seules les notifications **en attente** sont fusionnées (un message pour plusieurs remplacements en attente). Pannes SMTP temporaires : reprises existantes (24 h), `Message-ID` stable ; échec définitif : état visible de l'agent. Sans e-mail : « aucun e-mail disponible » et message à l'écran de l'agent : « transmettez le nouveau QR par un autre moyen ».
- Cohérence : page bénéficiaire et PDF régénérés montrent le nouveau QR ; un ancien PDF affiche un QR qui répond 410 « QR remplacé » ; le lien bénéficiaire compromis se révoque à part (DM-118), le message le rappelle.

## 7. Protection contre les abus (aucun blocage d'une opération légitime)

1. **Friction, jamais refus** : récapitulatif d'effet (« le QR de toutes les communes et tous les QR imprimés ou téléchargés cesseront de fonctionner ») et confirmation.
2. **Second remplacement dans l'heure** (tout agent, tout laissez-passer, mesuré sous verrou sur la dernière révision `TOKEN_REPLACED`) : confirmation renforcée — **saisie de la référence `LP-`**, motif codifié (Q4) — et le serveur refuse **seulement** l'absence de cette confirmation, jamais l'opération elle-même.
3. **Signalement** : au-delà de 3 remplacements en 24 h sur un laissez-passer, ou de 10 par agent en 24 h, une ligne structurée est écrite dans le journal (sans jeton ni donnée personnelle) et l'audit est marqué « inhabituel ». Aucune action n'est bloquée. Comptages par la table des révisions (aucune migration).
4. **Double envoi** : identifiant d'opération (§4). **CSRF** et rôle serveur comme partout.
5. **Anti-automatisation (Q6)** : voir arbitrage.

## 8. Migration

**Aucune migration attendue** : `permit_verification_token`, `retired_at`, `active_permit_id`, `permit_revision` (type VARCHAR(32), `batch_id`), l'outbox et l'audit suffisent. Les comptages de signalement s'appuient sur l'index existant `(permit_id, occurred_at, id)` ; le comptage par agent n'a pas d'index dédié (volume très faible) : un index ne serait proposé, séparément, que si une mesure l'exige. À revérifier avant tout code.

## 9. Plan de tests

- **Domaine** : retrait puis création, un seul jeton actif, ordre flush/unique, format du nouveau jeton, collision simulée (nouveau tirage), jamais le même jeton.
- **Service** : ancien QR → **410 sans donnée**, nouveau QR → droits corrects (zones, périodes, plaques identiques), numéro d'ordre, motifs obligatoires, `OTHER` + commentaire, commentaire contenant un jeton refusé, idempotence (même identifiant d'opération rejoué), second remplacement dans l'heure (référence exigée, jamais refusé avec référence), seuils de signalement, laissez-passer/référence/autorisations/liens inchangés, demande citoyenne inchangée.
- **Habilitations** : INSTRUCTOR, EVENT_MANAGER refusés ; participation non active ; autorisation révoquée ; identifiants forgés ; autre événement ; commune sans autorisation ; événement suspendu/clos ; période expirée.
- **Concurrence réelle MySQL (`ParallelPhp`)** : remplacement contre révocation (les deux ordres), deux remplacements (successifs, jamais deux jetons actifs), double envoi, deux communes, remplacement contre modification de plaques et de zones, lecture publique **pendant** l'opération (jamais deux QR valides ni aucun) ; aucun interblocage.
- **Notifications** : prioritaire, aucun jeton ni lien ni motif ni plaque, fusion des notifications en attente, jamais de suppression par un message déjà envoyé, panne SMTP temporaire puis reprise, échec définitif, absence d'e-mail.
- **Sécurité des jetons** : aucune valeur de jeton dans audit, révisions, outbox, journaux, exceptions, ni dans la réponse 410 ; entropie et format ; comparaison du jeton retiré en 410 (jamais 404) ; limitation de débit : un 410 n'est pas un échec.
- **Pages et PDF** : page publique (ancien 410, nouveau 200), page bénéficiaire et PDF réels (QR intégré = celui du nouvel URL, pixel pour pixel comme `BeneficiaryPdfTest`), historique de la commune (détail) et d'une autre (ligne neutre), architecture (seul le service remplace un jeton).
- `bin/qa full` obligatoire, `verify-docs.sh`, validation Doctrine.

## 10. Découpage proposé

05.5.5a domaine et service (garde administrateur, jeton, idempotence, concurrence) ; 05.5.5b notification et résilience ; 05.5.5c écran, confirmation renforcée, signalements, recette automatisée. Un seul lot livrable est possible.

## 11. Risques

- **Un QR imprimé est irrécupérable** : c'est voulu ; le message et l'écran le disent. Un PDF déjà téléchargé donne un QR mort (410) tant que le bénéficiaire n'a pas rouvert son lien.
- Compromission du **lien bénéficiaire** (qui donne accès au nouveau QR) : hors périmètre, revocation séparée (DM-118) ; à rappeler dans le message et l'écran.
- Remplacement abusif répété par un administrateur légitime ou compromis : visible (audit, ligne neutre, notification, signalement), non bloqué (décision validée) ; la contrepartie est un bénéficiaire qui reçoit un message par remplacement.
- Ordre d'écriture Doctrine face à l'index unique actif (§2) : test dédié.
- **Conservation des jetons retirés** : ils doivent rester lisibles pour répondre 410 tant que des QR imprimés peuvent circuler ; une purge trop précoce transformerait un 410 en 404 (question pour le LOT 05.6, Q9).
- **Exposition publique** (DM-115 : nom et plaques au scan) : un QR compromis expose ces données jusqu'au remplacement ; c'est ce que l'opération corrige.

## 12. Arbitrages ouverts (tous « À valider »)

### Q1 — Qui peut remplacer, et sous quelle condition d'autorisation ?
- **Option 1** : MUNICIPALITY_ADMIN d'une commune avec autorisation **non révoquée** et participation ACTIVE. Conséquence : cohérent avec les plaques ; une commune révoquée ne peut plus agir sur un objet commun.
- **Option 2** : idem, y compris une commune dont l'autorisation est révoquée. Conséquence : plus de souplesse en cas de compromission, mais une commune sans droits agit sur un QR qui n'est plus le sien.
- **Recommandation : option 1.** Les autres communes titulaires peuvent remplacer ; il reste toujours un administrateur habilité tant qu'une autorisation vit.

### Q2 — Événement suspendu, clos, période expirée, aucune autorisation vivante
- Suspendu : **autorisé** (recommandé). Période expirée mais événement actif : **autorisé** (les données publiques restent exposées au scan, DM-115). Aucune autorisation non révoquée : **refusé** (plus rien à protéger par une commune titulaire).
- Événement **clos** — **Option 1** : refusé (rien n'est plus exercé). **Option 2** : autorisé tant que la page publique expose l'identité (DM-115) : à vérifier au moment du développement (la lecture publique d'un événement clos doit être relue).
- **Recommandation : suspendu et expiré autorisés ; clos refusé uniquement si la lecture publique d'un laissez-passer d'un événement clos ne révèle déjà plus l'identité ni les plaques** (sinon autorisé jusqu'à la purge du LOT 05.6).

### Q3 — Idempotence
- **Option 1** : identifiant d'opération du formulaire = `batch_id` de la révision, relu sous verrou (aucune migration). **Option 2** : table de clés d'idempotence (migration). **Option 3** : CSRF à usage unique seulement.
- **Recommandation : option 1.**

### Q4 — Second remplacement dans l'heure : motif
- **Option 1** : motif réel du catalogue **et** saisie de la référence `LP-` ; la révision porte l'indicateur « rapproché » automatiquement. **Option 2** : motif dédié « répété » (DM-119 §4.5) en plus de la référence. Conséquence de l'option 2 : le vrai motif (perte, détournement…) est perdu.
- **Recommandation : option 1** (le motif véritable est conservé ; la répétition est détectée par le serveur, non déclarée). Écart consigné à DM-119 §4.5 si validé.

### Q5 — Seuils de signalement
- **Option 1** : 3 remplacements en 24 h par laissez-passer, 10 par agent en 24 h (DM-119), journal + audit « inhabituel », aucun blocage. **Option 2** : seuils plus bas. **Option 3** : en plus, courriel d'alerte aux administrateurs de la commune.
- **Recommandation : option 1** ; l'alerte par courriel est une évolution d'exploitation, non V1.

### Q6 — Anti-automatisation HTTP
- **Option 1** : aucune limite bloquante (les protections sont l'identifiant d'opération, le CSRF, le rôle, le signalement). **Option 2** : plafond technique très au-dessus d'un usage humain (par exemple 20 écritures par agent en 10 minutes, réponse 429 avec message clair, signalé). Conséquence : bloque un robot, jamais un humain ; reste un blocage.
- **Recommandation : option 1 en V1 avec le signalement du §7** (« aucun verrouillage arbitraire d'une opération légitime »), le plafond de l'option 2 restant désactivé et documenté comme réglage d'exploitation.

### Q7 — Message au bénéficiaire sans e-mail
- **Option 1** : « aucun e-mail disponible » + consigne à l'écran de l'agent de transmettre le nouveau QR autrement (comme la mise à disposition). **Option 2** : rien. **Recommandation : option 1.**

### Q8 — Notifications successives
- **Option 1** : fusion des seules notifications en attente ; un message déjà envoyé ne couvre jamais un nouveau remplacement. **Option 2** : un message par remplacement sans fusion. **Option 3** : fusion même après envoi. Conséquence de l'option 3 : le bénéficiaire garde un QR mort sans le savoir.
- **Recommandation : option 1.**

### Q9 — Conservation des jetons retirés
- Les jetons retirés servent à répondre 410. **Option 1** : conservés tant que le laissez-passer existe (puis traités par le LOT 05.6 avec la même durée que le laissez-passer). **Option 2** : purgés après une durée fixe (le QR répondrait alors 404). **Recommandation : option 1**, à inscrire dans le mécanisme de conservation du LOT 05.6.

### Q10 — Commentaire : jeton
- **Option 1** : refus d'un commentaire contenant un jeton (32 caractères base32 ou une URL `/v/…`). **Option 2** : aucun contrôle. **Recommandation : option 1.**

## 13. Ce qui est demandé à la validation

Valider ou amender Q1 à Q10 (recommandations : Q1 option 1 ; Q2 suspendu et expiré autorisés, clos selon la lecture publique ; Q3 option 1 ; Q4 option 1 ; Q5 option 1 ; Q6 option 1 ; Q7 option 1 ; Q8 option 1 ; Q9 option 1 ; Q10 option 1), puis autoriser le développement. Aucun code ni migration n'est écrit avant.

## 14. Décisions du commanditaire (10 novembre 2026)

| Arbitrage | Décision |
|---|---|
| Q1 | **Validé** : MUNICIPALITY_ADMIN d'une commune ACTIVE avec une autorisation non révoquée |
| Q2 | **Amendé** : autorisé si l'événement est **suspendu** ou si la période d'autorisation est **expirée** ; **refusé si l'événement est clos** définitivement. Confidentialité de la page publique après clôture : à vérifier séparément (**constat au §15**) |
| Q3 | **Validé sous condition** : idempotence **persistante** par identifiant d'opération ; deux soumissions identiques = un seul remplacement ; vérifier la persistance réelle du `batch_id` et la restitution du résultat initial |
| Q4 | **Validé** : second remplacement dans l'heure : motif réel obligatoire, référence `LP-` ressaisie, indicateur serveur « rapproché » |
| Q5 | **Validé** : signalement à partir de 3 remplacements par laissez-passer ou 10 par agent en 24 h ; aucun blocage |
| Q6 | **Amendé** : aucun quota métier bloquant en V1 ; protections techniques existantes maintenues (authentification, CSRF, idempotence, journalisation, protections anti-abus) |
| Q7 | **Amendé** : sans e-mail, remise sécurisée du QR courant par le parcours agent existant ; le lien bénéficiaire présente le nouveau QR et permet un PDF à jour ; aucun canal SMS |
| Q8 | **Validé** : fusion des seules notifications en attente ; un message envoyé ne couvre jamais une nouvelle opération |
| Q9 | **Validé** : anciens jetons conservés pour garantir HTTP 410, sous réserve de la politique de conservation du LOT 05.6 |
| Q10 | **Validé** : jeton refusé dans un commentaire, sans recopie dans un message d'erreur ni un journal |

## 15. Réalisation (LOT 05.5.5) : choix d'implémentation à relire

- **Aucune migration** : `permit_verification_token` (`retired_at`, `active_permit_id`), `permit_revision` (`batch_id`), l'audit et l'outbox suffisaient. Schéma Doctrine validé. Les deux `@phpstan-ignore` « LOT 05.5 » du jeton sont retirés.
- **Domaine** : `TokenReplacementRules` (pur : états, remplacement rapproché, seuils, référence, jeton dans un commentaire), `TokenReplacementReason`, `VerificationToken::retire()`, `Permit::addToken()`, révision `TOKEN_REPLACED` (`PermitRevision::token()`).
- **Service** `PermitTokenReplacementService`. Verrous : événement (partagé) → participation → laissez-passer → ligne du jeton → autorisation. Lectures verrouillantes (`lockActiveToken`, `lockedByBatch`, `lockedTokenReplacementTimes`) : un `SELECT` simple verrait un instantané périmé en `REPEATABLE READ` (numéro d'ordre, remplacement rapproché et idempotence en dépendent). L'ancien jeton est retiré **et écrit (`flush`) avant** l'insertion du nouveau (index unique du jeton actif) ; rien n'est visible avant le commit : jamais deux QR valides ni aucun (testé en lecture publique pendant l'opération).
- **Idempotence (Q3)** : l'identifiant d'opération du formulaire (UUID v7 généré à l'affichage) devient le `batch_id` de la révision. Sous verrou : même identifiant + même commune + type `TOKEN_REPLACED` = **succès sans effet** avec le résultat initial (numéro d'ordre, rapproché, inhabituel lus dans la révision) ; autre commune ou autre type de révision = refus ; autre laissez-passer = aucun effet. Après un incident (EntityManager fermé), la reprise est une **nouvelle requête** avec le même identifiant : un seul remplacement (testé avec un nouveau processus).
- **Garde** : mode « administrateur seul » (`adminOnly`), appliqué au contrôle initial **et** sous verrou ; 403 pour INSTRUCTOR, EVENT_MANAGER ; 404 uniforme hors périmètre.
- **Jeton** : 5 tirages au plus en cas de collision ; échec sans le jeton dans l'erreur. Jamais la valeur d'un jeton dans une révision (identifiants de lignes, numéro d'ordre, indicateurs), l'audit, l'outbox, le journal, une exception, un message ni une page. **Trouvaille corrigée pendant les tests** : un refus ré-affichait le commentaire saisi, donc un jeton collé dedans ; le champ est désormais vidé quand le commentaire contient un jeton.
- **Abus (Q4, Q5)** : « rapproché » = dernier remplacement du laissez-passer (tous agents) il y a moins d'une heure, mesuré sous verrou ; la référence ressaisie est comparée sans casse et jamais recopiée ; « inhabituel » = 3 remplacements par laissez-passer (compteur verrouillé) ou 10 par agent (lecture simple, indicative) en 24 h : audit marqué, ligne structurée `permit.token_replacement.unusual` (identifiants et compteurs, sans jeton), aucun blocage.
- **Audit** : une ligne détaillée (`PERMIT_TOKEN_REPLACED` : identifiants, numéro d'ordre, motif, indicateurs) pour la commune agissante ; une ligne **neutre** `PERMIT_TOKEN_REPLACED_BY_OTHER` (acteur « système ») par autre commune titulaire, révoquée ou non. Historique : détail pour la commune qui a agi (motif, commentaire, auteur, numéro d'ordre, « rapproché », « inhabituel »), ligne neutre « QR code remplacé » pour les autres.
- **Notification** `QR_REPLACED` : prioritaire, niveau laissez-passer (`authorization_id` NULL), exacte tant qu'une autorisation non révoquée existe, **aucun jeton, lien, motif, commentaire, plaque ni nom de commune**. Le dispatcher applique à ce type la seule **fusion des remplacements en attente** (l'ordonnancement lui évite tout retard) ; un message envoyé ne couvre jamais un remplacement plus récent. Pannes SMTP : reprises et abandon existants. Sans e-mail : enregistrée « sans destinataire » ; l'écran dit à l'agent de transmettre le lien (nouveau QR, PDF à jour) via « Liens et notification du bénéficiaire » (Q7).
- **Écran** « Remplacer le QR code » (`/communes/{id}/demandes/{id}/droits/qr`) et panneau sur l'écran des droits ; gabarit de la page du bénéficiaire et du PDF corrigés (le QR « ne change pas » n'était plus exact : il n'est remplacé qu'exceptionnellement et le PDF dit que son QR cesse alors de fonctionner).
- **Constat Q2 (confidentialité après clôture)** : **résolu** (décision définitive, §16). Le constat initial (la lecture publique d'un jeton actif ne regardait pas l'état de l'événement) est corrigé.
- **Q9** : aucun jeton retiré n'est supprimé (architecture testée) ; la durée de conservation relève du LOT 05.6 (**bloquant avant la production** avec les plaques).
- **Tests** : `TokenReplacementRulesTest` (13), `PermitTokenReplacementServiceTest` (23), `TokenReplacementConcurrencyTest` (7, processus réels), `TokenReplacementNotificationTest` (8), `PermitQrScreenTest` (12 : écran, 410, nouveau QR, lien bénéficiaire, PDF réel pixel pour pixel, états, rôles, CSRF), `TokenReplacementArchitectureTest` (4).
- **Recette transversale (05.5.6)** : scan réel d'un ancien QR imprimé (410) et du nouveau ; réception réelle du message `QR_REPLACED` ; PDF téléchargé avant le remplacement rouvert dans un lecteur réel ; parcours sans e-mail (remise par l'agent) ; parcours multicommunal (une commune remplace, l'autre voit la ligne neutre et reçoit le nouveau QR par le lien) ; décision sur l'exposition après clôture.

## 16. Correctif de confidentialité après clôture (décision définitive du 10 novembre 2026)

- **Règle** : événement **définitivement clos** → la vérification publique n'affiche qu'un statut neutre « Événement terminé » (HTTP 200) : ni nom, ni prénom, ni plaques, ni zones, ni référence, ni nom d'événement, ni communes. Amendement : [DM-115](decisions/metier/DM-115-scan-public-integral-sans-habilitation.md).
- **Mise en œuvre côté serveur** : `PublicVerificationReader` renvoie `PublicVerificationOutcome::EventClosed` (sans aucune donnée) dès que l'événement est clos, **avant toute lecture des droits** ; le contrôleur affiche `verification_closed.html.twig`. Aucune autre représentation publique n'existe (aucune route JSON : vérifié) ; le test fonctionnel le contrôle pour HTML, barre finale, `Accept: application/json`, XML, PDF, AJAX, Turbo, langue, suffixe `.json`, chemins voisins, paramètres de requête.
- **Conservé** : jeton retiré = 410 sans donnée (même après clôture, sans annoncer l'état de l'événement) ; jeton inconnu ou mal formé = 404 neutre ; événement **suspendu** = « Droits suspendus », aucun droit utilisable, aucun « Valide actuellement ». Une réponse « événement terminé » n'est ni une redirection ni un échec compté par la limitation de débit (30 consultations successives testées).
- **Non modifié** : calcul interne des droits (page du bénéficiaire, PDF), règles de conservation administrative (**LOT 05.6**).
- **Tests** : `PublicVerificationClosedEventTest` (fonctionnel, 17 cas) ; `PublicVerificationReaderTest` (issue neutre à tout instant, 410 et 404 conservés, suspension) ; `PublicVerificationScenariosTest` et `PermitQrScreenTest` réécrits selon la nouvelle règle.
- **Tests modifiés (à relire)** : trois tests figeaient l'ancien comportement (page complète après clôture) et sont remplacés par leur contraire : le scénario de clôture de `PublicVerificationScenariosTest`, la ligne « événement clôturé » du jeu de données de `PublicVerificationReaderTest` (et son test de coupure anticipée, déplacé sur le calcul interne des droits, qui reste exact), la ligne « événement terminé » de `PublicVerificationPageTest`, et le constat de `PermitQrScreenTest`. Aucune règle de suspension, de 410 ou de 404 n'est assouplie.

## 17. Contrôle de stabilité et incident intermittent

- **Incident observé** : au premier passage complet de `bin/qa full` du LOT 05.5.5, `DirectIssuanceConcurrencyTest::testAReplayWhileTheFirstIssuanceHoldsItsLocksWaitsThenReturnsTheExistingResult` a échoué (`OperationConflict` : « Un autre traitement est en cours »). Le Mac s'est mis en veille pendant ce passage (l'horloge hôte a sauté de 32 minutes alors que le conteneur n'en comptait que 2). Le test passe seul et au second passage complet. **Cause non prouvée** : hypothèse d'une expiration d'un délai d'attente de verrou pendant la veille ; aucune mesure des compteurs de verrous MySQL n'a été faite. Aucun test n'a été modifié ni assoupli.
- **Rejeu demandé (10 novembre 2026, après le correctif de confidentialité)** : 5 passages successifs de chaque classe, sans veille de la machine :

| Passage | `TokenReplacementConcurrencyTest` | `DirectIssuanceConcurrencyTest` |
|---|---|---|
| 1 | OK (7 tests, 69 assertions, 20 s) | OK (3 tests, 29 assertions, 9 s) |
| 2 | OK (idem) | OK (idem) |
| 3 | OK (idem) | OK (idem) |
| 4 | OK (idem) | OK (idem) |
| 5 | OK (idem) | OK (idem) |

  **Aucun échec sur 10 passages.** L'incident du premier passage complet reste le seul constaté : intermittent, non reproduit, cause non prouvée (hypothèse : veille de la machine). Ces tests n'ont pas été assouplis. Si l'incident se reproduit hors veille, il faudra mesurer les attentes de verrou MySQL (`innodb_lock_wait_timeout`, `performance_schema.data_lock_waits`) avant de conclure.
