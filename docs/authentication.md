# Civipass — Authentification (LOT 01.2)

Périmètre : connexion par email et mot de passe, « Se souvenir de moi », mot de passe oublié, déconnexion, sessions, comptes désactivés, limitation des tentatives, `lastLoginAt`, initialisation du premier superadmin. Les citoyens et les contrôleurs externes n'ont pas de compte (CLAUDE.md, règle 6) et ne passent pas par ce firewall.

## Parcours

| URL | Méthode | Comportement |
|---|---|---|
| `/login` | GET | Formulaire (Twig, provisoire). `Cache-Control: no-store`, `noindex`. Un utilisateur déjà connecté est redirigé : superadmin → `/admin/dashboard`, autre compte → `/`. |
| `/login` | POST | Authentification (`form_login`). Champs `email`, `password`, `_csrf_token`. |
| `/logout` | POST | Déconnexion avec jeton CSRF `logout`. Toute autre méthode : 405. |
| `/forgot-password`, `/forgot-password/check-email` | GET, POST | Demande de réinitialisation, puis page de confirmation **identique** quel que soit le compte. |
| `/reset-password/{token}` | GET | Contrôle le jeton, le range en session et redirige vers `/reset-password` (le jeton quitte l'URL). |
| `/reset-password`, `/reset-password/done` | GET, POST / GET | Formulaire du nouveau mot de passe, puis confirmation. |
| `/admin/**` | tous | `ROLE_SUPER_ADMIN` **et authentification complète**. Anonyme ou simplement « mémorisé » : redirection vers `/login`. Compte connecté sans le rôle : 403. |

Après connexion, `default_target_path` renvoie vers `/login`, qui redirige selon le rôle ; `use_referer` est désactivé (jamais de redirection vers une URL arbitraire).

## Garde-fous

- **Email normalisé** à la connexion (casse, espaces, Unicode NFC) par `UserProvider`, avant la recherche binaire.
- **Pas d'énumération de comptes** : « email inconnu » et « mauvais mot de passe » donnent le même message (« Identifiants invalides. »).
- **Comptes désactivés** : `UserChecker::checkPostAuth`, donc refusés **après** la vérification du mot de passe (message explicite seulement pour qui connaît le bon mot de passe). Une session ouverte est invalidée dès la désactivation (`EquatableInterface`, LOT 01.1).
- **CSRF** : jetons de **session** pour `authenticate` et `logout` (`csrf.yaml` : seul `submit` reste stateless). Fonctionne sans JavaScript.
- **Limitation** (`LoginRateLimiter`, seuils dans `framework.yaml`) : 5 tentatives / 15 min par couple identifiant + IP ; 50 tentatives / 15 min par IP (seuil large pour les réseaux partagés). Une connexion réussie remet à zéro le compteur identifiant + IP, pas le plafond par IP.
- **Session** : cookie `HttpOnly`, `SameSite=Lax`, `Secure` automatique en HTTPS ; identifiant de session renouvelé à la connexion (anti-fixation).
- **`lastLoginAt`** : mis à jour à chaque connexion réussie par `LoginRecorder`, sans toucher `updatedAt`. Ce n'est pas un journal des connexions.
- **Langue** : français par défaut (messages de sécurité, validateur, Sonata : traductions `fr` présentes).

## Mot de passe oublié

Parcours en formulaires HTML simples, jetons CSRF de session : **fonctionne sans JavaScript** (testé avec un client qui n'exécute aucun script).

| Exigence | Réalisation |
|---|---|
| Jeton aléatoire | 32 octets `random_bytes`, encodés en base64url (43 caractères). |
| Stockage | Seule l'empreinte **SHA-256** est en base (`identity_password_reset_request.token_hash`, unique). Le jeton en clair n'existe que dans l'email, puis dans la session de l'utilisateur. |
| Expiration | 30 minutes (`expires_at > maintenant`, comparaison stricte). |
| Usage unique, consommation atomique | `UPDATE … SET used_at = :now WHERE id = :id AND used_at IS NULL AND expires_at > :now` : un seul appelant obtient « 1 ligne modifiée », même en concurrence (testé avec deux connexions et le même code de dépôt). |
| Atomicité du changement | **Une seule transaction** : consommation du jeton, invalidation des autres jetons, changement du mot de passe, audit `USER_PASSWORD_RESET`. Un échec (audit indisponible, par exemple) annule tout : mot de passe inchangé, jeton non consommé. |
| Politique de mot de passe | La même que partout (12 caractères, 4096 octets maximum) ; contrôlée **avant** la transaction : un mot de passe refusé ne consomme pas le jeton. |
| Pas d'énumération | Adresse inconnue, invalide, compte désactivé ou demande limitée : même redirection, même page, aucun email, aucune exception. Le jeton invalide, expiré ou déjà utilisé donne toujours la même page « Lien invalide ou expiré ». |
| Temps de réponse | L'email est envoyé **après** la réponse (`kernel.terminate`, avec PHP-FPM). Résiduel : la création de la ligne en base pour un compte existant prend quelques millisecondes de plus qu'un refus immédiat ; elle n'est pas masquée. |
| Protection contre l'abus | Par adresse : 3 demandes / heure ; par IP : 10 demandes / heure ; par IP : 10 validations ou soumissions de jeton / 15 min (au-delà : page 429). Au-delà des limites de demande, l'envoi est abandonné en silence avec la réponse habituelle. Clés de limiteurs = empreintes HMAC (aucune adresse ni IP en clair dans le cache). |
| Liens | Construits à partir de **`DEFAULT_URI`**, jamais de l'en-tête `Host` ni de `X-Forwarded-Host` (testé avec un `Host` falsifié). `DEFAULT_URI` doit être défini en production. |
| Contenu des emails | Jamais de mot de passe. Un email « votre mot de passe a été modifié » est envoyé après chaque réinitialisation réussie. |
| Journalisation | Réinitialisations réussies : `USER_PASSWORD_RESET` (acteur = le compte, origine `USER`), sans jeton ni mot de passe. Échecs d'envoi : journal applicatif sans destinataire ni jeton. |
| Pages | `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`. |

Envoi : `PasswordResetNotifier` (abstraction) → `QueuedPasswordResetNotifier` → `DeferredMailQueue` (flush sur `kernel.terminate` et `console.terminate`). **V1 : pas de relance** en cas d'échec d'envoi. Évolution prévue (LOT 07) : remplacer la file par un message Messenger asynchrone avec outbox, sans toucher aux cas d'usage.

Configuration : `MAILER_DSN` (défaut `null://null` dans `.env` : n'envoie rien ; développement : MailCatcher dans `.env.local`, `smtp://saas_civipass_mailcatcher:1025`, interface sur `mail-civipass.cws.gregory.localhost`), expéditeur `MAILER_FROM` (repli non routable `no-reply@civipass.invalid`, **à définir en production**).

## « Se souvenir de moi »

- Case **non cochée** par défaut ; durée **30 jours** ; cookie `REMEMBERME` `HttpOnly`, `SameSite=Lax`, `Secure` automatique en HTTPS (testés).
- Cookie **signé, sans table** : la signature couvre `password`, `active` et `superAdmin`. Démontré par des tests : un changement de mot de passe, la désactivation du compte ou un changement de privilège invalident le cookie ; un cookie modifié est rejeté ; la déconnexion le supprime.
- **Compte désactivé, deux protections indépendantes** : la signature (`active`) et `UserChecker`. Les tests montrent que retirer l'une des deux laisse l'autre refuser le compte, et que retirer les deux fait échouer le test.
- **Un utilisateur « mémorisé » n'est pas pleinement authentifié** : `/admin` exige `IS_AUTHENTICATED_FULLY` (il doit retaper son mot de passe) et `/login` s'affiche pour lui (pas de boucle de redirection). **Règle pour les futures opérations sensibles** : `#[IsGranted('IS_AUTHENTICATED_FULLY')]` (changement de mot de passe ou d'email, gestion des accès, exports, etc.).
- `lastLoginAt` n'est mis à jour que par une authentification par mot de passe, pas par la reconnexion automatique.

## Stratégie de révocation des sessions et des cookies persistants

| Événement | Sessions ouvertes | Cookies remember-me |
|---|---|---|
| Réinitialisation du mot de passe | Invalidées (hash du mot de passe comparé par `EquatableInterface`) | Invalidés (signature) |
| Changement de mot de passe par tout autre moyen | Idem | Idem |
| Désactivation du compte | Invalidées (`active` comparé) + refus par `UserChecker` | Invalidés (signature) + `UserChecker` |
| Changement du privilège superadmin | Invalidées | Invalidés |
| Déconnexion | La session | Le cookie de cet appareil |

Pas de table de jetons persistants en V1 : **la révocation de tous les appareils d'un compte sans changer son mot de passe n'est pas disponible**. Le moyen est la réinitialisation du mot de passe (ou la désactivation). Si ce besoin apparaît (« déconnecter tous mes appareils »), ajouter une colonne `securityStamp` à `User` et à la signature, sans autre changement d'architecture.

## Adresse IP du client, proxys et déploiement

**Principe : un en-tête `X-Forwarded-*` envoyé par un client n'est jamais cru.** Symfony ne l'utilise que si l'adresse qui se connecte (`REMOTE_ADDR`) fait partie de la liste des proxys de confiance.

| Environnement | Proxys de confiance | Fichier |
|---|---|---|
| **Développement local** (Docker, derrière Traefik) | **Traefik seul**, déclaré par `SYMFONY_TRUSTED_PROXIES` dans `.env.local` (adresse de Traefik sur le réseau Docker `reverse_proxy` : `docker inspect traefik`) ; en-têtes `x-forwarded-for`, `-proto`, `-port` seulement (jamais `-host`). **`private_ranges` n'est plus utilisé** (LOT 05.2 bis) : un conteneur voisin du même réseau n'est pas un proxy et ne peut plus falsifier l'adresse IP. Variable absente ou adresse de Traefik changée : échec fermé (l'IP vue est celle de Traefik, limiteurs plus stricts) | `config/packages/framework.yaml` (bloc `when@dev`), `.env.local`, `.env` (exemple commenté) |
| **Test** | Aucun | — |
| **Production cible** (Nginx + PHP-FPM, sans Traefik ni Redis) | **Aucun** : `REMOTE_ADDR` est l'adresse réelle du client | rien à configurer ; ne pas définir `SYMFONY_TRUSTED_PROXIES` |

Traefik n'est **pas** une dépendance de production et la configuration Docker n'a pas été modifiée.

Adaptations à prévoir si l'architecture évolue :

1. **Reverse proxy ou CDN devant Nginx** (Nginx n'est plus le premier saut) : déclarer **uniquement** les adresses/plages réelles du proxy dans `SYMFONY_TRUSTED_PROXIES` (jamais `0.0.0.0/0` ni `private_ranges` en production) et n'autoriser que les en-têtes réellement posés par ce proxy (`SYMFONY_TRUSTED_HEADERS`) ; pour un CDN, utiliser l'en-tête qu'il garantit (ex. `X-Forwarded-For`) et ses plages publiées. Côté proxy, **écraser** l'en-tête reçu du client au lieu de l'ajouter. Sans cela, tous les clients partageraient l'IP du proxy et le plafond global de 50 connexions / heure serait atteint par l'ensemble des utilisateurs.
2. **Plusieurs instances Symfony** : l'état des limiteurs (connexion, mot de passe oublié) est aujourd'hui dans le **cache fichier** de chaque instance (`cache.rate_limiter`, adapté à **une seule instance**). Avec N instances, les plafonds seraient multipliés par N et un client pourrait les contourner. Il faut alors un **stockage partagé** (Redis, ou base de données via un pool de cache) pour `cache.rate_limiter`, ainsi que pour les sessions (sessions en base ou Redis, sinon les utilisateurs sont déconnectés à chaque changement d'instance) et pour le verrouillage éventuel (`symfony/lock`). Redis n'est **pas** installé : décision différée.
3. **Hôtes autorisés** : en production, configurer Nginx avec un `server_name` explicite (et un serveur par défaut qui refuse les autres hôtes) et envisager `framework.trusted_hosts`. Les liens des emails n'en dépendent pas (ils utilisent `DEFAULT_URI`), mais le reste de l'application génère des URL à partir de la requête.
4. **HTTPS** : cookies `Secure` automatiques ; derrière un proxy qui termine TLS, le proxy doit être déclaré de confiance avec `x-forwarded-proto`, sinon Symfony croira être en HTTP et ne posera pas `Secure`.

## Initialisation du premier superadmin

```
docker exec -it saas_civipass_php-fpm php bin/console app:super-admin:create admin@exemple.fr
```

- **Uniquement si aucun superadmin actif n'existe** (installation, ou récupération d'incident). Sinon refus, sans rien modifier. Ce n'est pas un contournement de la protection du dernier superadmin : elle ne retire ni ne désactive personne.
- **Idempotente** : relancée pour le compte déjà initialisé, elle réussit sans modification ni entrée d'audit.
- **Compte inexistant** : créé (prénom et nom demandés, ou `--first-name` / `--last-name`). **Compte actif existant** : promu, mot de passe inchangé. **Compte désactivé** : refusé.
- **Mot de passe** : jamais en argument ni en option (historique shell, liste des processus). Interactif : saisie masquée avec confirmation. Non interactif : variable d'environnement `CIVIPASS_SUPERADMIN_PASSWORD`. 12 caractères minimum.
- **Audit** : `USER_SUPER_ADMIN_CHANGED`, origine `CONSOLE`, sans acteur.
- Deux exécutions concurrentes sont sérialisées par un verrou sur les superadmins actifs.

## Risques et limites

1. **Une seule instance** : voir la section « Adresse IP du client, proxys et déploiement » (limiteurs en cache fichier, sessions fichiers).
2. **Pas de double authentification** : hors périmètre. Un attaquant qui contrôle la messagerie d'un compte peut le réinitialiser : l'email est le facteur de récupération.
3. **Pas de révocation de tous les appareils sans changement de mot de passe** (voir la stratégie de révocation).
4. **Envoi des emails sans relance** (V1) : un incident SMTP est journalisé mais le lien n'est pas renvoyé ; l'utilisateur peut refaire une demande (limites : 3 / heure).
5. **Résidu de canal temporel** : une demande pour un compte existant écrit en base (quelques ms de plus).
6. **Pages d'authentification** : recomposées avec les composants du design system (`layouts/auth.html.twig`, voir `docs/design-system.md`) ; comportement, noms de champs et jetons CSRF inchangés. Elles fonctionnent toujours sans JavaScript.
7. **Base de développement** : la migration du LOT 01.1 n'est pas jouée sur `civipass` ; la connexion n'y fonctionnera qu'après cette migration (décision à prendre).
