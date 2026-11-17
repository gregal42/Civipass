# Écarts techniques et points de recette connus

Registre de suivi tenu à jour à la clôture de chaque lot. Un écart listé ici **ne bloque pas** le développement local ; ceux marqués « mise en service » doivent être levés **avant toute mise en service** (recette terrain, charge, production). Dernière mise à jour : 17 novembre 2026 (clôture du développement local du LOT 07.3).

## Périmètre actuel

Civipass reste en **développement exclusivement local** (décision du 17 novembre 2026). **Aucun VPS de recette n'est créé**, aucun déploiement, aucune activation publique : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`. La recette HTTPS sur de vrais smartphones et les tests de charge sur infrastructure représentative sont **reportés à la phase finale de préparation de la mise en service** ([runbooks/recette-verification-publique.md](runbooks/recette-verification-publique.md), préparé et non exécuté).

## Tests intermittents (fiabilisation prévue, aucune refonte engagée)

| Test | Constat | Fiabilisation prévue |
|---|---|---|
| `tests/Integration/Permit/RevocationConcurrencyTest` (`testASecondRevocationWaitsForTheFirstThenSeesItAndWritesNothing`) | A échoué une fois sur 3 358 sous la charge du passage complet (résultat de la seconde révocation absent) ; réussi 3 fois sur 3 isolément et au passage complet suivant. S'appuie sur des processus PHP parallèles et un `usleep(600_000)` | Remplacer les temporisations par une **synchronisation déterministe** : le processus fils signale « verrou pris » (fichier ou tube) ; le test attend que le second soit réellement bloqué en interrogeant le moteur (`performance_schema.data_lock_waits` ou `information_schema.innodb_trx`) puis libère le premier |
| `tests/Functional/Retention/RestoreReplayTest` | Attente artificielle (temporisation) | Même principe : signal explicite du processus concurrent et attente d'une condition observable, jamais d'une durée |

Règle : ne pas désactiver ni assouplir ces tests ; les relancer isolément pour diagnostiquer, puis corriger la synchronisation dans un lot dédié.

## Contrôle QR (LOT 07.3)

| Point | Statut |
|---|---|
| Recette sur iPhone et Android (VP-01 à VP-54), dont cache avant/arrière de Safari (VP-14) | **Mise en service** : non exécutée |
| Test de charge et valeur définitive de `VERIFICATION_ACCESS_LIMIT` (1 500 / 10 min provisoire, [DT-055](decisions/technique/DT-055-limitation-de-debit-du-controle-public-echecs-et-garde-fou-d-acces.md)) | **Mise en service** : non exécuté |
| Nginx de production appliqué et contrôlé sur un serveur réel, vraie IP, journaux masqués | **Mise en service** : préparé, non appliqué |
| **Historique du navigateur** : l'URL scannée (qui porte le jeton) est conservée dans l'historique du téléphone du contrôleur | Risque **accepté et documenté** (ci-dessous) ; non bloquant |
| Copie ou capture d'écran du QR : le porteur affiche le nom et les plaques | Risque résiduel de DM-115 §7, atténué par le rappel de rapprochement (DM-125) |
| Plafond d'accès saturable par une personne partageant l'adresse IP | Résidu connu de DT-055 : 429 explicite, borné à 10 minutes ; procédure de terrain (DM-128) |

### Historique du navigateur (décision du 17 novembre 2026)

Le jeton figure dans l'URL `/v/{jeton}` ; le navigateur d'un contrôleur la **conserve dans son historique** et peut la proposer en saisie semi-automatique. Les en-têtes `no-store` et `no-referrer` n'y changent rien : l'historique est local à l'appareil. Le jeton donne accès, en lecture seule, aux seules données de DM-115 §2 ; il est remplaçable par la commune (DM-113) et les droits sont recalculés à chaque consultation, donc une URL conservée n'ouvre jamais plus que l'état courant.

