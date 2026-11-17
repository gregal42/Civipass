# LOT 07.3 — Contrôle QR sur le terrain : cadrage

**Statut : version 2 validée le 17 novembre 2026 (orientations, arbitrages A1 à A8 et plan des §§ 11 à 18). Incréments 07.3.1 à 07.3.4 en exécution autonome ; 07.3.5 (recette appareils réels) soumis à autorisation distincte.** Les §§ 1 à 10 sont la version 1 ; en cas de divergence, les §§ 11 à 17 prévalent. Aucune migration, aucun déploiement, aucun push de ce document.
Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.
Références : DM-113, DM-115 (et ses amendements des 10 et 11 novembre 2026), DM-116, DM-117, DM-120, DT-048, DT-050, [exploitation-verification-publique.md](exploitation-verification-publique.md), [lot-05-5-6-checklist-terrain.md](lot-05-5-6-checklist-terrain.md).

## 1. État réel du contrôle QR existant

**Le contrôle en ligne sans compte est déjà développé (LOT 05.4) et testé. Il est désactivé par défaut.** Le LOT 07.3 ne le construit donc pas : il l'éprouve, l'adapte au terrain et prépare son activation.

| Domaine | Existant |
|---|---|
| Route | `GET /v/{jeton}` (et variante avec `/` final, sans redirection), `PublicVerificationController`. Interrupteur coupé : même 404 qu'un jeton inconnu, sans lecture de la base ni des compteurs. |
| Jeton | 160 bits, base32 minuscule, 32 caractères, `random_bytes`, stocké en clair, un seul jeton actif par laissez-passer, QR stable (DM-113). Aucune donnée personnelle ni référence `DEM-`/`LP-` dans l'URL. |
| Droits | `PublicVerificationReader` + `PermitRightsEvaluator` (mode vérification) : **recalculés à chaque consultation**, rien n'est stocké ni mis en cache. |
| Verdict global | `PublicVerdict` : droits actifs / à venir / suspendus / événement terminé / révoqués / expirés / aucun droit actif. Détail par zone et par commune, périodes écrites, zones retirées à part, compteur N/M. |
| Données exposées | Nom, prénom, plaques, zones, périodes, référence, événement, communes (DM-115 §2). Jamais adresse, e-mail, téléphone, justificatif, motif. |
| Événement clos | **Statut neutre « Événement terminé » seul**, HTTP 200 (décision du 10 novembre). Événement suspendu : « Droits suspendus ». |
| Jeton retiré / inconnu | 410 sans donnée / 404 uniforme (aussi pour un jeton mal formé et une route coupée). Après purge : 404 pour tout jeton (DM-115, amendement du 11 novembre). |
| Sécurité HTTP | Pare-feu `public_verification` (`security: false`, aucune session ni cookie) ; `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`, `nosniff`, `X-Frame-Options: DENY`, CSP. |
| Limitation | 300 consultations / 10 min / IP ; 20 jetons inconnus / 15 min / IP ; 429 lisible avec `Retry-After` ; 503 explicite si le stockage des compteurs tombe. |
| Journaux | `SecretScrubbingLogger` ; configuration nginx préparée et testée dans `ops/nginx/`, **non appliquée à un serveur**. Aucun journal systématique des scans. |
| Écran | `verification.html.twig` : sans JavaScript, une colonne dès 390 px, verdict texte + icône (jamais la couleur seule), plaques en grand, bouton « Actualiser » (rechargement de l'URL courante), heure de vérification affichée. |
| Tests | `PublicVerification{Page,Scenarios,Security,ClosedEvent}Test`, `…ReaderTest`, `…TimeTest`, `PublicVerdictTest`, listener d'en-têtes, limiteur, extraction du jeton. |
| Recette terrain | **Jamais exécutée** : TER-01 à TER-07 ter et CLO-01 sont « Non exécuté » ou « Bloqué » (aucun téléphone n'atteint l'environnement de développement ; nginx natif non installé). |

### Écarts entre la demande et l'existant (à connaître avant validation)

1. **Contradiction documentaire sur la PWA** : la demande la reporte au LOT 08 ; `development-roadmap.md` et DM-115 la placent au **LOT 06** (« PWA et hors ligne, à redéfinir »), et le LOT 08 est « Administration SaaS et durcissement ». À résoudre (voir A8).
2. **« Sortie d'une commune »** : le modèle n'a pas d'événement « sortie » sur le contrôle. Les cas existants sont la **participation** clôturée ou suspendue, la **commune** suspendue ou inactive, la **révocation** d'une autorisation. Chacun est déjà traduit par un état de zone. Le cadrage les teste de bout en bout et ne crée pas de nouveau concept.
3. **Plaques « uniquement »** : DM-115 valide aussi l'affichage du **nom et du prénom** (le contrôleur rapproche la personne du véhicule). Les restreindre aux seules plaques modifierait une décision validée (A1).
4. **Pas de vérification « pour une zone donnée »** : la page affiche toutes les zones du laissez-passer et le contrôleur lit celle qui le concerne. Aucun paramètre de zone n'existe, il n'a pas été décidé (A3).

## 2. Parcours utilisateur cible

1. L'agent remet le laissez-passer (lien bénéficiaire, PDF ou impression) portant le QR (DM-114, DM-118).
2. Le contrôleur scanne avec l'appareil photo natif (iPhone, Android) ; le navigateur ouvre `https://<domaine>/v/<jeton>`.
3. Civipass lit le jeton, applique les plafonds, recalcule les droits à l'instant T.
4. Premier écran, sans défilement : **verdict global** (mot + icône + couleur, très grand), **plaques** en grand, nom et prénom, événement, zones avec leur état individuel.
5. Le contrôleur rapproche plaques et personne ; il peut **Actualiser** (même URL) avant de décider.
6. Aucun lien de la page ne mène à un dossier, une demande, une commune ou un écran authentifié.

Hors parcours : aucun compte, aucune application, aucune PWA, aucun stockage local d'autorisation, aucun journal des scans.

## 3. Informations affichées selon l'état

| Situation (calcul serveur à l'instant T) | HTTP | Verdict | Contenu |
|---|---|---|---|
| Au moins une zone valide | 200 | Droits actifs (N/M zones) | Complet : identité, plaques, zones et états, périodes, référence, événement |
| Aucune zone valide, une à venir ou hors créneau | 200 | À venir | Complet ; aucune zone présentée comme utilisable |
| Événement, commune, participation ou zone suspendus | 200 | Droits suspendus | Complet ; aucune zone présentée comme utilisable |
| Toutes les autorisations révoquées | 200 | Révoqué | Complet (motif jamais affiché) |
| Toutes les zones expirées | 200 | Expiré | Complet |
| Zone retirée | 200 | — | Listée à part, jamais comptée comme valide |
| Événement définitivement clos | 200 | Événement terminé | **Statut neutre seul** (décision du 10 novembre) |
| QR remplacé (jeton retiré) | 410 | QR remplacé | Aucune donnée |
| Jeton inconnu, mal formé, route coupée, dossier purgé | 404 | Introuvable | Aucune donnée, réponse uniforme |
| Plafond atteint | 429 | Indisponible | « Ce n'est pas un refus d'autorisation » ; `Retry-After` |
| Compteurs en panne | 503 | Indisponible | Aucune donnée ; procédure de terrain |

Règle de lecture non négociable : **un laissez-passer n'est jamais présenté comme entièrement valide si une seule zone ne l'est pas** (DM-115 §4).

## 4. Règles de sécurité et de confidentialité

- **Jeton** : opaque, 160 bits, jamais dans les journaux, l'audit, la page (le bouton recharge l'URL courante) ni les en-têtes `Referer`. Réponse indiscernable pour inconnu / mal formé / désactivé.
- **Droits** : jamais stockés ni mis en cache ; réévalués à chaque requête ; lecture seule, aucune écriture, aucun effet de bord.
- **Cache** : `no-store, private` et `Pragma`. Point à vérifier sur appareil : le **cache avant/arrière de Safari iOS** (bfcache) peut réafficher un ancien verdict ; l'heure de vérification reste visible, et la recette contrôle ce cas (§7).
- **Pas de session ni de cookie** ; `X-Frame-Options: DENY` ; `noindex`.
- **Minimisation** : seules les données de DM-115 §2. Aucune adresse, coordonnée, justificatif ni motif de révocation. Aucun accès par `DEM-` ou `LP-`.
- **Diffusion ou copie d'un QR** (risque résiduel assumé, DM-115 §7) : le QR n'est pas une preuve d'identité. Atténuations existantes : QR remplaçable en cas de compromission (DM-113 §4), droits recalculés (une copie ne survit pas à une révocation), limitation de débit contre l'énumération. Atténuations proposées : rappel permanent à l'écran « Rapprochez le nom et les plaques de la personne et du véhicule » ; consigne identique sur le PDF et la page du bénéficiaire (A2).
- **Données personnelles** : base légale, information des personnes et durée de conservation à valider avant toute production (DM-117) ; prérequis d'activation, pas du développement.
- **Limitation de débit** : l'ensemble des contrôleurs d'un même point d'accès partage une IP (NAT, 4G partagée), donc un seul compteur : 30 consultations par minute pour tous, et **20 jetons inconnus en 15 min bloquent aussi les jetons valides** de cette IP. Risque opérationnel réel en crise : valeurs à confirmer (A4).

## 5. Conditions techniques d'activation de `PUBLIC_VERIFICATION_ENABLED`

Reprise et complétée de [exploitation-verification-publique.md](exploitation-verification-publique.md) § 6. **Toutes** les conditions, puis accord explicite du responsable du projet, par environnement :

1. nginx de `ops/nginx/` **appliqué** sur le serveur cible et ses contrôles (§ 11 du README) rejoués ; journaux PHP-FPM et Symfony vérifiés.
2. Topologie connue : proxys, `SYMFONY_TRUSTED_PROXIES`, stockage des compteurs (instance unique), politique de continuité si les compteurs tombent.
3. `APP_ENV=prod`, `APP_DEBUG=0`, `display_errors=Off`, `DEFAULT_URI` en `https://`, cache vidé au déploiement ; `check-deployment.sh` sans échec.
4. HTTPS valide sur le domaine réel (le QR encode l'URL de `DEFAULT_URI`).
5. Politique de conservation, base légale et information des personnes validées (DM-115 §7, DM-117) ; purge conforme (DM-120).
6. Recette sur appareils réels réussie avec preuves (§ 7).
7. Valeurs de limitation arrêtées (A4).

**Activation en recette isolée** : possible avant la production, avec accord explicite, uniquement sur un environnement de recette accessible aux téléphones (prérequis P0 de la checklist), remis à `0` ensuite. **Aucune activation en production sans validation explicite.**

## 6. Écrans et adaptations nécessaires

Le contrôle existe : aucune nouvelle page n'est requise. Adaptations proposées, toutes **sans JavaScript obligatoire** et sans migration :

| # | Adaptation | Pourquoi |
|---|---|---|
| U1 | Verdict et plaques au premier écran, sans défilement, sur 360 × 640 px | Lecture en une seconde |
| U2 | Contraste renforcé du verdict et des plaques (AAA visé), vérifié en plein soleil réel et en thème clair forcé | Lisibilité extérieure |
| U3 | Rappel permanent « Rapprochez le nom et les plaques de la personne et du véhicule » | Copie du QR |
| U4 | Âge de la vérification (« il y a N secondes ») et bouton Actualiser large, hors du pli si besoin | Cache arrière, décision sur données récentes |
| U5 | Gestion des erreurs réseau : sans réseau, c'est le navigateur qui répond ; ajouter, si la recette le montre nécessaire, un script minimal conforme à la CSP, **sans aucun stockage local**, qui signale « Vérification impossible : pas de connexion » sur échec d'actualisation | Aucun verdict ancien jamais présenté comme actuel |
| U6 | Pages 404 / 410 / 429 / 503 : message d'action pour le contrôleur (« appliquez la procédure de terrain ») | Situation de crise |
| U7 | Libellés : « Droits actifs » toujours accompagné de la ou des zones valides, nommées, en tête | Éviter le « tout valide » trompeur |

## 7. Tests automatisés et recette sur appareils réels

**Automatisés (à compléter, la base existe)** : scénario de bout en bout pour chaque cause de non-validité (révocation d'une commune, participation clôturée, commune suspendue, zone retirée, événement suspendu puis clos) avec le même QR ; clôture d'événement puis purge (404) ; plusieurs communes dont une seule révoquée ; en-têtes sur **toutes** les réponses y compris 5xx ; absence de cookie et de session ; absence de jeton et de donnée personnelle dans corps, en-têtes, journaux et audit ; énumération (limiteurs) ; non-régression si NAT (plafonds configurés) ; rendu de U1 à U7 (contenu, ordre, absence de lien sortant) ; `PUBLIC_VERIFICATION_ENABLED=0` verrouillé par défaut dans tous les fichiers d'environnement et de déploiement (test existant, conservé).

**Recette sur appareils réels** (preuves : captures ou photos, aucune case cochée sans exécution), sur un iPhone et un Android, navigateurs natifs (Safari, Chrome) **et** lecteur de QR de l'appareil photo, Google Lens :
TER-01/02 (scan, remplacement), TER-03 bis (clair et sombre, sans défilement horizontal), TER-04 (QR du PDF et de l'impression), CLO-01, TER-07/07 bis/07 ter (HTTPS, en-têtes, journaux, limitation avec la vraie IP) ; **ajouts** : soleil et luminosité maximale, mode économie d'énergie, mode avion puis retour du réseau, retour arrière après révocation (bfcache), 4G dégradée, deux téléphones sur une même 4G partagée (plafonds), QR imprimé abîmé ou photographié à l'écran.
**Prérequis bloquant** : un environnement de recette HTTPS accessible depuis les téléphones, ce qui est une décision d'infrastructure à valider (P0 de la checklist).

## 8. Migrations éventuelles

**Aucune prévue.** Le contrôle ne persiste rien et les droits sont calculés. Une migration ne serait nécessaire que pour une décision A5 (compteurs agrégés en base) ou A3 si elles étaient retenues ; elle ferait l'objet d'un cadrage et d'un accord distincts.

## 9. Arbitrages métier indispensables

| # | Question | Recommandation |
|---|---|---|
| A1 | **Nom et prénom** : conserver l'affichage complet (DM-115) ou ne montrer que les plaques et, par exemple, le prénom et l'initiale ? | Conserver DM-115 : sans identité, la plaque seule ne permet pas de rapprocher la personne. Toute minimisation amende une décision validée. |
| A2 | Mentions de prudence sur le QR copiable (U3, PDF, page bénéficiaire) | Oui : texte court, sans changement de décision |
| A3 | Vérification **pour une zone** (ex. `?zone=`) ou une seule liste de zones ? | Une seule page, zones nommées, la valide en tête : aucun nouveau paramètre ni surface d'attaque en V1 |
| A4 | Plafonds de débit et **politique d'échec** : un jeton valide doit-il rester vérifiable quand l'IP a atteint 20 inconnus ? Nombre de contrôleurs par point d'accès ? | À fixer avec des chiffres du terrain ; sinon relever le plafond d'échecs et ne bloquer que les inconnus |
| A5 | Compteurs agrégés de scans (autorisés par DM-115 §6) : oui ou non en V1 ? | Non en V1 (aucune donnée, aucune migration) |
| A6 | Messages de la page de conduite à tenir quand la vérification est indisponible (procédure de terrain, contact) : qui la rédige ? | La commune, via l'événement ; texte générique par défaut |
| A7 | Environnement de recette HTTPS accessible aux téléphones : lequel, qui l'héberge ? | À décider avant 07.3.5 ; sinon recette terrain impossible |
| A8 | PWA hors ligne : **LOT 06** (feuille de route, DM-115) ou **LOT 08** (votre message) ? | Corriger la feuille de route selon votre choix ; hors périmètre du 07.3 dans tous les cas |

## 10. Découpage proposé en incréments

| Incrément | Contenu | Dépend de |
|---|---|---|
| **07.3.1** | Arbitrages A1 à A8 consignés (fiches DM/DT), correction de la feuille de route | Validation du présent cadrage |
| **07.3.2** | Adaptations d'écran U1 à U4, U6, U7 (Twig, CSS du design system, sans JS), tests de rendu | 07.3.1 |
| **07.3.3** | Gestion d'erreur réseau U5 (seulement si la recette ou A4 le justifie), réglage et tests des plafonds | 07.3.1, A4 |
| **07.3.4** | Scénarios automatisés complémentaires (§ 7), documentation d'exploitation mise à jour, checklist d'activation | 07.3.2 |
| **07.3.5** | Recette appareils réels sur environnement isolé (accord explicite d'activation temporaire), rapport de recette, retour à `PUBLIC_VERIFICATION_ENABLED=0` | A7, 07.3.2 à 07.3.4 |

Clôture du lot : `bin/qa full`, `verify-docs.sh`, `doctrine:schema:validate`. L'activation en production reste **hors lot** et soumise à votre accord explicite.

## Écarts connus conservés (LOT 07.2, hors périmètre ici)

Recette visuelle de Sonata et de l'écran Équipe ; envoi d'invitations avec SMTP réel ; validation ergonomique avec des agents municipaux ; motif de révocation commerciale non persisté ; attente artificielle dans `RestoreReplayTest`.

---

# Version 2 — arbitrages et plan d'exécution définitif (17 novembre 2026)

## 11. Orientations validées (récapitulatif) et leur traduction

| # | Orientation validée | Traduction dans ce cadrage |
|---|---|---|
| 1 | Le 07.3 porte sur le contrôle web mobile existant ; la PWA hors connexion est reportée | Hors périmètre ; mise en cohérence documentaire proposée au § 12 |
| 2 | Nom, prénom et plaques conservés (DM-115), aucune donnée ajoutée | **A1 clos.** Un test verrouille la liste exacte des champs affichés (§ 16) |
| 3 | Limitation de débit revue, sans dépendre d'un nombre de contrôleurs | Stratégie au § 13 (remplace A4) |
| 4 | Recette HTTPS sur smartphones : prérequis et procédure préparés, rien déployé | § 15 (remplace A7) |
| 5 | Aucun concept de « sortie d'une commune » | **Clos.** États réutilisés : commune inactive, participation inactive, autorisation révoquée |
| 6 | Aucune migration SQL | **Clos.** Écarte aussi tout stockage de configuration par événement (voir A6) |

## 12. Mise en cohérence de la numérotation et de DM-115 (proposition, aucune modification faite)

Constat : `development-roadmap.md` et DM-115 §5 nomment « LOT 06 » la PWA hors ligne ; le LOT 08 est « Administration SaaS et durcissement » ; le LOT 07 regroupe notifications, audit, stockage, équipes communales et ce contrôle. Proposition, **modification de forme seulement** :

- **Feuille de route** : renommer l'intitulé « LOT 06 — PWA et hors ligne » en « **PWA et hors ligne — lot ultérieur, numéro et date non fixés, reporté après le LOT 08** » ; ajouter au LOT 07 la ligne « 07.3 : contrôle QR web mobile, fiabilisation et recette terrain (ce document) ». Le LOT 08 conserve son intitulé et son contenu.
- **DM-115** : ne pas toucher aux décisions §1 à §7. Ajouter un amendement daté, rédigé ainsi : « *Amendement de forme du … novembre 2026 : la mention « LOT 06 » du point 5 désigne la PWA hors ligne, désormais « lot ultérieur non planifié ». Aucune autre disposition n'est modifiée.* » Mêmes références mises à jour dans `lot-05-schema.md` §15.2 et dans `exploitation-verification-publique.md`.
- Vérification par `docs/tools/verify-docs.sh` ; DM-115 passe par la procédure d'amendement existante (jamais de réécriture silencieuse).

## 13. Limitation de débit : fonctionnement actuel, scénarios de blocage, stratégie cible

### 13.1 Fonctionnement actuel (code lu)

- Clé = adresse IP du client, **telle que PHP la voit** (`REMOTE_ADDR` ; IPv6 par préfixe /64). En production nginx est le premier saut et aucun en-tête de provenance n'est transmis à PHP : l'IP est celle de l'appareil ou de la passerelle 4G.
- Deux compteurs à fenêtre glissante, en cache fichier de l'instance :
  - `verification_access` : **300 / 10 min / IP**, consommé à **chaque** requête, quelle qu'en soit l'issue (page valide, 404, 410).
  - `verification_failure` : **20 / 15 min / IP**, consommé par un jeton **bien formé mais inconnu**. Il est **consulté avant toute recherche** (`consume(0)`) : plafond atteint, **tout** jeton est refusé en 429, **même valide**.
- Attente annoncée : 1 à 900 s (`Retry-After`). Panne du stockage : 503, jamais une absence silencieuse de limite.

### 13.2 Scénarios de blocage (réels, à l'état actuel)

| # | Scénario | Effet |
|---|---|---|
| B1 | Un agent scanne 20 anciens QR inconnus (laissez-passer purgé : tout jeton devient 404, DM-115 du 11 novembre ; QR d'un autre événement ; texte mal copié) | Les 20 échecs bloquent **tous** les contrôleurs derrière cette IP, **QR valides compris**, jusqu'à 15 min |
| B2 | Une personne malveillante derrière la même IP (ou le même NAT 4G) envoie volontairement 20 jetons inventés | Déni de service ciblé sur ce point d'accès, à coût nul |
| B3 | Passerelle 4G ou Wi-Fi d'un poste de contrôle : N contrôleurs, une IP | 300 consultations / 10 min, soit 30 par minute pour tous, avec l'actualisation et les rechargements |
| B4 | IPv6 : un préfixe /64 appartient souvent à un seul abonné, mais certains opérateurs en partagent plusieurs | Mêmes effets que B1 à B3 à l'échelle du préfixe |
| B5 | Stockage des compteurs en panne (disque plein, droits) | 503 pour tous, y compris pour les jetons valides, jusqu'à rétablissement |
| B6 | Mauvaise configuration de proxy en production (proxy déclaré sans nécessité) | Tous les utilisateurs partagent l'IP du proxy : un seul compteur pour toute la commune. `check-deployment.sh` le détecte déjà |

### 13.3 Stratégie cible (sans nombre fixe de contrôleurs)

Principe : **ne jamais faire dépendre l'accès d'un jeton valide du comportement d'un autre jeton.** Un jeton valide fait 160 bits : la probabilité qu'une devinette tombe juste est de 2⁻¹⁶⁰, donc ralentir les jetons inconnus suffit à contenir l'énumération, sans toucher aux jetons valides.

1. **Les échecs ne bloquent que les jetons inconnus.** Ordre : plafond d'accès → recherche du jeton → si l'issue est « inconnu » **et** que le plafond d'échecs est atteint, 429 ; si l'issue est valide, retirée ou événement clos, la réponse normale est servie. Le compteur d'échecs n'est plus consulté avant la recherche. (Corrige B1 et B2.)
2. **Le plafond d'accès devient un garde-fou contre l'inondation, pas un quota de contrôleurs** : relevé à une valeur élevée (proposition : 1 500 / 10 min / IP, soit 150 par minute), **réglable par variable d'environnement**. La valeur est arrêtée par un **test de charge** du 07.3.5 (coût réel d'une requête sur le VPS), pas par un nombre de contrôleurs. (Corrige B3.)
3. **Aucune clé dérivée du jeton, aucune donnée personnelle** dans les compteurs : seules des clés d'adresse y passent (inchangé).
4. **Panne du stockage : le comportement 503 est conservé** (pas de contrôle ouvert sans limite) ; la procédure de terrain est affichée (A6). Le risque B5 est surveillé (journal `error` existant) ; une bascule vers « servir sans limite » reste **interdite sans décision**.
5. **Observabilité sans secret** : journal `warning` agrégé « plafond d'échecs atteint » (sans IP ni jeton, avec un simple compteur) pour détecter B1 et B2 en exploitation.
6. **Résidu assumé** : un attaquant derrière la même IP peut toujours saturer le plafond d'accès (garde-fou) ; l'effet est un 429 explicite, borné à 10 min, et le contrôleur applique la procédure de terrain. Documenté dans le runbook.

### 13.4 Tests prévus (aucun ne dépend d'un nombre de contrôleurs)

Horloge simulée, stockage de test : (a) 50 jetons inconnus puis un jeton **valide** de la même IP → 200 ; (b) jeton retiré et événement clos → 410 et statut neutre après le plafond d'échecs ; (c) jeton inconnu après plafond → 429 + `Retry-After` borné ; (d) jeton mal formé : jamais compté, jamais de recherche ; (e) plafond d'accès atteint → 429 pour tous les jetons, valides compris, puis reprise après la fenêtre ; (f) deux IP indépendantes, deux préfixes IPv6 /64 distincts, IPv4-mappée = IPv4 ; (g) `X-Forwarded-For` forgé ignoré (aucun contournement) ; (h) stockage en panne → 503, aucune donnée ; (i) aucune réponse 429 ne contient de donnée ni de jeton, en-têtes de protection présents ; (j) journaux sans jeton ni IP ; (k) pas de différence de corps entre « inconnu avant plafond » et « mal formé » ; (l) test de charge reproductible (script, hors CI) pour fixer la valeur du plafond d'accès.

## 14. Arbitrages à valider : A2, A3, A5, A6

### A2 — Mentions de prudence sur la copie du QR  ·  *Recommandation : OUI*
- **Question** : ajouter un rappel court rappelant que le QR n'est pas une preuve d'identité.
- **Texte proposé, trois emplacements** : (1) page de contrôle, permanent, sous le verdict : « **Rapprochez le nom et les plaques de la personne et du véhicule.** Ce QR code ne prouve pas l'identité. » ; (2) page du bénéficiaire : « Ne diffusez pas ce QR code : toute personne qui le possède peut afficher votre nom et vos plaques. » ; (3) PDF : même phrase que (2).
- **Impact** : gabarits Twig et PDF uniquement ; **aucune donnée ajoutée**, aucune décision modifiée (conforme DM-115 §7, qui prévoit déjà ce rapprochement). Tests : présence du texte, absence de toute donnée nouvelle.
- **Si refus** : le risque résiduel de DM-115 §7 reste documenté sans rappel visible.

### A3 — Vérification d'une zone ou page unique  ·  *Recommandation : page unique, zones nommées, zone valide en tête*
- **Question** : ajouter un paramètre (`?zone=…`) pour répondre « est-il valable pour la zone X » ?
- **Recommandation** : **non**. Une page, toutes les zones du laissez-passer, **les zones valides d'abord**, chacune nommée avec son état en mot et en icône. Raisons : aucune nouvelle entrée client à contrôler (règle 5 du CLAUDE.md), aucune confusion entre zone demandée et zone autorisée, aucun nouveau cas d'oracle (« la zone existe-t-elle ? »), et le contrôleur lit déjà la zone qui l'intéresse. Un paramètre de zone n'a pas de source de vérité côté contrôleur : il faudrait un référentiel de zones scannables, hors périmètre.
- **Impact** : ordre d'affichage uniquement (à confirmer par lecture du gabarit en 07.3.2). Aucune migration.
- **Si refus** : un 07.3 bis dédié (nouveau cadrage).

### A5 — Compteurs agrégés de scans  ·  *Recommandation : NON en V1*
- **Question** : DM-115 §6 les autorise (« compatibles avec la confidentialité »). Les mettre en œuvre ?
- **Recommandation** : **non**. Ils exigent une table, donc une migration (exclue par l'orientation 6), et une politique de conservation (DM-117). Le besoin (savoir si le contrôle est utilisé, détecter les abus) est couvert sans donnée : journal agrégé d'incidents de limitation (§ 13.3 point 5), journaux nginx masqués, supervision système.
- **Si accepté plus tard** : lot distinct, cadrage, migration approuvée.

### A6 — Conduite à tenir quand la vérification est indisponible  ·  *Recommandation : texte national fixe, sans configuration par événement*
- **Question** : qui rédige la consigne affichée quand la page répond 404, 410, 429 ou 503, et où la stocker ?
- **Recommandation** : **texte fixe dans les gabarits**, rédigé par nous, validé par vous, au sens commun de tous les contrôleurs : 
  - 404 : « Ce QR code n'est pas reconnu. Ne concluez pas à une fraude : demandez une autre pièce justificative et signalez le cas à votre responsable. » ;
  - 410 : « Ce QR code a été remplacé. Demandez le nouveau QR code à la personne ou contactez la commune. » ;
  - 429 et 503 : « La vérification est temporairement indisponible. Ce n'est pas un refus d'autorisation. Réessayez dans N minutes ou appliquez la procédure de terrain de votre commune. »
- **Pas de texte par événement** : il exigerait une colonne en base (migration exclue) et un écran de saisie. La « procédure de terrain » est celle de la commune, **hors de l'application** (consigne de poste, briefing) ; la présente recette ne l'invente pas.
- **Impact** : cinq gabarits Twig, tests de contenu, aucune donnée.
- **Si vous souhaitez un texte par événement** : arbitrage séparé avec migration à cadrer.

**Récapitulatif des recommandations** : A2 oui, A3 page unique, A5 non, A6 texte fixe. Décisions déjà prises, donc closes : A1, A4 (stratégie § 13), A7 (§ 15), A8 (§ 12).

## 15. Environnement HTTPS de recette : stratégie la plus simple pour notre architecture VPS

Contraintes : production cible = VPS, nginx natif, PHP-FPM, MySQL, instance unique, pas de Docker ni de Traefik ni de Redis (`ops/nginx/`). L'adresse IP du client doit rester la vraie (aucun proxy de confiance), sinon les limiteurs et leur recette perdent leur sens.

**Option recommandée : un VPS de recette jetable, installé avec le même kit `ops/nginx/` que la production, sur un sous-domaine dédié** (ex. `recette.<domaine>`), certificat Let's Encrypt, base MySQL séparée, données **fictives** uniquement.
- C'est **exactement la cible de production** : la recette valide aussi le kit nginx, les journaux masqués, `check-deployment.sh`, la vraie IP, le vrai TLS (TER-07, 07 bis, 07 ter, aujourd'hui « bloquées »).
- Aucune dépendance à un tiers de transit, aucune modification de l'architecture, aucune ligne de code spécifique à la recette.
- Détruit après la recette (le registre de recette existant, `var/recette/ledger.json`, sert au nettoyage).

Options écartées : **tunnel public (type Cloudflare Tunnel, ngrok)** — ajoute un proxy, déforme l'IP du client, fait transiter les données par un tiers, et ne valide pas notre nginx ; **réseau local + certificat auto-signé ou mkcert** — l'installation d'une autorité sur iPhone et Android est lourde, le QR n'ouvre pas sans avertissement, et rien n'est testé de la production ; **production en préproduction** — jamais, interrupteur et données réels.

**Prérequis à préparer (sans rien déployer)** :
1. Décision : machine (même hébergeur que la cible de production, plus petite offre), nom de domaine, durée de vie, personne qui l'administre. *Aucune commande n'est lancée sans votre accord explicite.*
2. `civipass.env` de recette (domaine réel de recette, certificats), `render.sh`, `check-deployment.sh` sans échec ; `APP_ENV=prod`, `APP_DEBUG=0`, `DEFAULT_URI` en `https://`.
3. Accès SMTP de recette (déjà exigé par NOT-06) ; jeu de données fictif (P2) : deux laissez-passer dont un avec plusieurs communes, deux plaques, deux zones, un événement à clore.
4. Procédure d'**activation temporaire**, **sur ce seul serveur** : `PUBLIC_VERIFICATION_ENABLED=1` uniquement dans son `.env.local`, demandée et horodatée, journalisée dans le rapport de recette, remise à `0` en fin de séance ; **les autres interrupteurs restent à 0**. Contrôle avant et après par `check-deployment.sh` et par un 404 constaté sur `/v/<jeton valide>` après remise à 0.
5. Procédure de recette écrite (scénarios et preuves, § 16) et dépôt des preuves (captures, photos) hors Git.
6. Procédure de **destruction** : arrêt, suppression de la base et des journaux de recette, révocation du certificat et suppression de l'enregistrement DNS.

Livrable du 07.3 : `docs/runbooks/recette-verification-publique.md` (procédure complète). **Le déploiement lui-même est hors lot, soumis à autorisation.**

## 16. Adaptations d'interface réellement nécessaires, et ce qui se valide automatiquement ou sur appareil

### 16.1 Adaptations retenues (révision du § 6)

| Réf. | Adaptation | Statut |
|---|---|---|
| U3 / A2 | Rappel « rapprochez le nom et les plaques » ; mentions de la page du bénéficiaire et du PDF | **Nécessaire** (si A2 validé) |
| U6 / A6 | Textes d'action sur 404, 410, 429 et 503 | **Nécessaire** (si A6 validé) |
| U7 / A3 | Zones valides d'abord, zones non valides ensuite | **Nécessaire** si l'ordre actuel ne le fait pas (lecture du gabarit en 07.3.2) |
| U1 | Verdict et plaques visibles sans défilement sur 360 × 640 | **Conditionnel** : mesuré en test de rendu (hauteurs), corrigé seulement si l'écran dépasse |
| U2 | Contraste | **Conditionnel** : calcul des contrastes des jetons du design system (clair et sombre) ; ajustement local seulement s'il est insuffisant (WCAG AA 4,5:1 ; AAA 7:1 visé pour le verdict) |
| U4 | Âge de la vérification | **Abandonné** sous sa forme « il y a N secondes » (exige du JavaScript). L'heure de vérification et le bouton Actualiser existent déjà ; on les met en évidence si la recette le demande |
| U5 | Erreur réseau | **Conditionnel**, après recette (04.3) ; par défaut **aucun script** (page sans JS conservée) |
| — | Aucune donnée affichée en plus ; aucun lien sortant ; aucune donnée en cache local | **Verrouillé par test** |

### 16.2 Ce qui se valide automatiquement

- Logique de verdict et d'états pour chaque cause : révocation, participation, commune, zone, événement suspendu puis clos ; même QR avant/après ; plusieurs communes ; purge (404).
- Liste **exacte** des champs affichés (nom, prénom, plaques, zones, périodes, référence, événement, communes) ; absence d'adresse, e-mail, téléphone, motif, identifiants internes.
- En-têtes et absence de cookie sur toutes les réponses, y compris 429 et 503 ; réponse uniforme 404 ; aucun jeton ni IP dans corps, journaux, audit.
- Limitation de débit (§ 13.4), interrupteur par défaut à `0` dans tous les fichiers d'environnement et de déploiement.
- Rendu : présence et ordre des blocs, textes A2 et A6, absence de lien sortant et de script, hauteurs des blocs critiques et contrastes calculés à partir des jetons.
- Contrôles d'exploitation : `check-deployment.sh`, rendu de la configuration nginx, tests `ops/` existants.

### 16.3 Ce qui exige un appareil réel (iPhone et Android)

Ouverture du QR par l'appareil photo natif et Google Lens (comportement du système, pas de l'application) ; certificat et ouverture HTTPS sans avertissement ; lisibilité en plein soleil et luminosité maximale ; thèmes clair et sombre forcés par le système ; **retour arrière et cache avant/arrière de Safari iOS** après une révocation ; mode avion puis retour du réseau (message d'erreur du navigateur) ; 4G dégradée ; deux téléphones sur une même passerelle 4G ; QR imprimé abîmé, photographié à l'écran, scanné à distance ; taille tactile des boutons ; débordement horizontal ; comportement du navigateur intégré de certaines applications (messagerie) ouvrant le lien. **Aucun de ces points n'est déclaré réussi sans preuve** (capture ou photo), conformément à la checklist existante.

## 17. Conditions d'activation sécurisée du contrôle public (version finale)

**Activation en recette isolée** (07.3.5) : accord explicite horodaté ; serveur dédié aux données fictives ; conditions 1 à 4 ci-dessous ; interrupteur remis à `0` en fin de séance.

**Activation en production** : **hors lot 07.3, validation explicite distincte**, après les conditions de [exploitation-verification-publique.md](exploitation-verification-publique.md) § 6 et ces compléments :
1. nginx du kit `ops/nginx/` installé, contrôles § 11 rejoués ; `check-deployment.sh` sans échec ; aucun proxy de confiance superflu (B6).
2. Valeurs de limitation arrêtées par le test de charge ; stratégie § 13 livrée ; stockage des compteurs surveillé (espace, droits `var/share`, purge du cache).
3. HTTPS valide, HSTS, `APP_ENV=prod`, `APP_DEBUG=0`, `display_errors=Off`, `DEFAULT_URI` en `https://`.
4. Recette appareils réels réussie avec preuves (§ 16.3) et rapport signé.
5. Politique de conservation, base légale, information des personnes (DM-115 §7, DM-117) et purge conforme (DM-120) **validées** ; RETENTION_PURGE_ENABLED et RIGHTS_ERASURE_EXECUTION_ENABLED pilotés par leurs propres décisions.
6. Procédure de terrain de la commune écrite et connue des contrôleurs (A6).
7. Accord explicite du responsable du projet, par environnement, consigné.

## 18. Plan d'exécution définitif

Chaque incrément : implémentation, tests ciblés (`bin/qa lint`, `bin/qa changed`), **commit local** ; `bin/qa full`, `verify-docs.sh` et `doctrine:schema:validate` en fin de lot. Aucune migration. Aucun push, aucun déploiement, interrupteurs inchangés.

| Incrément | Contenu | Livrables | Dépend de |
|---|---|---|---|
| **07.3.1** Documentaire | Fiches DM/DT pour A2, A3, A5, A6 et la stratégie de limitation (DT) ; amendement de forme de DM-115 ; feuille de route corrigée ; références mises à jour | Documents validés, `verify-docs.sh` vert | Validation du présent cadrage |
| **07.3.2** Limitation de débit | Stratégie § 13.3 : interface `VerificationThrottle` (plafond d'accès avant, compteur d'échecs consulté seulement sur « inconnu »), plafond d'accès relevé et réglable, journal agrégé ; script de test de charge | Tests § 13.4 (a à k), documentation d'exploitation § 3 et § 4 mise à jour | 07.3.1 |
| **07.3.3** Écran et textes | A2, A3 (ordre des zones), A6 (404, 410, 429, 503), mesures U1 et U2 puis corrections conditionnelles ; mentions page du bénéficiaire et PDF | Tests de rendu et de contenu, liste exacte des champs verrouillée | 07.3.1 |
| **07.3.4** Scénarios et exploitation | Scénarios automatisés complémentaires (§ 16.2), mise à jour de `exploitation-verification-publique.md`, **runbook de recette** (`docs/runbooks/recette-verification-publique.md`), checklist d'activation, grille de preuves | Documents, `bin/qa full` vert | 07.3.2, 07.3.3 |
| **07.3.5** Recette appareils réels | **Soumise à autorisation distincte** : création du serveur de recette, activation temporaire, exécution du § 16.3, rapport avec preuves, désactivation, destruction. Corrections éventuelles : U5 seulement si la recette l'exige | Rapport de recette ; anomalies classées | Autorisation d'infrastructure ; 07.3.2 à 07.3.4 |

Les incréments 07.3.1 à 07.3.4 peuvent être exécutés **sans aucun serveur ni appareil** après validation. Le 07.3.5 est le seul bloqué par une décision d'infrastructure (§ 15 point 1).

### Risques résiduels du lot
- Un QR photographié ou transmis affiche nom, prénom et plaques à son porteur : risque DM-115 §7, atténué mais non supprimé (A2).
- Un attaquant partageant l'IP peut saturer le plafond d'accès : 429 explicite et borné (§ 13.3 point 6).
- Panne du stockage des compteurs : indisponibilité du contrôle en ligne tant qu'elle dure ; la procédure de terrain est l'ultime recours.
- Le comportement du système (lecteur de QR, cache Safari) n'est vérifiable que sur appareil.

### Points à valider
1. A2, A3, A5, A6 selon les recommandations du § 14.
2. La stratégie de limitation du § 13.3, y compris le relèvement du plafond d'accès (1 500 / 10 min, valeur provisoire arrêtée par le test de charge).
3. La proposition de numérotation et d'amendement de forme de DM-115 (§ 12).
4. La stratégie de recette du § 15 (serveur jetable, même kit que la production) ; **aucune création sans autorisation**.
5. Le plan du § 18 comme mandat d'exécution des incréments 07.3.1 à 07.3.4.
