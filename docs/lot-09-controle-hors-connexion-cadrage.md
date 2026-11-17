# LOT 09 — Contrôle hors connexion (PWA) : étude d'architecture et cadrage

**Date** : 17 novembre 2026 · **Statut** : **cadrage soumis à validation — aucune décision de ce document n'est validée** · **Numérotation** : « LOT 09 » est proposé (les lots d'audit 09 et 10 deviendraient 10 et 11) ; à confirmer.
**Nature** : cadrage seul. Aucun développement de PWA, aucune migration, aucun déploiement, aucun VPS ; interrupteurs inchangés (`PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`). Travail exclusivement local ; aucun push sans autorisation.

**Origine** : décision métier du 17 novembre 2026 — le contrôle hors connexion devient **obligatoire en V1, avant le premier pilote**, sous forme de PWA, sans application native ni compte permanent de contrôleur. Elle **remplace** le report de la PWA (DM-115 §5, roadmap « lot ultérieur non planifié »).

**Références** : CDC §9.2–9.3, §12 ; SFD §1.1, §4.4, §7.3, écrans 36–39 / SCR-036…039, REC-008 ; DM-113, DM-115, DM-116, DM-127, DM-112, DM-119 ; DT-055 ; `security.md`, `architecture.md`, `data-model.md`, `development-roadmap.md` ; règle impérative 7 de `CLAUDE.md` (QR sans donnée personnelle).

---

## 1. Synthèse et recommandation

**Recommandation : approche 1 — synchronisation temporaire d'un jeu de données minimal sur le terminal —, avec un moteur de droits unique côté serveur, et sans QR signés en V1.**