- **La navigation privée n'est pas une condition du contrôle.**
- Elle est **conseillée pour les appareils partagés** (poste de contrôle partagé, téléphone prêté) : à inscrire dans la consigne de terrain de la commune.
- Un contrôleur qui quitte son poste ou change d'appareil peut effacer son historique ; la commune peut remplacer le QR en cas de doute (DM-113 §4).

### Absence de collecte du jeton par un tiers (vérifié le 17 novembre 2026)

- **Aucun script, aucune ressource tierce** dans les pages de contrôle : CSP `default-src 'none'; style-src 'self'; img-src 'self' data:`, aucun `<script>` ni lien externe dans les gabarits (tests `PublicVerificationPageTest`).
- **Aucun outil d'analyse ni de supervision d'erreurs** dans `composer.json`, la configuration ou les gabarits (pas de Matomo, Google Analytics, Sentry, etc.) ; aucun bundle de journalisation tiers ; le profileur Symfony n'est pas installé (seul MakerBundle l'est, en développement).
- **Journaux applicatifs** : `SecretScrubbingLogger` masque `/v/…` ; les messages du contrôle sont fixes et sans adresse ni jeton ; aucun journal ni audit des scans en V1 (DM-127). Tests de non-divulgation existants.
- **Journaux nginx** : masquage du segment qui suit `/v/` (préparé et testé dans `ops/nginx/`, **à contrôler sur le serveur réel** : mise en service).
- Cette vérification est à **rejouer** si une dépendance, un script ou un outil de supervision est ajouté un jour.

## Écarts du LOT 07.2 (conservés)

Recette visuelle de Sonata et de l'écran Équipe ; envoi d'invitations avec un SMTP réel ; validation ergonomique avec des agents municipaux ; motif de révocation commerciale non persisté ; attente artificielle dans `RestoreReplayTest` (voir ci-dessus).


## Contrôle hors connexion (LOT 09, développement local au 17 novembre 2026)

