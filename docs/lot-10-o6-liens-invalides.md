# LOT 10 / O-6 — Revue de sécurité des réponses aux liens invalides

Date : 18 novembre 2026. Question posée : les réponses aux liens invalides permettent-elles de découvrir l'existence de comptes, de demandes ou de jetons ? Test qui fige la matrice : `tests/Functional/InvalidLinkResponsesTest.php`.

## 1. Statuts HTTP et messages retenus

| Flux | Jeton mal formé | Inconnu | Expiré | Déjà utilisé | Remplacé / révoqué / terminé | Trop d'essais |
|---|---|---|---|---|---|---|
| Invitation `/invitation/{jeton}` (43 car.) | 404 générique du framework | **404** « Lien introuvable » | 410 « Ce lien a expiré » | 410 « Ce lien a déjà été utilisé » | 410 « remplacé » ou « n'est plus en attente » | 429 |
| Réinitialisation `/reset-password/{jeton}` (43 car.) | 404 générique | **200** « Lien invalide ou expiré » | même page | même page | même page | 429 |
| Lien bénéficiaire `/laissez-passer/{secret}` | 404 | **404** « Ce lien n'est pas valide… » | même page | n/a | même page (lien retiré, événement clos) | 429 |
| Vérification publique `/v/{jeton}` (32 car.) | 404 | **404** « QR code non reconnu » (identique aussi quand l'interrupteur est à 0) | n/a | n/a | 410 « QR remplacé » ; 200 « Événement terminé » (décisions DM-115, DM-126) | 429 |
| Activation du contrôle `POST /controle/api/activation` | 400 | **403** « Code d'activation invalide, expiré ou déjà utilisé. » | même réponse | même réponse | même réponse | 429 |

Toutes ces réponses : `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, aucune session ni aucun cookie, le jeton n'est jamais renvoyé dans le corps.

## 2. Analyse : peut-on découvrir l'existence d'un jeton, d'un compte ou d'une demande ?

- **Jetons** : deux jetons inconnus différents reçoivent exactement la même réponse (testé à l'octet près pour les quatre flux). Les seuls états distincts (utilisé, expiré, remplacé, terminé : invitation, QR, lien bénéficiaire pour les clos) ne sont visibles que par une personne qui **possède** un jeton ayant réellement existé : les jetons font 160 à 256 bits et les essais sont limités (429), donc **aucun sondage ne peut en faire découvrir un**. Ces distinctions servent l'utilisateur légitime (savoir quoi faire) et ne divulguent ni événement, ni commune, ni personne.
- **Demandes et laissez-passer** : aucune page d'erreur ne contient de référence, de nom ou de donnée de dossier ; la page de vérification d'un QR inconnu est identique pour un jeton mal formé, inconnu ou un contrôle non activé.
- **Comptes — réinitialisation de mot de passe** : adresse inconnue, invalide, désactivée ou limitée : même redirection, même page (testé), e-mail envoyé **après** la réponse (`kernel.terminate`). Écart de temps résiduel mesuré : médiane 11 ms (compte existant) contre 9 ms (inconnu), non exploitable (documenté dans `docs/authentication.md`).
- **Comptes — connexion** : message identique (« Identifiants invalides. »), compte désactivé signalé **seulement** après un mot de passe correct. **Mais voir § 3 : le temps de réponse est, lui, très différent.**

## 3. Constat de sécurité nouveau : énumération de comptes par le temps de réponse de la connexion

| Mesure (recette locale, mot de passe erroné, 4 essais) | min / médiane / max |
|---|---|
| Compte **existant** | 339 / 345 / 366 ms |
| Compte **inconnu** | 6 / 6 / 7 ms |

Cause : pour un compte existant, l'application vérifie le mot de passe (hachage coûteux, ≈ 340 ms) ; pour un compte inconnu, elle échoue immédiatement, sans calcul. Le message est identique, mais **le temps révèle si l'adresse correspond à un compte**. L'écart (facteur ≈ 50) se mesure facilement à distance ; le limiteur de connexion (5 essais par adresse et par IP sur 15 minutes, 50 par IP) ralentit sans empêcher un balayage lent. `docs/authentication.md` annonce « pas d'énumération de comptes » sans mentionner ce temps de réponse : **non documenté, non couvert par un test**.

Gravité : **moyenne** (divulgation de l'existence de comptes agents ; pas d'accès, pas de donnée métier ; l'adresse est le seul élément révélé). Impact pour le pilote : un tiers peut tester si une adresse professionnelle d'une mairie possède un compte Civipass.

**Correction retenue et implémentée le 18 novembre 2026 (voir [registre de sécurité, SEC-001](registre-securite.md))** — proposition d'origine : à l'échec d'une connexion pour un identifiant inconnu (`UserNotFoundException`), exécuter un hachage factice du mot de passe saisi avec le même algorithme et le même coût que les vrais comptes, avant de répondre, pour égaliser le temps. Écouteur sur `LoginFailureEvent` : aucun contournement d'authentification possible (le compte fictif n'existe pas), aucun changement de message ni de statut, aucune donnée en base. Test : un hacheur espion vérifie qu'un échec « inconnu » consomme un hachage ; un test de ratio des temps (tolérance large) l'accompagne. Effort : S (≈ ½ jour). Option complémentaire : documenter l'écart résiduel de ≈ quelques ms dans `docs/authentication.md`.

## 4. Décisions proposées

1. **Matrice du § 1 retenue telle quelle**, figée par test : aucune modification de comportement pour les liens invalides (l'uniformité souhaitée existe déjà pour tout visiteur).
2. **Corriger l'énumération par le temps de la connexion** (§ 3) : à valider avant implémentation (sécurité).
3. Variante écartée sauf avis contraire : uniformiser tous les états d'une invitation sur un seul 404, ce qui retirerait au porteur légitime l'explication « expiré / déjà utilisé » sans protection supplémentaire (les jetons ne se devinent pas).