Les raisons, détaillées au §4 :
1. **Les QR actuels ne conviennent pas à une vérification cryptographique autonome.** Un QR signé ne peut rester conforme à la règle 7 (« aucune donnée personnelle dans le QR ») **et** afficher nom et plaques (exigence DM-115) : il faudrait soit mettre des données personnelles dans le QR, soit synchroniser quand même. Il ne saurait pas non plus refléter une prolongation, un ajout de zone ou une révocation sans **changer de QR**, ce que DM-113 §3 interdit (« QR stable »). Les QR déjà imprimés devraient être réémis.
2. **Seule une copie des droits permet d'afficher identité, plaques, zones et périodes** : c'est le contenu du contrôle en ligne (DM-115 §2) et ce que le contrôleur doit rapprocher de la personne.
3. **Les droits ne sont jamais recalculés sur le téléphone** : le serveur matérialise, avec le moteur existant (`PermitRightsEvaluator`), des **fenêtres de validité explicites en UTC** par zone ; l'appareil ne fait que comparer l'heure à ces fenêtres. Aucun second moteur (récurrences, heure d'été, suspension) à maintenir en JavaScript.
4. **Le risque résiduel principal est irréductible et doit être accepté explicitement** : une révocation, une suspension ou un remplacement de QR **postérieurs à la dernière synchronisation sont invisibles hors ligne**. L'écran doit le dire à chaque scan, et la durée de confiance (12 h / 24 h) borne le risque.

**Ce que cette décision implique** (à ne pas minimiser) :
- c'est un **nouveau traitement de données personnelles sur des terminaux non maîtrisés** (nom et plaques) : analyse d'impact (AIPD) et validation juridique **avant** toute donnée réelle (hors du code) ;
- **3 tables nouvelles** (migration à approuver) ;
- **une dépendance JavaScript** (décodeur de QR embarquée, car `BarcodeDetector` est absent d'iOS) et un **outillage de test JS** : décisions de dépendance (règle 10 de `CLAUDE.md`) ;
- la **recette sur smartphones réels** (Android **et** iPhone, en HTTPS) devient un **prérequis du pilote** et non plus une opération de mise en service : ceci **contredit** la décision du 16 nov. (« développement local uniquement, aucun VPS ; recette smartphone reportée ») et appelle une autorisation distincte (§10, A12) ;
- un volume de travail estimé à **6 incréments** (§9), supérieur à 07.3.

---

## 2. Parcours utilisateur

**Acteurs** : *administrateur communal* (prépare), *contrôleur* (gendarme, policier municipal, bénévole : aucun compte), *bénéficiaire* (présente son QR).

### 2.1 Préparation (réseau disponible)
1. L'administrateur ouvre « Contrôle » › « Appareils » de **sa** commune et de l'événement ; il génère un **code d'activation** (QR + code court) : durée **30 min**, **10 appareils** par défaut (SFD SCR-036 ; valeurs provisoires, paramétrables).
2. Le contrôleur ouvre l'adresse de la PWA sur son smartphone, **installe** l'application (Android : invite ; iPhone : « Ajouter à l'écran d'accueil », guidé) et **scanne** (ou saisit) le code d'activation. Un nom d'appareil facultatif peut être saisi (« Barrage Nord »).
3. L'appareil reçoit un **secret d'appareil** (aléatoire, stocké côté serveur sous forme d'empreinte) et télécharge le **jeu de données** : état « Prêt — synchronisé le … à … ».
4. L'écran d'accueil de la PWA affiche en permanence : l'événement, la commune, l'**heure de dernière synchronisation**, la **fraîcheur** (vert < 12 h, orange 12–24 h, rouge > 24 h) et un bouton **Synchroniser**.

### 2.2 Contrôle
1. Le contrôleur ouvre la PWA et appuie sur **Scanner** ; la caméra décode le QR.
2. **Avec réseau (délai court)** : la PWA ouvre la **page de contrôle en ligne existante** (`/v/{jeton}`), temps réel. *C'est le mode préféré.*
3. **Sans réseau** (ou délai dépassé, ~3 s, pour éviter les faux « connectés ») : la PWA affiche un **verdict hors ligne** (§3.3), sous un bandeau **permanent et non masquable** : « **Vérification hors connexion — données du JJ/MM à HH:MM (il y a N h). Les révocations récentes ne sont pas visibles.** ».
4. Le contrôleur **rapproche** nom et plaques de la personne et du véhicule (même consigne que DM-125) et vérifie la zone correspondant à son point de contrôle.

### 2.3 Reconnexion, fin et incidents
- Dès que le réseau revient et que l'application est ouverte : **synchronisation automatique** (puis toutes les 15 min tant qu'elle reste ouverte ; pas de synchronisation en arrière-plan sur iPhone) ; bouton manuel.
- À la **fin de l'événement**, à l'expiration de l'appareil ou après désactivation : les données locales sont **supprimées** à la prochaine ouverture ; l'appareil devient inutilisable.
- **Téléphone perdu, volé ou compromis** : l'administrateur **désactive l'appareil** depuis la liste ; l'effet est **à la prochaine connexion de l'appareil** (pas avant : voir §5.3) ; mesures de contention : durée de vie courte des données, minimisation, chiffrement local, procédure décrite dans un runbook.
- **Aucun journal de scan** : ni local, ni remonté (DM-127 confirmé, A7).

---

## 3. Modèle de confiance

### 3.1 Principes
1. **Le serveur reste l'unique source de vérité** et l'unique moteur de droits.
2. **Le terminal n'est jamais de confiance** : il peut être perdu, cloné, son horloge modifiée.
3. **Un verdict hors ligne n'est jamais une vérification temps réel** : il est toujours qualifié et daté.
4. **L'absence de garantie se traduit par « indéterminé »**, jamais par un vert implicite.
5. **Moindre donnée** : le strict nécessaire pour rapprocher une personne (nom, plaques) et pour décider (fenêtres) ; rien d'autre.
6. **Isolation par commune** : un appareil n'obtient que les droits de la ou des communes qui l'ont activé (A2).

### 3.2 Données présentes sur l'appareil (proposition)
| Donnée | Présente ? | Remarque |
|---|---|---|
| Jeton du QR **en clair** | **non** | seule l'**empreinte SHA-256** y figure ; le jeton a 160 bits d'entropie, donc l'empreinte ne permet pas de forger un QR |
| Nom et prénom | oui (laissez-passer **valides ou à venir**) | nécessaires au rapprochement (DM-115) |
| Plaques | oui | idem |
| Libellés de zones, périodes (fenêtres UTC), commune, état | oui | pour l'affichage et la décision |
| Laissez-passer révoqués ou remplacés | **empreinte + état seulement, sans nom** | permet « révoqué » au lieu de « inconnu » |
| Adresse, e-mail, téléphone, pièces, motif | **jamais** | DM-115 §3 |
| Secret d'appareil, date de synchronisation, date d'expiration locale | oui | |

### 3.3 Verdicts hors ligne

Famille : **jamais de vert plein** ; le ton le plus favorable est « valide selon synchronisation », visuellement distinct du vert en ligne.

| Verdict affiché | Condition | Ton |
|---|---|---|
| **Valide selon la dernière synchronisation** | au moins une zone de la commune dont une fenêtre contient *maintenant*, état non suspendu à la synchro, fraîcheur ≤ 24 h | orange « hors ligne » + bandeau ; avertissement renforcé 12–24 h |
| **Non valide selon la dernière synchronisation** | tout est expiré, à venir, suspendu ou révoqué à la synchro | rouge/gris + bandeau |
| **Révoqué / remplacé selon la dernière synchronisation** | empreinte marquée | rouge |
| **Événement terminé (selon synchronisation)** | fin planifiée atteinte, ou clôture connue | gris |
| **Indéterminé** | jeton **inconnu** de l'appareil ; hors périmètre de l'appareil ; données **> 24 h** ; appareil désactivé ou expiré ; **horloge incohérente** ; jeu de données incomplet ou corrompu | **orange « indéterminé »** : aucune conclusion, **jamais un refus automatique** (CDC §9.2) |

Le texte de l'état « indéterminé » reprend le ton de DM-128 : « Ce n'est pas un refus d'autorisation… appliquez la procédure de terrain ».

### 3.4 Limites explicites (à afficher et à documenter)
| Événement | Visible hors ligne ? | Conséquence |
|---|---|---|
| Expiration naturelle (fin de période, fin d'événement planifiée) | **oui** (fenêtres connues) | traité |
| Révocation après synchronisation | **non** | le laissez-passer apparaît valide jusqu'à la synchronisation suivante |
| Suspension de zone, de participation, d'événement après synchronisation | **non** | idem |
| Clôture anticipée de l'événement | **non** | idem |
| Remplacement du QR (compromission) | **non** | l'ancien QR reste « valide » hors ligne jusqu'à synchronisation |
| Nouveau laissez-passer ou ajout de droits après synchronisation | **non** | « indéterminé » (jeton inconnu) |
| Prolongation ou ajout de zone | **non** | peut apparaître « non valide » à tort |
| Horloge du téléphone altérée | **détectée en partie** | voir §5.4 |

### 3.5 Durée de confiance (valeurs provisoires du SFD §7.3)
- ≤ 12 h : verdict avec bandeau ; 12–24 h : avertissement renforcé ; **> 24 h : aucune validation** (indéterminé) ;
- fenêtres matérialisées par le serveur **jusqu'à l'instant de synchronisation + 24 h** : l'appareil ne peut statuer au-delà de son propre horizon.

---

## 4. Étude d'architecture : trois approches

### 4.1 Tableau comparatif

| Critère | **1. Synchronisation temporaire des droits** | **2. QR signés (vérification cryptographique)** | **3. Hybride** |
|---|---|---|---|
| **Principe** | l'appareil reçoit un jeu de données minimal (empreintes de jetons + états + fenêtres) et le consulte localement | le QR contient une charge signée (jeton, événement, validité…) vérifiée avec une **clé publique** embarquée | 1 + QR signé pour prouver l'authenticité d'un jeton inconnu de l'appareil |
| **Hors connexion** | complet après synchronisation | complet **sans synchronisation préalable** pour l'authenticité et la période signée | comme 1 ; l'inconnu devient « authentique mais non vérifiable » |
| **Identité et plaques affichées** | oui (copiées) | **non** sans données personnelles dans le QR (violation de la règle 7 et de DM-113) ; sinon synchronisation | oui |
| **Durée de confiance** | bornée par la fraîcheur du jeu (12/24 h) | bornée par la validité **signée dans le QR** ; plus longue = plus risquée | comme 1 |
| **Révocations / clôtures** | visibles à la synchronisation suivante | **invisibles** sans liste de révocation, donc sans synchronisation | comme 1 |
| **Prolongation, ajout de zone, correction** | reflétés à la synchronisation | impose de **réémettre un QR** (viole DM-113 §3 « QR stable ») ou de signer une validité large (dangereux) | comme 1 |
| **QR déjà imprimés** | **compatibles** | **inutilisables** (non signés) : réémission générale | compatibles, signature en plus pour les nouveaux |
| **Gestion des clés** | secret d'appareil par terminal ; clés de chiffrement du jeu ; rotation simple | **clé de signature à long terme** à protéger (HSM / environnement), rotation, distribution de clé publique, révocation de clé | les deux |
| **Données personnelles** | PII (nom, plaques) **sur l'appareil**, limitée à la commune, courte durée | aucune sur l'appareil… **mais** pas d'identité à rapprocher | comme 1 |
| **Accès temporaire des contrôleurs** | activation par code (30 min, N appareils) → secret d'appareil | **inutile** pour la lecture (tout le monde peut vérifier) : n'**interdit pas** la vérification par un tiers non habilité | activation requise |
| **Android / iPhone** | Service Worker + IndexedDB ; iPhone : installation requise (sinon purge du stockage après ~7 j sans usage), pas de synchronisation en arrière-plan | idem pour le décodage ; vérification crypto via Web Crypto (Ed25519 récent sur iOS ; ECDSA P-256 sûr) | cumulés |
| **Performance, volumétrie** | recherche O(1) dans IndexedDB ; jeu de quelques centaines d'octets par laissez-passer : 5 000 passes ≈ 2–3 Mo | QR plus dense (signature 64 octets) : **lisibilité dégradée** sur papier noir et blanc et caméras modestes | cumulés |
| **Complexité de développement** | moyenne : synchronisation, stockage, 3 tables | élevée : signatures, rotation, réémission, impact PDF/QR/notifications | **la plus élevée** |
| **Complexité d'exploitation** | liste d'appareils, désactivation, runbook perte/vol | gestion de clé critique | les deux |
| **Migrations** | 3 tables (appareils, activations, éventuellement journal d'audit dédié) | colonnes de signature, versions de QR, réémission, table de clés | cumulées |
| **Sécurité / tests** | jeu cloisonné par commune, fraîcheur, horloge, appareil désactivé, cloisonnement | falsification, rejeu, rotation de clé, ancienne clé | cumulés |
| **Compatibilité avec décisions** | compatible (DM-113, DM-115, DM-127, règle 7) | **incompatible** avec la règle 7 si l'identité est dans le QR ; casse DM-113 §3 | partiellement |

### 4.2 Pourquoi pas les QR signés ici (réponse à « ne pas présupposer que les QR actuels sont adaptés »)
Le QR actuel est une **URL avec un jeton aléatoire de 160 bits, non signé, stocké en clair côté serveur** (DM-113). Il n'est pas « adapté » à une vérification autonome : sans copie locale des droits, **rien ne permet de distinguer un jeton valide d'un jeton fabriqué** (un jeton inconnu de l'appareil donne « indéterminé »). Un QR signé résoudrait seulement ce point d'**authenticité**, au prix de la stabilité du QR, de l'identité affichée et des documents déjà émis. L'authenticité n'est de toute façon pas le risque principal : un jeton aléatoire de 160 bits est infalsifiable ; le risque est la **perte de fraîcheur**, que seule la synchronisation traite.

### 4.3 Approche hybride : bénéfice démontré ?
Un seul bénéfice se dégage : afficher « QR authentique, droits à vérifier » pour un laissez-passer **créé après la dernière synchronisation**. Ce cas se traite plus simplement par la fréquence de synchronisation et par « indéterminé ». Le coût (clé de signature, réémission, QR plus dense) **n'est pas justifié**. Recommandation : **ne pas retenir l'approche 3 en V1**, et la réexaminer si le pilote montre un volume important de cas « jeton inconnu ».

### 4.4 Variante de livraison du verdict (écartée)
Intercepter `/v/{jeton}` par un Service Worker pour servir le verdict hors ligne **quand le contrôleur utilise l'appareil photo natif** : séduisant (aucune application), mais **fragile** (sur iPhone le stockage d'une application installée est **séparé** de celui de Safari ; la page publique est en CSP `default-src 'none'`, sans script ni cache). Retenu : **scan dans l'application**, comme le prévoit le SFD (écran 37).

---

## 5. Architecture recommandée

### 5.1 Composants
| Composant | Rôle |
|---|---|
| **Route `/controle` (PWA)** | gabarit Twig, manifeste, Service Worker (servi à la racine du périmètre `/controle/`), CSP dédiée, aucun cookie de session |
| **API d'activation** | `POST /controle/api/activation` : code → secret d'appareil (affiché une fois) |
| **API de synchronisation** | `GET /controle/api/sync` (en-tête `Authorization: Bearer <secret>`) : jeu de données de la commune, `ETag` pour « inchangé » ; limiteur de débit par appareil et par IP |
| **Constructeur de jeu de données (serveur)** | réutilise `PermitRightsEvaluator` ; produit pour chaque autorisation de la commune les **fenêtres UTC** (jusqu'à synchronisation + 24 h) et l'état ; ajoute les laissez-passer révoqués/remplacés (empreinte + état) |
| **Écran « Appareils »** (administrateur communal) | génération de codes, liste, désactivation, dernière synchronisation ; audit |
| **Stockage local** | IndexedDB : jeu de données chiffré (AES-GCM, clé **non extractable** conservée en IndexedDB) ; l'empreinte du jeton sert de clé d'index |
| **Décodeur de QR** | bibliothèque JS **embarquée** (pas de CDN), `BarcodeDetector` si disponible |

### 5.2 Choix structurants
1. **Synchronisation complète (instantané) en V1**, avec `ETag`, plutôt que différentielle : simple, sans colonne de suivi sur les laissez-passer, volume faible (§4.1). Le différentiel (curseur de révision) est une optimisation **à n'ouvrir que si** le volume dépasse un seuil à fixer (A13).
2. **Pas de moteur de droits en JavaScript** : l'appareil compare *maintenant* aux fenêtres calculées par le serveur ; les heures d'été, récurrences et nuits sont déjà résolues (DM-017, DM-021).
3. **L'empreinte du jeton, pas le jeton** ; le PDF et le QR existants restent **inchangés**.
4. **Interrupteur dédié `OFFLINE_CONTROL_ENABLED`, défaut 0** (nouveau), **indépendant** de `PUBLIC_VERIFICATION_ENABLED` : tant qu'il est à 0, les routes `/controle/*` répondent 404 uniforme (A10).
5. **Activation par code** : l'administrateur (`MUNICIPALITY_ADMIN`) de la commune ; code stocké sous forme d'empreinte, **usage borné** (durée, nombre), limiteur de débit ; l'appareil est lié à **une commune et un événement** (ou plusieurs activations).

### 5.3 Perte, vol, compromission
| Mesure | Effet | Limite |
|---|---|---|
| Désactivation par l'administrateur | l'appareil est refusé à la **prochaine** synchronisation ; ses données sont alors supprimées | **inefficace tant que le téléphone est hors ligne** |
| Expiration locale dure (24 h après la dernière synchronisation) | l'application refuse de statuer ; **purge** à l'ouverture | l'application doit être **ouverte** pour purger ; la donnée chiffrée reste sur le disque |
| Minimisation (commune seule, laissez-passer actifs, nom + plaques) | limite l'exposition | reste une donnée personnelle |
| Chiffrement du stockage avec clé non extractable | protège contre la copie simple des fichiers | **pas** contre un code exécuté dans l'application sur un téléphone déverrouillé |
| Verrouillage du téléphone (consigne) | protection principale | non vérifiable par une PWA |
| Code PIN local facultatif | friction d'accès | contournable, hors périmètre V1 si non retenu (A8) |

Principe : **le risque résiduel est documenté, pas supprimé** ; il fait partie de l'AIPD (§10).

### 5.4 Horloge et rejeu
L'appareil enregistre l'heure **serveur** à chaque synchronisation et la dernière heure locale vue ; un retour en arrière de l'horloge, ou un écart manifeste avec l'heure de synchronisation, produit **« indéterminé : horloge incohérente »**. Limite : une horloge avancée **raccourcit** la fenêtre de confiance, une horloge reculée est détectable ; la fraude par modification d'horloge reste possible avant la première synchronisation d'un nouveau jeu, d'où la fenêtre bornée à 24 h.

### 5.5 Isolation entre communes
Le jeu d'un appareil contient **uniquement** les autorisations de la commune qui l'a activé et ses zones propres. Un laissez-passer n'ayant que des droits d'autres communes est **inconnu** de l'appareil (« indéterminé : hors du périmètre de cet appareil »). Conséquence visible : **hors ligne, un appareil ne montre pas les droits des autres communes**, contrairement à la page en ligne. Arbitrage A2.

---

## 6. Incidence sur les décisions existantes (à mettre à jour)

| Décision / document | Statut actuel | Proposition |
|---|---|---|
| **DM-115 §5** (« `ControllerActivation` et `ControllerDevice` non développés… PWA hors ligne (LOT 06) à redéfinir ») | contredite pour la partie PWA | **amender** : le contrôle **en ligne** reste public et sans activation ; la PWA hors ligne est ajoutée par une nouvelle décision |
| **DM-115 §6** (aucun journal systématique des scans) | à confirmer | maintenir : aucun journal local ni distant (A7) |
| **DM-116 §4** (« l'habilitation d'appareils contrôleurs est abandonnée ») | **obsolète pour l'appareil** | **remplacer** : habilitation d'appareils par **activation temporaire**, sans compte ; l'agent connecté garde l'isolation communale |
| **DM-127** (pas de compteurs persistants de scans) | valide | maintenir ; préciser « y compris sur l'appareil » |
| **DM-113 §3** (QR stable, jeton en clair côté serveur) | valide | maintenir ; **motif du rejet de l'approche 2** |
| **DM-128** (messages nationaux fixes) | valide | **étendre** aux verdicts hors ligne (état « indéterminé ») |
| **DM-120** (clôture : liens fermés, page « terminé ») | valide | ajouter : suppression des données locales à la clôture de l'événement (à la synchronisation) |
| **DM-117** (conservation, base légale) | valide | étendre la base légale et l'information aux **copies locales** |
| **`security.md` l. 20 et §109, `architecture.md` l. 25, `data-model.md` l. 46, `development-roadmap.md` l. 49–55** | indiquent « hors V1 » / « aucune entité » | réviser après validation (nouvelles entités `ControlActivation`, `ControlDevice`) |
| **SFD / CDC** (PWA incluse en V1 ; seuils 12/24 h) | de nouveau cohérents | lever l'incohérence **I1** de l'audit |
| **Fiche d'arbitrage 4** (« état indéterminé ») | écrivait que l'orange hors ligne avait disparu | **à mettre à jour** : l'état « indéterminé » hors ligne **revient**, avec des situations plus précises (§3.3) |
| **Fiche 1** (papier 48 h) | recommandait le report | **reste valable** : le contrôle hors ligne couvre la majeure partie du besoin ; le papier n'apporte plus de valeur de contrôle |
| **Décision du 16 nov. (local uniquement, recette smartphone reportée)** | en tension | arbitrage A12 |

---

## 7. Arbitrages métier indispensables avant développement

| # | Décision à prendre | Recommandation |
|---|---|---|
| **A1** | Approche | **Approche 1** ; QR signés exclus de la V1 (réexamen après pilote) |
| **A2** | Périmètre des données par appareil | **commune activatrice seule** (isolation, minimisation) ; l'ajout d'autres communes se fait par une activation supplémentaire |
| **A3** | Durées de confiance | **12 h / 24 h** (SFD) ; fenêtres matérialisées jusqu'à +24 h ; > 24 h = indéterminé ; durée de vie d'un appareil = durée de l'événement, plafonnée (à fixer) |
| **A4** | Qui active ; paramètres | `MUNICIPALITY_ADMIN` ; **30 min** de validité du code, **10 appareils**, réglables par commune dans des bornes ; un appareil = une commune + un événement |
| **A5** | Vocabulaire hors ligne | jamais de vert plein ; « Valide selon la dernière synchronisation » orange ; « Indéterminé » ; bandeau non masquable |
| **A6** | Acceptation du décalage de révocation | **à valider avec la commune pilote et les autorités** : un laissez-passer révoqué reste valide hors ligne jusqu'à la synchronisation ; textes affichés fournis par le métier |
| **A7** | Journal des scans | **aucun** (local ni distant), conformément à DM-127 ; seuls des journaux techniques d'activation/synchronisation sans donnée personnelle |
| **A8** | Protection locale | verrouillage du téléphone (consigne) + chiffrement du stockage ; **code PIN de l'application : non retenu en V1** (à confirmer) |
| **A9** | Dépendances et outillage | décodeur QR JS **embarqué** (licence compatible, version figée, sans réseau) ; outillage de tests JS (le dépôt n'a pas de `package.json`) ; à valider (règle 10) |
| **A10** | Interrupteurs | nouvel `OFFLINE_CONTROL_ENABLED=0` ; dépendance avec `PUBLIC_VERIFICATION_ENABLED` (la PWA préfère le contrôle en ligne : à activer ensemble pour le pilote) |
| **A11** | Données sur l'appareil | nom, prénom, plaques, zones/fenêtres ; **aucune autre** donnée personnelle ; AIPD et validation juridique préalables |
| **A12** | Recette sur appareils réels | HTTPS obligatoire pour le Service Worker : un environnement HTTPS accessible depuis des smartphones (VPS dédié ou équivalent) devient nécessaire **avant le pilote** → **autorisation d'infrastructure à demander séparément** ; développement et tests simulés en local d'ici là |
| **A13** | Synchronisation | instantané complet en V1 ; seuil de volume déclenchant le différentiel ; fréquence 15 min **application ouverte** |
| **A14** | Plusieurs communes sur un même point de contrôle | activations multiples sur un même appareil, ou un appareil par commune (à trancher selon le terrain) |
| **A15** | Migration | approbation des 3 tables (§8) |

---

## 8. Migrations envisagées (à approuver au cadrage ; rien n'est exécuté)

| Table | Contenu | Remarques |
|---|---|---|
| `control_activation` | id, commune, événement, empreinte du code, expiration, nombre d'usages max / utilisés, créé par, révoqué à | code jamais en clair ; usage atomique (comme les invitations) |
| `control_device` | id, commune, événement, libellé facultatif, **empreinte du secret d'appareil**, état, activé à, expire à, dernière synchronisation, dernière empreinte du jeu, désactivé à / par | aucune donnée personnelle de contrôleur |
| (option) audit | événements `CONTROL_DEVICE_ACTIVATED/DEACTIVATED/SYNC_REFUSED` via `AuditTrail` existant | aucune table nouvelle probable |

Aucune colonne ajoutée à `permit` en V1 (instantané complet). Retour arrière : suppression des deux tables ; aucune donnée existante modifiée.

---

## 9. Découpage en incréments

| Incr. | Contenu | Livrable | Dépend de |
|---|---|---|---|
| **09.0** | Validation du cadrage, fiches DM, **AIPD / validation juridique engagée**, décisions A1–A15 | documents | — |
| **09.1** | **PWA en ligne** : `/controle`, manifeste, Service Worker (coquille hors ligne), installation, **scan dans l'application** (décodeur), ouverture du contrôle en ligne ; interrupteur `OFFLINE_CONTROL_ENABLED` | application installable, aucune donnée locale | A9, A10 |
| **09.2** | **Appareils** : migration (A15), activation, secret d'appareil, écran « Appareils », désactivation, audit, limiteurs | gestion des appareils | 09.1 |
| **09.3** | **Jeu de données serveur** : constructeur sur `PermitRightsEvaluator`, fenêtres UTC, périmètre commune, empreintes, états révoqué/remplacé, API de synchronisation, `ETag` | API testée sur identifiants forgés | 09.2 |
| **09.4** | **Verdict hors ligne** : stockage chiffré, recherche, fraîcheur 12/24 h, horloge, verdicts et bandeau, préférence au contrôle en ligne | contrôle hors ligne fonctionnel (simulé) | 09.3 |
| **09.5** | **Cycle de vie** : synchronisation automatique/manuelle, purge (expiration, désactivation, clôture), reprise après perte réseau, runbook perte/vol | exploitation | 09.4 |
| **09.6** | **Durcissement et recette** : scénarios de sécurité, matrice Android/iPhone (réelle si A12 autorisé), accessibilité, mesures de performance | critères d'acceptation | 09.5 |

Les **lots d'audit 08.1–08.6** restent indépendants (sauf 08.2 « entrée Contrôle » qui doit afficher la consigne hors ligne et l'entrée « Appareils »).

---

## 10. Critères d'acceptation du pilote

**Fonctionnels**
1. Un contrôleur sans compte active un appareil avec un code valide 30 min ; un code expiré, épuisé ou erroné est refusé uniformément.
2. Après synchronisation, un laissez-passer valide est reconnu **hors ligne** (mode avion) avec nom, plaques, zones et périodes ; le bandeau « hors connexion + date » est toujours visible.
3. Le verdict n'est **jamais un vert plein** hors ligne ; un laissez-passer expiré, à venir, révoqué ou remplacé **connu à la synchronisation** est signalé comme tel.
4. Un jeton **inconnu** ou **hors périmètre** donne « indéterminé », jamais un refus.
5. Données **> 24 h**, appareil désactivé ou expiré, horloge incohérente : aucune validation (« indéterminé »).
6. Avec réseau, le contrôle **en ligne** est utilisé (temps réel) ; le contrôle en ligne existant est inchangé.
7. À la reconnexion, la synchronisation met à jour le jeu ; les révocations deviennent visibles ; les données d'un événement clos sont supprimées.
8. Un administrateur désactive un appareil ; **à sa reconnexion**, l'appareil est refusé et purgé.

**Sécurité et confidentialité**
9. Isolation : un appareil activé par la commune A ne reçoit **aucune** donnée de la commune B (identifiants et empreintes forgés testés).
10. Aucun jeton en clair, aucun contact, aucune pièce, aucun motif n'est présent sur l'appareil.
11. Aucun journal de scan (local ou distant) ; aucune donnée personnelle dans les journaux d'activation/synchronisation.
12. Le secret d'appareil et les codes ne sont jamais journalisés ni stockés en clair côté serveur.
13. Limiteurs d'activation et de synchronisation testés ; réponse uniforme sans énumération.

**Appareils et usage** (recette réelle, A12)
14. Fonctionnement vérifié sur **Android (Chrome)** et **iPhone (Safari, application installée)** ; installation, caméra, mode avion, retour du réseau.
15. Lecture du QR d'un PDF noir et blanc et d'un écran sur matériel modeste ; temps de verdict **< 2 s** après la lecture.
16. Un agent non technique réalise l'installation et le premier scan avec la seule consigne écrite.

**Documentaire et juridique**
17. AIPD et validation juridique rendues ; textes des bandeaux validés par le métier ; limites de §3.4 acceptées par écrit.
18. Runbook « perte / vol / compromission d'un terminal » relu ; `bin/qa full` vert ; aucun interrupteur actif par défaut.

---

## 11. Risques et points de vigilance

| Risque | Gravité | Traitement |
|---|---|---|
| Faux sentiment de sécurité : laissez-passer révoqué vu valide hors ligne | **élevée** | bandeau permanent, 24 h maximum, consigne, acceptation écrite (A6) |
| Données personnelles sur terminaux perdus | élevée | minimisation, chiffrement, expiration, procédure ; AIPD |
| iPhone : stockage purgé, installation manuelle, pas de synchronisation d'arrière-plan | moyenne | consigne d'installation, resynchronisation à l'ouverture ; recette réelle |
| Décodeur QR : performances sur matériel modeste, éclairage | moyenne | mesure en 09.6 ; saisie manuelle du jeton **non proposée** (secret) |
| Complexité : JS, Service Worker et cache avec AssetMapper (URL d'actifs à empreinte) | moyenne | route dédiée générant la liste de pré-cache ; tests |
| Outillage de tests JS inexistant | moyenne | A9 |
| Conflit avec le périmètre « local uniquement » | **élevée** | A12 |
| Déploiement d'un Service Worker défectueux (difficile à rappeler) | moyenne | version du cache, interrupteur serveur, procédure de désinstallation |

---

## 12. Ce que ce cadrage ne tranche pas
Le choix du décodeur et de l'outillage de test, la valeur finale des durées, le texte juridique et les consignes de terrain, l'environnement HTTPS de recette et la politique de PIN : ils dépendent des arbitrages A1–A15. **Aucun développement ne commence avant leur validation.**
