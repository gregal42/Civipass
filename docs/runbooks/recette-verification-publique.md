# Recette du contrôle public `/v/{jeton}` sur un VPS HTTPS dédié (LOT 07.3.5)

**Statut : procédure préparée au LOT 07.3.4. Rien n'est créé, déployé ni activé par ce document.** Civipass reste en développement exclusivement local : **cette recette est reportée à la phase finale de préparation de la mise en service** (décision du 17 novembre 2026) et son exécution (07.3.5) exige une **autorisation explicite distincte** (§ 2). Écarts ouverts : [ecarts-techniques-connus.md](../ecarts-techniques-connus.md). Cadrage : [lot-07-3-cadrage.md](../lot-07-3-cadrage.md) §§ 15 à 17 ; décisions [DM-125](../decisions/metier/DM-125-rappel-de-rapprochement-et-avertissement-de-non-diffusion-du-qr.md) à [DM-128](../decisions/metier/DM-128-messages-nationaux-fixes-des-erreurs-du-controle-public.md), [DT-055](../decisions/technique/DT-055-limitation-de-debit-du-controle-public-echecs-et-garde-fou-d-acces.md) ; exploitation : [exploitation-verification-publique.md](../exploitation-verification-publique.md).

## 0. Règles

1. **Données fictives uniquement.** Aucune donnée réelle d'une commune, d'un agent ou d'un citoyen. Aucune plaque réelle de tiers.
2. **Serveur dédié et jetable**, même kit que la production ([`ops/nginx/`](../../ops/nginx/README.md)), sous un sous-domaine de recette. Jamais la production, jamais un serveur partagé.
3. `PUBLIC_VERIFICATION_ENABLED=1` **uniquement dans l'environnement de ce serveur**, pendant la recette, avec accord horodaté. `RIGHTS_ERASURE_EXECUTION_ENABLED` et `RETENTION_PURGE_ENABLED` restent à `0`. Le dépôt, `.env` et la production ne sont jamais modifiés.
4. Aucune case n'est « réussie » sans **preuve** (§ 10). Un test automatisé ne remplace pas un appareil réel.
5. Le serveur est **détruit** à la fin (§ 11).

## 1. Ce qui est automatisé et ce qui exige cette recette

