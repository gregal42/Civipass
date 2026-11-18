# Registre de sécurité

Constats de sécurité de Civipass, avec leur gravité, leur correction et leurs preuves. Une entrée n'est « Corrigée » que si le correctif est testé et mesuré. Liens : [security.md](security.md), [authentication.md](authentication.md).

| ID | Date | Constat | Gravité | Statut |
|---|---|---|---|---|
| [SEC-001](#sec-001) | 18 novembre 2026 | Énumération de comptes par le temps de réponse de la connexion | Moyenne | **Corrigée** (commit local, à pousser) |

## SEC-001

**Constat (LOT 10, revue O-6).** À mot de passe erroné, la connexion d'un compte **existant** coûtait une vérification de hachage (≈ 350 ms avec l'algorithme de production) alors que l'échec d'un identifiant **inconnu** était immédiat (≈ 7 ms), pour un message identique (« Identifiants invalides. »). Un tiers pouvait donc tester à distance si une adresse possède un compte Civipass. Aucun accès ni donnée métier n'était exposé : seule l'existence du compte. Le limiteur de connexion (5 essais par identifiant et IP, 50 par IP, sur 15 minutes) ralentit un balayage sans l'empêcher.

**Décision du commanditaire (18 novembre 2026).** Anomalie à corriger avant tout pilote ; solution retenue : vérification de mot de passe factice quand l'identifiant ne correspond à aucun compte ; sans changement des messages visibles, des règles d'authentification ni du limiteur ; sans nouveau levier de déni de service ; sans chercher une égalité parfaite des temps.

**Correctif.** `UnknownAccountTimingListener` (`src/Identity/Infrastructure/Security/`), écouteur de `LoginFailureEvent` : quand l'échec vient d'un `UserNotFoundException` (directement ou en cause d'un `BadCredentialsException`), il exécute **un** hachage du mot de passe saisi avec la fabrique de hacheurs de l'application, donc **le même algorithme et le même coût** que les comptes réels. Garanties :
- **Messages, statuts et règles inchangés** : l'écouteur n'agit qu'après l'échec et ne modifie ni la réponse ni l'exception ; toute erreur du hachage est absorbée ; aucun mot de passe n'est journalisé ni conservé.
- **Limiteur intact** : il refuse avant toute recherche de compte ; une tentative limitée (`TooManyLoginAttemptsAuthenticationException`) n'est pas un échec « inconnu » et n'effectue **aucun** hachage.
- **Pas de levier de déni de service** : un identifiant inconnu coûte désormais exactement ce que coûte un compte existant avec un mauvais mot de passe, dans la même enveloppe de limitation ; un mot de passe vide ou de plus de 4 096 octets n'est pas haché (un vrai compte échouerait lui aussi sans hachage).
- **Comptes désactivés** : inchangé (refusés après la vérification du mot de passe, message explicite seulement avec le bon mot de passe).

**Mesures sur la recette (algorithme et coût de production ; réponses à une connexion refusée, mesures entrelacées, comptes existants alternés, identifiants inconnus tous différents).**

| Mesure | N | Compte existant (min / médiane / max ms) | Identifiant inconnu (min / médiane / max ms) | Écart des médianes |
|---|---|---|---|---|
| **Avant** | 8 + 8 | 346 / 360 / 380 | 6 / 7 / 8 | **+353 ms (× 51)**, aucun recouvrement |
| **Après**, série 1 | 8 + 8 | 341 / 361 / 369 | 345 / 358 / 375 | +3 ms (× 1,0), recouvrement complet |
| **Après**, série 2 | 6 + 6 | 349 / 352 / 364 | 348 / 353 / 365 | −1 ms (× 1,0), recouvrement complet |

Messages relevés pendant les trois séries : un seul (« Identifiants invalides »). Protocole : `var/lot10/campaign/timing.mjs` (script local non versionné, N configurable ; au plus un essai par compte et par mesure pour rester sous le limiteur). Conditions : serveur local mono-processus, réseau local ; une égalité parfaite n'est pas recherchée : l'écart systématique (≈ 350 ms) a disparu, les distributions se recouvrent.

**Tests.**
- `UnknownAccountTimingListenerTest` (8) : identifiant inconnu → un hachage du mot de passe saisi ; exception non enveloppée aussi ; compte existant → aucun hachage supplémentaire ; tentative limitée → aucun ; mot de passe vide ou de plus de 4 096 → aucun ; lecture depuis la requête sans passeport ; hacheur défaillant sans effet sur la réponse ; réponse et exception jamais modifiées.
- `LoginTimingTest` (5), avec un décorateur de test de la **vraie** fabrique de hacheurs : écouteur enregistré sur l'événement réel ; inconnu → `hash`, compte existant → `verify` seul ; messages, statuts et redirections identiques ; connexion valide inchangée (une vérification) ; après cinq échecs, la sixième tentative est limitée **sans aucun hachage de plus**.
- Non-régression : `AuthenticationTest` (limiteur, désactivé, CSRF, normalisation) et la suite complète.

**Résiduel.** (1) Les temps ne sont pas strictement égaux (quelques millisecondes de bruit réseau et de base de données) : non exploitable dans les conditions mesurées. (2) Un compte dont le hachage stocké a un coût plus ancien peut répondre un peu plus vite ou plus lentement qu'un hachage au coût courant (rehachage à la connexion réussie) : non mesuré, non exploitable. (3) La réinitialisation de mot de passe envoie l'e-mail après la réponse ; écart de la base de quelques millisecondes (médiane 11 ms contre 9 ms) : documenté dans [authentication.md](authentication.md). À reprendre en recette sur le serveur cible (09.7) avec la latence réseau réelle.
