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