Automatisé (déjà en CI locale) : verdicts et états, ordre des zones, champs exposés, en-têtes, absence de cookie, 404 uniforme, limitation de débit (jeton valide après jetons inconnus, plafond d'accès, IP partagée, IPv6, en-têtes forgés), panne du stockage, journaux sans jeton, interrupteur à `0`. **Exige un appareil ou un vrai serveur** : lecteur de QR de l'appareil photo, certificat et HTTPS, lisibilité en plein soleil, cache avant/arrière, mode avion, 4G dégradée, passerelle partagée, QR imprimé ou photographié, nginx réel (journaux masqués, vraie IP), charge réelle du VPS.

## 2. Prérequis exacts pour autoriser le 07.3.5

À fournir **par écrit** avant toute création :

| # | Prérequis | Précision attendue |
|---|---|---|
| P1 | Autorisation de créer **un VPS de recette** | Hébergeur, région UE, offre, coût, **durée maximale** (proposition : 7 jours), responsable de la destruction |
| P2 | **Sous-domaine** et maîtrise du DNS | ex. `recette.<domaine>` ; enregistrement A (et AAAA si IPv6) ; aucune réutilisation du domaine de production |
| P3 | **Version à déployer** | Référence Git (étiquette ou hash de la branche `develop`), aucune modification de `main` |
| P4 | **Accès SMTP de recette** (ou décision de s'en passer) | Pour recevoir un lien bénéficiaire réel et le PDF |
| P5 | **Appareils et testeurs** | Au moins 1 iPhone (Safari) et 1 Android (Chrome), versions notées ; 1 imprimante ; 1 second téléphone ; carte SIM avec partage de connexion |
| P6 | **Accord d'activation temporaire** de `PUBLIC_VERIFICATION_ENABLED=1` sur ce seul serveur | Date, heure, personne qui accorde |
| P7 | Accord sur le **jeu de données fictives** et les plaques de test | § 6 |
| P8 | `ops/nginx/tests/verify-nginx.sh` et `verify-rotation.sh` rejoués (Docker) | Résultat joint ; sinon, décision de les rejouer sur le serveur |
| P9 | Politique d'exposition du serveur | Accès SSH par clé seule ; pare-feu (22, 80, 443) ; liste des personnes ayant un accès |

## 3. Préparation du VPS dédié

Debian 12/13 ou Ubuntu 22.04/24.04, 2 vCPU et 2 Go de RAM minimum (à noter : la charge mesurée au § 9 dépend de l'offre, et celle de la production devra être confrontée à la même méthode).

1. Mises à jour de sécurité, fuseau UTC, synchronisation d'horloge (`systemd-timesyncd`), utilisateur d'administration, SSH par clé, mot de passe désactivé, `ufw` : 22, 80, 443 seulement.
2. Paquets : nginx stable **≥ 1.25.1**, PHP-FPM 8.4 avec les extensions du projet, MySQL 8.4, `composer`, `git`, `certbot`, `logrotate`, `perl`, `curl`.
3. Enregistrement DNS créé, propagation vérifiée (`dig +short recette.<domaine>`).
4. Notez dans le rapport : hébergeur, IP, offre, date et heure de création.

## 4. nginx, certificat et journalisation

À suivre **tel quel** : [`ops/nginx/README.md`](../../ops/nginx/README.md) §§ 3 à 11. Points de contrôle :

1. `civipass.env` de recette : `CIVIPASS_DOMAIN=recette.<domaine>` (jamais le domaine d'exemple, que `render.sh` refuse), certificat Let's Encrypt (`certbot certonly --webroot` ou `--nginx`), `PHP_FPM_UPSTREAM`, `DOCROOT=…/public`.
2. `ops/nginx/bin/render.sh --env civipass.env --out /tmp/civipass-nginx`, installation des fichiers, `nginx -t`, rechargement.
3. Dossiers et fichiers de journaux **avant** le démarrage ; pool PHP-FPM sous un utilisateur distinct ; minuterie de rotation horaire activée.
4. **Aucun proxy de confiance** : `SYMFONY_TRUSTED_PROXIES` vide (nginx est le premier saut, l'adresse du client est `REMOTE_ADDR`). C'est ce qui rend les plafonds par IP valables.
5. Contrôles : `ops/nginx/bin/check-deployment.sh --domain recette.<domaine> <.env> <.env.local>` **sans échec** (il signale aussi si `PUBLIC_VERIFICATION_ENABLED` est actif : attendu, informatif, pendant la recette seulement) ; `ops/nginx/bin/check-rotation.sh` ; HTTPS : `curl -sI https://recette.<domaine>/` (HSTS), `openssl s_client -connect recette.<domaine>:443 -servername recette.<domaine>` (chaîne complète, TLS 1.2 ou plus).

## 5. Application

1. Déploiement de la version P3 dans `DOCROOT` (jamais de `main`), `composer install --no-dev --optimize-autoloader`, cache vidé et réchauffé.
2. `.env.local` du serveur : `APP_ENV=prod`, `APP_DEBUG=0`, `DEFAULT_URI=https://recette.<domaine>`, secrets et base **propres à la recette** (aucun secret copié de la production), SMTP de recette, interrupteurs à `0` (ils sont **activés au § 7 seulement**).
3. Base MySQL dédiée ; migrations **existantes** appliquées (`doctrine:migrations:migrate`, aucune migration nouvelle dans ce lot) ; `doctrine:schema:validate` sans écart.
4. `app:super-admin:create` pour le compte CWS de recette ; `app:event-types:initialize`.
5. Vérifier que `/v/<jeton>` répond **404 uniforme** (interrupteur à `0`) et que le site répond en HTTPS.

## 6. Données fictives de recette

À créer par les écrans existants (Sonata pour les communes et accès, écran Équipe pour les agents, formulaire public de dépôt pour les demandes). **Registre** : tenir un registre des données créées (identifiants, dates) pour le nettoyage.

| Élément | Contenu |
|---|---|
| Communes | « Commune Test A » et « Commune Test B » (fictives, jamais une vraie commune) |
| Agents | un administrateur et un instructeur par commune, adresses e-mail de recette |
| Événement 1 | actif, deux zones par commune ; événement 2 : à clore pendant la recette |
| LP-1 | demandeur fictif, **deux plaques fictives**, zones des deux communes, approuvé par les deux ; e-mail de recette |
| LP-2 | une seule commune, une plaque |
| LP-3 | destiné à la **révocation** d'une autorisation et au **remplacement du QR** |
| LP-4 | sur l'événement 2 (clôture) |
| LP-5 | sans plaque (« Aucun véhicule associé ») |

Plaques : formats valides mais **attribuées à personne** ; aucune plaque d'un collaborateur ou d'un tiers. Les noms sont fictifs. Le rapport indique la liste.

## 7. Activation temporaire

1. **Accord P6 reçu** (heure notée).
2. Sur le serveur uniquement : `PUBLIC_VERIFICATION_ENABLED=1` dans `.env.local`, cache vidé ; `check-deployment.sh` relancé (état « contrôle public ouvert » attendu).
3. Noter l'heure d'activation. Tout est consigné dans le rapport (§ 12).
4. **Rappel** : les autres interrupteurs restent à `0`.

## 8. Scénarios sur appareils

Pour chaque scénario : appareil, système, navigateur (versions), heure, **capture** ou photo, résultat (Réussi / Échoué / Non exécuté), commentaire. Identifiants : `VP-nn`. P0 = bloquant pour toute activation en production.

### 8.1 Android (Chrome, puis l'appareil photo natif et Google Lens)

| ID | Scénario | Attendu | Prio |
|---|---|---|---|
| VP-01 | Scan du QR de LP-1 avec l'appareil photo natif | Le navigateur s'ouvre sur HTTPS **sans avertissement de certificat** ; la page s'affiche | P0 |
| VP-02 | Lecture de l'écran à 360 px de large | **Verdict, nom, plaques** visibles **sans défilement** ; le rappel de rapprochement est lisible juste dessous ; aucun défilement horizontal | P0 |
| VP-03 | Thèmes clair et sombre forcés par le système | Contrastes lisibles, aucun texte coupé | P0 |
| VP-04 | **Plein soleil**, luminosité maximale, à bout de bras | Verdict et plaques lisibles sans effort | P0 |
| VP-05 | LP-1 : une zone suspendue, une valide, deux communes | Zone **valide en premier**, état écrit de chaque zone, compteur « N sur M » exact | P0 |
| VP-06 | Révocation d'une autorisation de LP-3 puis nouveau scan | Verdict « Droits révoqués » ou partiel, **sans** actualisation manuelle préalable | P0 |
| VP-07 | Remplacement du QR de LP-3 ; scan de l'**ancien** QR | Page 410, message « Demandez le nouveau QR code à la personne ou contactez la commune » | P0 |
| VP-08 | QR forgé (jeton inventé) | Page 404, « Ne concluez pas à une fraude… » | P0 |
| VP-09 | Clôture de l'événement 2 puis scan de LP-4 | « Événement terminé », **ni nom, ni plaque, ni zone** | P0 |
| VP-10 | Bouton **Actualiser** | Rechargement ; l'heure de vérification change | P1 |
| VP-11 | LP-5 (sans plaque) | « Aucun véhicule associé » | P1 |
| VP-12 | Taille tactile des boutons (au doigt, gants fins) | Utilisables | P1 |

### 8.2 iPhone (Safari, puis l'appareil photo natif)

Rejouer **VP-01 à VP-12** (mêmes attendus ; identifiants `VP-01-i` à `VP-12-i`), et :

| ID | Scénario | Attendu | Prio |
|---|---|---|---|
| VP-13 | Safari **plus ancien** supporté (plancher iOS 16.4, [design-system.md](../design-system.md)) si un appareil est disponible | Mise en page non dégradée (container queries, `color-mix`) | P1 |
| VP-14 | **Cache avant/arrière** : afficher LP-3, révoquer, revenir à l'onglet par le bouton retour | L'heure de vérification affichée dit que la page est ancienne ; noter si Safari réaffiche l'ancien verdict. **Ne pas conclure sans observation** ; si l'ancien verdict apparaît sans signe clair d'ancienneté, anomalie à traiter (envisager U5, cadrage § 6) | P0 |
| VP-15 | Ouverture du lien depuis une application de messagerie (navigateur intégré) | Page affichée, pas de blocage | P2 |

### 8.3 QR imprimés et photographiés

| ID | Scénario | Attendu | Prio |
|---|---|---|---|
| VP-20 | PDF du laissez-passer (LP-1) imprimé en A4, scan | Lisible ; mention « seul le contrôle du QR code fait foi » et avertissement de non-diffusion présents | P0 |
| VP-21 | QR du PDF réduit à ~3 cm | Se scanne | P1 |
| VP-22 | QR affiché sur écran, **photographié** par un second téléphone puis scanné depuis la photo | Se scanne ; la page est identique : la copie fonctionne (risque DM-115 §7 : le rappel de rapprochement est le seul garde-fou visible) | P0 |
| VP-23 | QR froissé, plié, partiellement caché, reflet | Noter la limite de lecture (aucun critère d'échec : information pour la consigne de terrain) | P2 |
| VP-24 | Distance de lecture (30 cm, 1 m) | Noter | P2 |
| VP-25 | **Capture d'écran du QR** envoyée par messagerie, scannée | Fonctionne (même jeton) ; le consigner comme risque résiduel connu | P0 |

### 8.4 Réseaux

| ID | Scénario | Attendu | Prio |
|---|---|---|---|
| VP-30 | Réseau mobile 4G/5G | Page affichée ; temps de premier affichage noté | P0 |
| VP-31 | Wi-Fi invité | Idem | P1 |
| VP-32 | **Réseau partagé** : deux téléphones sur la même passerelle 4G (partage de connexion) ; le premier scanne **plus de 25 QR inconnus**, le second scanne LP-1 | Le second obtient **200** : les échecs d'un téléphone ne bloquent pas l'autre (DT-055) | P0 |
| VP-33 | **Mode avion**, puis scan | Le navigateur affiche son erreur de connexion ; **aucun verdict ancien présenté comme actuel**. Retour du réseau puis Actualiser : verdict correct | P0 |
| VP-34 | **4G dégradée** (zone mal couverte, ou « Network Link Conditioner » sur iPhone, limitation réseau d'Android) | Page affichée ; noter le temps ; **objectif de départ : premier affichage utile en moins de 5 s** (hypothèse à confirmer, non validée) | P1 |
| VP-35 | Changement de réseau en cours de chargement (Wi-Fi vers 4G) | Page affichée après nouvel essai | P2 |

### 8.5 Cache et confidentialité côté navigateur

| ID | Scénario | Attendu | Prio |
|---|---|---|---|
| VP-40 | `curl -sI https://recette.<domaine>/v/<jeton>` (200, 404, 410, 429, 503 quand c'est possible) | `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, CSP ; **aucun `Set-Cookie`** | P0 |
| VP-41 | Aucun cookie ni stockage local créé sur l'appareil (outils du navigateur ou `chrome://inspect`/Safari Web Inspector) | Aucun | P0 |
| VP-42 | **Historique du navigateur** | L'URL scannée **y est conservée** (elle porte le jeton) : risque **accepté et documenté** ([ecarts-techniques-connus.md](../ecarts-techniques-connus.md)). **La navigation privée n'est pas une condition du contrôle** ; elle est conseillée sur les appareils partagés. Vérifier seulement que rien d'autre que l'historique local ne conserve l'URL | P1 |
| VP-43 | Aucune ressource tierce : onglet réseau du navigateur | Toutes les ressources sont de `recette.<domaine>` | P0 |

### 8.6 Serveur

| ID | Contrôle | Attendu | Prio |
|---|---|---|---|
| VP-50 | Après toute la recette : recherche des jetons dans `civipass-access.log`, `civipass-error.log` (actif et tournés), journaux PHP-FPM et Symfony | **Zéro occurrence de jeton**, de secret bénéficiaire ni de requête en clair ; chemins `/v/***` | P0 |
| VP-51 | Journal d'incidents de limitation (après le § 9) | Lignes `plafond … atteint pour une adresse`, **sans adresse ni jeton** | P0 |
| VP-52 | 404 d'un jeton inconnu, mal formé, et avec l'interrupteur à `0` (avant et après la recette) | Corps et en-têtes identiques | P0 |
| VP-53 | `check-deployment.sh` et `check-rotation.sh` | Aucun échec | P0 |
| VP-54 | HSTS, TLS, redirection HTTP vers HTTPS sans fuite du chemin (`curl -sI http://recette.<domaine>/v/<jeton>`) | Redirection sans écho du jeton dans le corps ; journaux masqués | P0 |

## 9. Tests de charge et seuils de limitation

Objectif : **confronter 1 500 consultations / 10 min / IP (valeur provisoire, [DT-055](../decisions/technique/DT-055-limitation-de-debit-du-controle-public-echecs-et-garde-fou-d-acces.md)) à la capacité réelle du VPS**, sans présumer d'un nombre de contrôleurs. Script : [`ops/recette/charge-verification.sh`](../../ops/recette/charge-verification.sh) (HTTPS seul, confirmation explicite, jeton jamais affiché, GET uniquement).

```bash
CIVIPASS_RECETTE_CONFIRM=serveur-de-recette-donnees-fictives \
  ops/recette/charge-verification.sh --base https://recette.<domaine> --token <jeton de LP fictif> --requests 600 --concurrency 20
```

Le script s'exécute **depuis une machine extérieure au serveur** (sinon la mesure ignore le réseau) ; l'adresse du client est alors celle de cette machine, **dont les compteurs sont consommés** : attendre 10 à 15 minutes entre deux essais, ou changer de réseau.

| Essai | Commande | Observer |
|---|---|---|
| L1 — référence | `--requests 100 --concurrency 1` | Latence de base (p50, p95) |
| L2 — charge normale | `--requests 600 --concurrency 20` | Codes tous 200 ; p95 ; charge CPU/mémoire du serveur (`top`, `vmstat`) |
| L3 — charge forte, **IP distinctes** | Lancer L2 **simultanément** depuis 3 machines ou téléphones sur des réseaux distincts | Capacité globale soutenable ; saturation PHP-FPM (`pm.max_children` atteint), erreurs 5xx |
| L4 — **plafond d'accès** | Depuis une seule adresse : `--requests 1600 --concurrency 20` (au-delà de 1 500) | Les **premiers 1 500** reçoivent 200, les suivants **429** avec `Retry-After` ; **une autre adresse reste à 200** ; reprise après ≤ 10 min |
| L5 — **jetons inconnus** | `--requests 200 --unknown-every 2` | 404 jusqu'à l'allocation (20), puis 429 **pour les inconnus** ; **les requêtes à jeton valide restent à 200** ; journal « plafond de jetons inconnus atteint » |
| L6 — **non-régression de panne** (optionnel, manuel) | Rendre `var/share` non inscriptible quelques secondes | 503 contrôlé, journal d'erreur, retour à la normale après rétablissement |

**Règle de réglage (à appliquer par un humain, pas par le script)** : relever, à L2/L3, le débit soutenable du serveur avec p95 acceptable (proposition : < 1 s) et sans erreur 5xx. Le plafond par adresse sur 10 minutes ne doit **pas dépasser une part tolérable de cette capacité** (proposition : au plus 10 % de `débit soutenable × 600 s`), et il doit **rester au-dessus du trafic légitime d'un poste de contrôle observé**. Si 1 500 respecte ces deux conditions, il est conservé ; sinon `VERIFICATION_ACCESS_LIMIT` est ajusté **par variable d'environnement** (sans code) et la valeur définitive, avec ses mesures, est consignée dans le rapport pour **décision**. **La valeur de production n'est pas fixée par cette recette** : elle est proposée, puis validée.

## 10. Critères d'acceptation et preuves attendues

**Acceptation de la recette** (condition nécessaire, non suffisante, pour demander l'activation en production) :
- Tous les scénarios **P0** (§ 8) sont « Réussi » sur **un iPhone et un Android**, avec preuve ; les P1 sont réussis ou leurs écarts sont acceptés par écrit.
- Aucune occurrence de jeton ou de secret dans les journaux (VP-50) ; en-têtes conformes (VP-40) ; aucun cookie (VP-41).
- Tests de charge L1 à L5 joués, résultats consignés, valeur de plafond **proposée** et justifiée (§ 9).
- Aucune anomalie bloquante ouverte (sécurité : correction avant clôture ; confort : classée, acceptée ou planifiée).

**Preuves** (hors Git, conservées avec le rapport, **sans donnée réelle**) : capture ou photo horodatée par scénario (modèle et système de l'appareil, navigateur et version), sortie de `curl -sI`, sorties de `check-deployment.sh` / `check-rotation.sh`, extraits **de journaux sans secret**, sortie du script de charge (jeton jamais affiché), schéma de la topologie (une instance, nginx premier saut). Un scénario sans preuve est « Non exécuté ».

## 11. Désactivation et nettoyage

À faire **dès la fin**, dans l'ordre :

1. `PUBLIC_VERIFICATION_ENABLED=0` (cache vidé) ; constater un **404 uniforme** sur `/v/<jeton valide>` (consigné) ; `check-deployment.sh` : contrôle fermé.
2. Exporter les preuves vers le dépôt de rapport (hors Git) ; vérifier qu'elles ne contiennent aucune donnée réelle.
3. Supprimer la base de recette, les journaux (nginx, PHP-FPM, Symfony), le cache, le registre de données ; ne rien copier vers un autre environnement.
4. Révoquer le certificat, **supprimer l'enregistrement DNS**, fermer les accès SSH, révoquer les clés, détruire le VPS chez l'hébergeur (confirmation d'hébergeur jointe au rapport).
5. Confirmer par écrit que les interrupteurs de **tous les environnements durables** sont inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

## 12. Rapport de recette

Un document daté, signé du responsable de la recette : version déployée (P3), appareils et versions, tableau des scénarios (§ 8) avec statut et preuve, résultats de charge et valeur de plafond **proposée**, anomalies (gravité, décision), limites connues (VP-22, VP-25 : copie du QR ; VP-42 : historique du navigateur), heures d'activation et de désactivation, confirmation de destruction. Il alimente la décision d'activation en production, **qui reste hors de ce lot et soumise à validation explicite** (conditions : [exploitation-verification-publique.md](../exploitation-verification-publique.md) § 6).