| Limite | Détail | Traitement |
|---|---|---|
| **Recette sur smartphones réels non faite** | Service Worker, caméra, installation (iPhone : application ajoutée à l'écran d'accueil), purge de stockage iOS (~7 j hors installation), comportement de `performance.now()` en veille, sauvegardes de l'appareil : **seulement simulés** dans Chrome de développement | incrément **09.7**, avant le pilote ; HTTPS et autorisation d'infrastructure requis |
| **Risque R-OFF-01** | une révocation, suspension, remplacement de QR ou clôture anticipée postérieurs à la dernière synchronisation sont **invisibles hors ligne** jusqu'à la synchronisation suivante (≤ 12 h au pilote) | borné par la durée de confiance, mode `STRICT`, consigne d'escalade ; **acceptation écrite à obtenir** (annexe de l'AIPD) |
| **Données personnelles sur l'appareil** | nom, prénom, plaques, zones, fenêtres de la commune activatrice ; extractibles depuis un téléphone déverrouillé ; suppression à l'**ouverture** de l'application seulement ; **aucun chiffrement applicatif annoncé** | minimisation, durée, désactivation à la reconnexion ; AIPD et validation juridique **avant toute donnée réelle** |
| **Horloge** | une avance volontaire application fermée n'est pas détectable ; reculs et décalage à la synchronisation le sont | « indéterminé » ; horizon du jeu ≤ 12 h |
| **Même origine que le reste de Civipass** | le stockage local est par origine : une faille de script ailleurs exposerait le jeu | cible : sous-domaine dédié (`CONTROL_PWA_BASE_URL`), à installer avec l'infrastructure |
| **jsQR non maintenu** | version 1.4.0 (avril 2021), stable ; performance à mesurer sur matériel modeste | repli conditionnel zxing-wasm (décision séparée) ; revue annuelle |
| **Pas d'alerte « resynchronisation demandée »** | non livrée (périmètre à deux tables) | désactivation, durée raccourcie, alerte radio |
| **Calcul du jeu non mis en cache** | 4,8 s pour 10 000 laissez-passer (0,5 ms/laissez-passer) ; l'« inchangé » économise le transfert, pas le calcul | cache court par commune/événement si le volume réel approche 10 000 ; mesures dans le cadrage §32 |
| **Aucun journal de scan** | pas de statistiques de contrôle (DM-127) | volontaire |
| **Tests e2e sur l'hôte** | Node 20 du conteneur n'a pas de client WebSocket natif ; `bin/qa e2e` exige Node ≥ 22, Chrome et Docker sur l'hôte | acceptable en local |

### Dette technique du contrôle hors connexion — entrées explicites (17 novembre 2026)

| # | Dette | Constat mesuré ou connu | Décision |
|---|---|---|---|
| DT-C1 | **Coût du recalcul des jeux de synchronisation** | construction linéaire, ~0,5 ms par laissez-passer : 0,5 s à 1 000, **4,8 s à 10 000** (mémoire +23 Mo) ; la réponse « inchangé » ne diminue pas ce coût | **aucune optimisation sans mesure en conditions réelles** ; seuil d'attention : > 2 000 laissez-passer ou synchronisations simultanées nombreuses |
| DT-C2 | **Absence de cache serveur du jeu** | chaque synchronisation reconstruit le jeu ; 10 appareils sur 10 000 laissez-passer ≈ 50 s de calcul par quart d'heure | première optimisation envisageable : cache court par commune et événement, invalidé au changement de droits ; à ne lancer qu'après mesure et justification |
| DT-C3 | **Limites de purge sur iOS** | pas d'exécution en arrière-plan ; suppression **à l'ouverture** de l'application ; stockage purgé par le système après ~7 jours sans usage hors application installée ; sauvegardes de l'appareil non vérifiées | à vérifier en 09.7 sur iPhone réel (suppression, veille, sauvegarde) ; consigne d'installation et de verrouillage |
| DT-C4 | **Limites de détection des modifications d'horloge** | recul et décalage à la synchronisation détectés ; **avance volontaire application fermée non détectable** ; `performance.now()` en veille non caractérisé | « indéterminé » en cas d'incohérence ; horizon du jeu ≤ 12 h ; aucune promesse de résistance absolue ; mesure en 09.7 |
| DT-C5 | **Maintenance future de jsQR** | 1.4.0 (avril 2021), non maintenu ; Apache-2.0 ; copie figée avec empreinte vérifiée par un test | revue annuelle et veille de sécurité ; repli conditionnel zxing-wasm **sur décision séparée** ; décodage à mesurer sur matériel modeste (09.7) |
| DT-C6 | **Appareils perdus restant hors connexion** | la désactivation n'agit qu'à la reconnexion ; les données identifiantes restent jusqu'à la prochaine ouverture ; extraction possible d'un téléphone déverrouillé | minimisation, durée de confiance, runbook de perte/vol, AIPD ; **risque inscrit, non supprimé** |

### Prérequis du LOT 09.7 et de tout usage de données réelles
Recette Android et iPhone **réels** ; environnement **HTTPS** adapté (autorisation d'infrastructure distincte, sous-domaine dédié visé) ; **AIPD validée** ; **acceptation écrite du risque R-OFF-01** ; validation des **consignes de terrain** et des **messages de contrôle** ; vérification sur appareils réels de la **suppression des données** et du **comportement après veille**.

### Configuration (rappel)
`OFFLINE_CONTROL_ENABLED=0` et `PUBLIC_VERIFICATION_ENABLED=0` dans `.env` (défauts du dépôt) ; `.env.local` et `.env.*.local` sont **ignorés par Git** (`.gitignore`) : aucun paramètre local de développement (par exemple `PUBLIC_VERIFICATION_ENABLED=1` du poste de développement) ne doit être repris à la mise en service, dont la configuration est établie séparément et autorisée explicitement.
