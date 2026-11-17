# LOT 09 — Contrôle hors connexion (PWA) : étude d'architecture et cadrage

**Date** : 17 novembre 2026 · **Statut** : **V1 (§§1–12) et V2 (§§13–22)**. Le **principe** (approche 1, sans QR signés) et les arbitrages A1, A2, A4, A9, A10, A15 (principe) sont **validés** le 17 nov. 2026 ; la **V2 prévaut sur la V1** là où elles diffèrent (notamment le chiffrement local : voir §13.4). Les autres arbitrages restent à valider · **Numérotation** : « LOT 09 » est proposé (les lots d'audit 09 et 10 deviendraient 10 et 11) ; à confirmer.
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


---
---

# PARTIE II — V2 : compléments de cadrage (17 novembre 2026)

**Décisions reçues** : approche 1 (synchronisation temporaire, sans QR signés) ; A2 (données limitées à la commune activatrice) ; A4 (activation par un administrateur communal, code 30 min, plafond initial 10 appareils, **sous réserve de protections contre l'usage abusif des codes**, §17) ; A9 (décodeur QR embarqué et tests JS, **après audit des dépendances**, §16) ; A10 (`OFFLINE_CONTROL_ENABLED`, défaut 0) ; A15 (principe des deux tables ; **schéma et migration à approuver avant exécution**, §18). **Non validés** : A3 (précisé §13), A6 (§14), A5, A7, A8, A11, A13, A14 (§19).
**Développement** : sur localhost, avec tests automatisés et navigateurs de développement ; **recette sur smartphones réels obligatoire avant le pilote, organisée à la fin du développement local** ; **aucun VPS créé maintenant**.

---

## 13. A3 — Modèle de confiance hors connexion (version finale proposée)

### 13.1 Paramètres
| Paramètre | Valeur par défaut | Bornes | Porté par |
|---|---|---|---|
| Seuil d'avertissement `warn` | 12 h | 1 h – `hard` | activation (§18), copié au jeu de données |
| Seuil dur `hard` | 24 h | 2 h – 24 h | idem |
| Mode d'assurance | `STANDARD` | `STANDARD` / `STRICT` (§14) | idem |
| Tolérance d'horloge | 2 min | fixe | application |

Le seuil dur ne peut **jamais dépasser 24 h** (CDC §9.2) ; une commune peut seulement le **raccourcir**.

### 13.2 Âge et verdicts
L'**âge** du jeu est `maintenant − instant de synchronisation`, mesuré avec l'horloge de l'appareil, **corrigé** par le décalage constaté à la synchronisation (§13.3). Verdicts, **mode STANDARD** :

| Âge | Laissez-passer reconnu avec fenêtre active | Reconnu mais sans fenêtre active (expiré, à venir, suspendu à la synchro) | Révoqué / remplacé connu | Jeton inconnu / hors périmètre |
|---|---|---|---|---|
| **0 – `warn`** | **Valide selon la dernière synchronisation** — orange « hors ligne », bandeau permanent | Non valide selon la dernière synchronisation | Révoqué selon la dernière synchronisation | **Indéterminé** (« inconnu de cet appareil ») |
| **`warn` – `hard`** (12–24 h) | **Valide selon des données anciennes** — orange renforcé, icône sablier, bandeau rouge-orange : « Données de plus de 12 h. Une révocation a pu échapper à cet appareil. **Vérifiez en ligne dès que possible ou contactez votre poste de commandement.** » | Non valide selon des données anciennes | Révoqué (information ancienne) | **Indéterminé** |
| **> `hard`** (> 24 h) | **Indéterminé** : « Données trop anciennes : aucune validation hors connexion. Synchronisez ou vérifiez en ligne. » | **Indéterminé** | **Révoqué (information ancienne, non actualisée)** — voir 13.5 | **Indéterminé** |

**Règles** : (1) un verdict positif hors ligne **ne comporte jamais** les mots « vérifié », « confirmé » ou « à jour » ; (2) le bandeau « Vérification hors connexion — données du JJ/MM à HH:MM (il y a N h) — ne reflète pas les modifications récentes » est **permanent et non masquable** ; (3) entre `warn` et `hard`, le verdict reste **positif mais dégradé** (c'est le sens du SFD §7.3 « avertissement renforcé ») ; la dégradation à « indéterminé » s'applique **à `hard`**, non à `warn` ; (4) tout verdict « non valide » est lui aussi **qualifié** (une prolongation postérieure à la synchronisation est invisible).

### 13.3 Horloge du téléphone modifiée
Ce qui est **mesuré** : à chaque synchronisation le serveur renvoie son heure `S` ; l'appareil lit son heure `D`, stocke `D`, `S`, `décalage = D − S` et une **« dernière heure locale vue »** `L`, mise à jour à chaque ouverture et à chaque scan.
| Situation | Détection | Verdict |
|---|---|---|
| Décalage `|D − S| > 2 min` **à la synchronisation** | oui | le jeu est reçu mais marqué **inutilisable** : « Réglez l'heure de l'appareil (automatique) puis synchronisez » → tout est **indéterminé** |
| Horloge **reculée** après synchronisation (`maintenant < L − 2 min` ou `< D − 2 min`) | oui | **indéterminé : horloge incohérente** |
| Horloge **avancée** après synchronisation | **non détectable seule** ; l'âge calculé augmente (donc l'avertissement vient plus tôt, la mort dure plus tôt) | l'âge corrigé joue en faveur de la prudence ; mais une fenêtre « à venir » peut apparaître active |
| Fuseau modifié | sans effet : tous les calculs sont en **instants UTC** | — |
| Redémarrage / mise à jour du navigateur | `L` persistant ; l'âge est relu | — |

**Limite assumée** : une horloge avancée **volontairement** peut faire paraître active une période qui commence dans les heures suivantes (≤ horizon de 24 h) ; elle ne peut pas rendre valide ce qui n'est **pas dans le jeu**. Le risque est borné par `hard`, et ne peut pas être supprimé sans connexion. Les tests simuleront reculs et avances d'horloge.

### 13.4 Correction de la V1 : chiffrement local
La V1 (§3.2, §5.1, §5.3) parlait d'un stockage « chiffré (AES-GCM, clé non extractable) » comme mesure de protection. **Cette affirmation n'est pas défendable** : une clé stockée dans le même profil de navigateur que les données ne protège pas contre une copie du profil ni contre un code exécuté dans l'origine. Elle est **retirée** ; voir §15 et A8.

### 13.5 Que faire des laissez-passer révoqués après `hard` (nouveauté, A19)
Les empreintes de laissez-passer **révoqués ou remplacés** ne sont pas des données personnelles lisibles (pas de nom) et leur état est **irréversible** (DM-116). **Recommandation** : au-delà de `hard`, l'appareil **supprime toutes les données identifiantes** (noms, plaques, fenêtres) mais **conserve les seules empreintes révoquées** jusqu'à la fin de l'événement ou la prochaine synchronisation, afin qu'un QR connu comme compromis ne devienne pas « indéterminé » mais « révoqué (information ancienne) ». Si le juriste y voit une donnée à caractère personnel (pseudonymisée), la suppression totale est le repli.

---

## 14. A6 — Révocations : garde-fous métier et procédure

**Principe non négociable** : un verdict hors ligne n'est **jamais** une confirmation en temps réel. Le risque (révocation, suspension, remplacement de QR, clôture anticipée, prolongation) est **réduit et rendu visible**, pas supprimé. Délai maximal pendant lequel une révocation reste invisible : **`min(hard, prochaine synchronisation)`**, donc **≤ 24 h** (≤ 12 h si la commune raccourcit).

### 14.1 Quatre niveaux de protection
| # | Garde-fou | Effet |
|---|---|---|
| **G1** | **Préférence au temps réel** : dès que le réseau répond (délai ~3 s), la PWA ouvre le contrôle en ligne ; le hors ligne n'est qu'un repli | la majorité des contrôles avec couverture est à jour |
| **G2** | **Durée de confiance réglable à la baisse** (2–24 h) et **resynchronisation fréquente** (à l'ouverture, toutes les 15 min en ligne) | réduit la fenêtre d'aveuglement |
| **G3** | **Mode `STRICT`** (choisi par l'administrateur à l'activation) : hors ligne, le verdict **n'est jamais positif** — l'écran affiche identité, plaques, zones « selon la dernière synchronisation » sous le verdict **« Indéterminé : confirmation en ligne requise »** ; seuls les cas négatifs connus (révoqué, expiré) restent affichés | adapté aux zones ou périodes sensibles ; le contrôleur dispose de l'information mais **doit** confirmer par ailleurs |
| **G4** | **Consigne de terrain « vérification impérativement à jour »** (ci-dessous) | traitement humain des cas où l'erreur est coûteuse |

### 14.2 Distinction contrôle ordinaire / vérification impérativement à jour (proposition)
| Situation | Traitement | Appui par l'application |
|---|---|---|
| **Contrôle ordinaire** (zone courante, données ≤ `warn`, aucun doute) | verdict hors ligne qualifié, acceptable | bandeau, âge, rapprochement nom/plaques |
| **Données entre `warn` et `hard`** | vérification en ligne **recommandée** ; sinon verdict dégradé | bandeau renforcé, bouton « Vérifier en ligne » |
| **Doute sur la personne, le véhicule ou la zone** | **escalade** : appel du poste de commandement ou contrôle en ligne ; ne pas décider sur le seul verdict hors ligne | texte de rappel sur l'écran de résultat |
| **Zone ou point « sensible »** (désigné par la commune) | **vérification en ligne obligatoire**, ou mode `STRICT` | option de mode `STRICT` ; (option **A16** : indicateur par zone, §19) |
| **Incident déclaré** (fraude, vol de QR, révocation urgente) | la commune **diffuse l'alerte hors outil** (radio, téléphone) ; **resynchronisation immédiate** demandée aux contrôleurs en ligne ; l'administrateur peut **désactiver** un appareil ou **raccourcir** la durée de confiance pour les nouvelles activations | écran « Appareils », message d'alerte lors de la synchronisation |
| **Après une longue période hors ligne** (> `warn`) | première action : **synchroniser** | l'application ouvre sur l'état de fraîcheur |

### 14.3 Procédure de révocation urgente (à intégrer au runbook)
1. L'agent révoque dans Civipass (effet **immédiat** en ligne, DM-116).
2. L'administrateur évalue la sensibilité : **alerte radio** aux postes concernés (le métier fournit le texte) et, si besoin, **désactivation** des appareils d'une zone.
3. Les appareils connectés se resynchronisent ; les autres le feront à la reconnexion ; **le délai maximal est affiché et accepté** (A6).
4. En cas de compromission d'un **QR**, le remplacement de QR (DM-113 §4) invalide l'ancien jeton en ligne ; hors ligne, l'ancien jeton reste « valide selon synchronisation » jusqu'à la synchronisation suivante.

### 14.4 Ce que le métier doit accepter ou refuser (A6, version précisée)
- **Accepter** : le délai maximal d'invisibilité (**≤ `hard`, paramétrable à la baisse**) pour les contrôles ordinaires ; la consigne d'escalade ; la possibilité du mode `STRICT`.
- **Décider** : le mode par défaut (`STANDARD` ou `STRICT`) pour le **pilote** ; la durée par défaut (12 h recommandée pour le début du pilote, 24 h ensuite) ; la liste des situations « impérativement à jour » ; le texte des bandeaux.
- **Hors périmètre technique** : la protection contre une révocation intervenue **pendant** la période d'aveuglement ne peut être obtenue que par la connexion.

---

## 15. Données personnelles sur les terminaux

### 15.1 Volumétrie (estimations à confirmer par mesure en 09.3)
Hypothèses par laissez-passer : enregistrement JSON compact : empreinte (43 octets en base64url), nom et prénom (~40), 1,5 plaque (~15), 1,5 autorisation avec 3 fenêtres UTC (~110) et libellés de zones **dédupliqués** par dictionnaire (~30), clés et séparateurs (~60) → **~300 – 450 octets**. Pire cas réaliste (10 plaques, 5 zones, 20 fenêtres) : **~4 ko**. Compression `gzip` du transfert : ×5 à ×8 environ.

| Laissez-passer | Jeu typique | Pire cas | Transfert compressé (typique) | Stockage local (IndexedDB, 1 ligne par laissez-passer) | Commentaire |
|---|---|---|---|---|---|
| **100** | ~45 ko | ~0,4 Mo | < 10 ko | < 0,1 Mo | négligeable |
| **1 000** | ~0,45 Mo | ~4 Mo | ~70–90 ko | ~0,5 Mo | confortable sur 3G |
| **10 000** | ~4,5 Mo | ~40 Mo | ~0,6–0,9 Mo (3–6 s en 3G faible) | ~5 Mo | **instantané complet toutes les 15 min** devient coûteux ; **seuil recommandé** pour passer à la synchronisation différentielle : > 2 000 laissez-passer ou > 1 Mo compressé (A13) |

Recherche : accès direct par clé (empreinte), **O(1)**, sans charger le jeu entier. Les limites de stockage navigateur (centaines de Mo) ne sont pas en jeu. **Côté serveur** : le constructeur évalue chaque autorisation avec le moteur existant ; coût proportionnel au nombre d'autorisations ; **mesure obligatoire en 09.3** pour 10 000 ; mise en cache du jeu par commune/événement invalidée par les changements de droits.

### 15.2 Conservation et suppression des données locales
| Déclencheur | Effet | Garantie |
|---|---|---|
| Synchronisation réussie | **remplacement atomique** du jeu précédent | oui |
| Âge > `hard` | suppression des **données identifiantes** (§13.5) à la prochaine **ouverture** de l'application | **pas de purge sans ouverture** : une PWA ne peut garantir d'exécution en arrière-plan (surtout sur iPhone) |
| Appareil désactivé (constaté à la synchronisation) | suppression complète | seulement **à la reconnexion** |
| Événement clos ou terminé (constaté à la synchronisation, ou fin planifiée connue localement) | suppression complète | idem pour la clôture anticipée |
| Effacement/rectification d'un laissez-passer (droits des personnes) | disparaît du jeu suivant | effet **≤ `hard`** après le changement ; à documenter dans l'information des personnes |
| Bouton « Effacer les données de cet appareil » | suppression immédiate | oui (action volontaire) |
| Désinstallation de l'application ou purge du navigateur par l'utilisateur / par iOS (≈ 7 jours sans usage hors installation) | suppression | non maîtrisée par Civipass |

**Côté serveur** : `control_activation` et `control_device` ne contiennent **aucune donnée personnelle de contrôleur** ; ils sont conservés avec le dossier de l'événement et **purgés par le même mécanisme que celui-ci** (DM-120) — à ajouter au service de purge (adaptation de code, aucune migration supplémentaire).

### 15.3 Perte ou vol d'un appareil
| Étape | Action | Limite |
|---|---|---|
| Signalement | le contrôleur prévient son poste, qui prévient l'administrateur communal | délai humain |
| Désactivation | écran « Appareils » : action **« Désactiver — perdu / volé »** (motif codé) | **agit à la prochaine connexion de l'appareil ; inefficace s'il reste hors ligne** |
| Exposition maximale | jeu **de la seule commune**, **laissez-passer valides ou à venir** seulement, nom + plaques + zones ; durée jusqu'à purge à l'ouverture | l'extraction reste possible tant que le stockage n'est pas purgé |
| Information | si le jeu contient des données de personnes, l'appréciation d'une **violation de données** (notification CNIL / personnes) relève du responsable de traitement ; le runbook donne la liste des données exposées et la période | décision juridique |
| Remédiation | l'administrateur peut **révoquer le code** et **raccourcir la durée** ; les laissez-passer ne sont **pas** remplacés en masse | — |

### 15.4 Extraction depuis le navigateur : risques réels
| Vecteur | Possible ? | Commentaire |
|---|---|---|
| Autre application installée | **non** (bac à sable du navigateur) | hors appareil « rooté » ou logiciel malveillant privilégié |
| Quelqu'un utilisant le téléphone **déverrouillé** | **oui** (ouvrir l'application ; inspecter le stockage via les outils de développement si activés) | le verrouillage de l'appareil est la protection principale |
| Débogage USB (Android) / Web Inspector (iOS) | oui avec accès au téléphone déverrouillé et appairé | idem |
| Sauvegardes de l'appareil | **à vérifier** (le contenu d'applications web installées peut être inclus dans certaines sauvegardes) ; **[M]** à mesurer pendant la recette réelle | à documenter dans l'AIPD |
| Extraction forensique du stockage de l'appareil | possible ; le chiffrement du système (Android FBE, iOS Data Protection) protège **appareil verrouillé**, pas contre un attaquant disposant du déverrouillage | hors contrôle de Civipass |
| **Script injecté dans l'origine** (XSS) | oui : les données locales sont **par origine**, pas par chemin | **la PWA partage l'origine du reste de Civipass** : une faille de script ailleurs exposerait le stockage ; recommandation : **sous-domaine dédié** pour la PWA (A17) et CSP stricte (sans script tiers) |
| Navigation privée / effacement des données du navigateur | perte des données (pas de fuite) | resynchronisation |

### 15.5 Protections réellement possibles (et celles qui ne le sont pas)
| Mesure | Effective ? | Raison |
|---|---|---|
| **Minimisation** (commune seule, valides/à venir, nom + plaques) | **oui** | réduit le contenu exposé |
| **Durée de vie courte** + purge à l'ouverture | **partielle** | la purge exige l'ouverture |
| **Empreinte à la place du jeton** | **oui** | un jeu volé ne permet pas de fabriquer un QR |
| **Désactivation à distance** | **partielle** | seulement à la reconnexion |
| **Chiffrement applicatif avec clé stockée sur l'appareil** (AES-GCM, clé « non extractable ») | **non démontrée** | la clé et les données résident dans le même profil ; **ne pas l'annoncer** |
| **Chiffrement avec clé dérivée d'un code PIN** (PBKDF2/Argon, WebCrypto) | **partielle** | protège un profil copié tant que le PIN résiste ; un PIN à 4–6 chiffres se casse hors ligne ; coût d'usage pour les agents ; **option V1.1** si l'AIPD l'exige |
| **Authentification par biométrie (WebAuthn)** pour ouvrir l'application | possible sur Android et iOS récents | conditionne l'accès **à l'interface**, pas aux fichiers ; complexité ; **hors V1** |
| **Verrouillage de l'appareil et chiffrement du système** | **oui, hors contrôle de Civipass** | **consigne obligatoire** et condition d'utilisation ; non vérifiable par une PWA |
| **Séparation par commune** | **oui** (jeu serveur par commune ; test d'isolation) | voir 15.6 |

**Ce que Civipass promet** : minimisation, durée bornée, purge à l'ouverture, désactivation à la reconnexion, isolation par commune. **Ce que Civipass ne promet pas** : l'impossibilité d'extraire les données d'un appareil déverrouillé ou compromis.

### 15.6 Séparation entre communes
- Le jeu est construit **côté serveur** à partir de l'appareil authentifié (secret d'appareil → commune et événement de l'activation) ; **aucun identifiant fourni par le client** n'est cru (règle 5).
- Un appareil = **un profil** (commune + événement) en V1 ; une nouvelle activation avec une autre commune **remplace** le profil après confirmation (purge) — alternative : 2 profils (A14).
- L'appareil ne contient **jamais** de laissez-passer d'une commune qui ne l'a pas activé ; hors périmètre = « inconnu de cet appareil ».
- Tests : appareil de A, empreintes de B → inconnues ; secret de B sur l'activation de A → refus ; `municipality_id` forgé ignoré.

### 15.7 Éléments nécessaires à l'AIPD / aux obligations RGPD (liste de travail)
1. **Description du traitement** : contrôle hors connexion de laissez-passer, copie temporaire sur terminaux.
2. **Finalité et base légale** : à qualifier par les responsables de traitement (communes) avec leur DPO ; **ne pas anticiper** (DM-117, DM-115 §7).
3. **Rôles** : communes = responsables ; CWS = sous-traitant ; **contrôleurs** (forces de l'ordre, police municipale, bénévoles) = destinataires/utilisateurs : **convention ou autorisation** à prévoir (cadre juridique du CDC §17).
4. **Données** : catégories, minimisation, exclusions (§3.2) ; absence de données sensibles.
5. **Durées** : sur terminal ≤ `hard` / événement ; côté serveur : alignées sur DM-120.
6. **Mesures de sécurité** : §15.5 (avec les limites), authentification d'appareil, isolation, journalisation sans donnée personnelle.
7. **Risques et vraisemblance** : accès illégitime (perte/vol, XSS), modification (horloge), disparition (purge iOS) ; **risque résiduel** assumé et chiffré.
8. **Droits des personnes** : information (texte du dépôt à compléter : « copie temporaire sur les appareils de contrôle »), effacement/rectification (effet ≤ `hard`, §15.2), opposition (limitée par la mission).
9. **Registre des traitements, documentation des violations** (runbook perte/vol).
10. **Consultation du DPO**, éventuelle consultation préalable de la CNIL si le risque résiduel reste élevé.

---

## 16. A9 — Audit préalable des dépendances JavaScript

**Règle** : aucune dépendance n'est ajoutée sans audit validé (règle 10). L'audit est **livrable de 09.1**, **avant tout code** de scan.

| Point d'audit | Décodeur QR | Outillage de test |
|---|---|---|
| Candidats à comparer | `jsQR` (sans dépendance), `qr-scanner` (utilise `BarcodeDetector` si présent, sinon un moteur embarqué), `@zxing/*` (plus volumineux) ; **aucun n'est retenu à ce stade** (versions, licences et maintenance à vérifier) | `node:test` natif (**zéro dépendance**) pour la logique pure ; pilotage de Chrome sans bibliothèque (protocole DevTools) pour l'e2e hors ligne ; **éviter** une chaîne lourde de développement |
| Critères | licence compatible, maintenance, taille (< 150 ko minifié souhaité), **absence de réseau** à l'exécution, absence de dépendances transitives, absence de scripts d'installation, comparaison de performance sur QR noir et blanc, **iPhone sans `BarcodeDetector`** | Node disponible sur l'hôte (v26) et dans le conteneur PHP (**version à vérifier**) ; aucune modification de l'image Docker souhaitée |
| Intégration | **copie figée et versionnée** dans `assets/vendor/`, somme de contrôle consignée, importmap locale ; **aucun CDN** ; pas de transpilation (règle 9) | tests dans `tests/Js/`, exécutés par `bin/qa` seulement si approuvé ; sinon commande séparée |
| Chaîne d'approvisionnement | version exacte, hachage, justification, plan de mise à jour | idem |

---

## 17. A4 — Protection des codes d'activation contre l'abus

**Risque principal** : un code d'activation divulgué (photo d'un écran, message transféré) donne accès au **jeu de données de la commune**. Les mesures suivantes limitent la probabilité et l'impact.

| # | Mesure | Détail |
|---|---|---|
| P1 | **Code fort, durée courte** | 12 caractères d'un alphabet de 32 (60 bits), affiché **une fois** (QR et texte), stocké **uniquement sous forme d'empreinte SHA-256** ; validité **30 min** (réglable de 10 à 60 min) |
| P2 | **Usage borné et atomique** | `uses_count < max_devices` et `expires_at > maintenant` et non révoqué, **dans une seule mise à jour conditionnelle** (comme les invitations) ; plafond initial **10**, **absolu 10** en V1 sauf décision |
| P3 | **Limiteurs** | échecs d'activation : 10 / 15 min / IP et plafond global (sans stocker d'IP en clair dans les journaux) ; réponse **uniforme** pour code inconnu, expiré, épuisé, révoqué ; échec de stockage = 503 (comme DT-055) |
| P4 | **Plafonds par commune** | au plus **3 codes actifs** simultanés et **N appareils actifs** (fixe, par exemple 50) par commune et événement ; création de code limitée par heure |
| P5 | **Habilitation et contexte** | `MUNICIPALITY_ADMIN` en authentification **complète** (jamais « se souvenir de moi », désactivé en V1) ; commune **ACTIVE**, participation **ACTIVE**, événement opérationnel ; CSRF ; confirmation explicite |
| P6 | **Visibilité et réaction** | l'administrateur voit **qui s'est activé et quand** (libellé **obligatoire** saisi par le contrôleur, plateforme grossière Android/iOS, heure) ; il peut **désactiver** un appareil et **annuler** un code ; alerte visuelle sur « appareil inattendu » (dépassement du nombre prévu) |
| P7 | **Secret d'appareil** | 256 bits, **hors de l'URL** (en-tête `Authorization`), stocké sous forme d'empreinte, jamais journalisé ; **jamais ré-affichable** ; durée de vie de l'appareil bornée (§18) |
| P8 | **Audit** | `CONTROL_ACTIVATION_CREATED / REVOKED`, `CONTROL_DEVICE_ACTIVATED / DEACTIVATED`, `CONTROL_SYNC_REFUSED` (sans code, secret ni donnée personnelle) via `AuditTrail` |
| P9 | **Option « validation manuelle »** (A18) | l'appareil s'enregistre en attente ; l'administrateur l'**approuve** avant tout jeu de données. Protège contre le vol de code au prix de la présence de l'administrateur ; **recommandé comme option par commune**, **désactivée par défaut** |
| P10 | **Rotation du secret** (optionnelle) | secret renouvelé à chaque synchronisation avec fenêtre de tolérance ; détecte le clonage d'un appareil ; complexité de reprise après coupure ; **V1.1** |

**Limite** : P1–P8 réduisent fortement l'exploitation ; **seul P9 empêche** qu'un code divulgué dans sa fenêtre de validité fournisse un jeu de données. Décision A18.

---

## 18. A15 — Schéma des deux tables (**proposition à approuver ; aucune migration créée ni exécutée**)

Conventions du dépôt : UUID v7 en `BINARY(16)`, dates en UTC (`DATETIME` côté base, `datetime_immutable` côté domaine), clés étrangères `ON DELETE RESTRICT`, empreintes en `CHAR(64) COLLATE utf8mb4_bin`, index nommés `idx_…` / `uniq_…`, noms de tables `module_entite` (ici `control_*`).

### 18.1 `control_activation`
| Colonne | Type | Null | Rôle |
|---|---|---|---|
| `id` | `BINARY(16)` | non | PK, UUID v7 |
| `municipality_id` | `BINARY(16)` | non | FK `municipality(id)` |
| `event_id` | `BINARY(16)` | non | FK `event_event(id)` |
| `code_hash` | `CHAR(64) utf8mb4_bin` | non | SHA-256 du code normalisé |
| `expires_at` | `DATETIME` | non | fin de validité du code (≤ 60 min) |
| `max_devices` | `SMALLINT UNSIGNED` | non | plafond (≤ 10 en V1, invariant du domaine) |
| `uses_count` | `SMALLINT UNSIGNED` | non | compteur atomique, défaut 0 |
| `trust_warn_seconds` | `INT UNSIGNED` | non | seuil d'avertissement |
| `trust_hard_seconds` | `INT UNSIGNED` | non | seuil dur (≤ 86 400) |
| `assurance_mode` | `VARCHAR(10)` | non | `STANDARD` / `STRICT` |
| `manual_approval` | `TINYINT(1)` | non | A18, défaut 0 |
| `created_by` | `BINARY(16)` | non | FK `identity_user(id)` |
| `created_at` | `DATETIME` | non | |
| `revoked_at` | `DATETIME` | oui | annulation avant usage complet |
| `revoked_by` | `BINARY(16)` | oui | FK `identity_user(id)` |
Index : `UNIQUE uniq_control_activation_code_hash(code_hash)` ; `idx_control_activation_scope(municipality_id, event_id, created_at)` ; index automatiques des FK. **Invariants** (domaine, testés) : `uses_count ≤ max_devices` ; `trust_warn ≤ trust_hard ≤ 86 400` ; une activation n'existe que pour une participation **ACTIVE**.

### 18.2 `control_device`
| Colonne | Type | Null | Rôle |
|---|---|---|---|
| `id` | `BINARY(16)` | non | PK |
| `activation_id` | `BINARY(16)` | non | FK `control_activation(id)` (source des paramètres de confiance) |
| `municipality_id` | `BINARY(16)` | non | FK `municipality(id)` ; **doit égaler** celui de l'activation (invariant testé) |
| `event_id` | `BINARY(16)` | non | FK `event_event(id)` ; idem |
| `label` | `VARCHAR(60)` | non | nom saisi (« Barrage Nord ») ; **aucune donnée personnelle de contrôleur** |
| `platform` | `VARCHAR(10)` | non | `ANDROID` / `IOS` / `OTHER` (indication d'assistance) |
| `secret_hash` | `CHAR(64) utf8mb4_bin` | non | SHA-256 du secret d'appareil |
| `status` | `VARCHAR(12)` | non | `PENDING` (si A18), `ACTIVE`, `DEACTIVATED` ; **l'expiration est calculée** à partir de `expires_at` |
| `activated_at` | `DATETIME` | non | |
| `expires_at` | `DATETIME` | non | fin de vie de l'appareil : min(fin effective de l'événement, activation + durée plafond à fixer) |
| `last_sync_at` | `DATETIME` | oui | |
| `last_snapshot_hash` | `CHAR(64)` | oui | `ETag` du dernier jeu servi |
| `sync_count` | `INT UNSIGNED` | non | défaut 0 |
| `deactivated_at` | `DATETIME` | oui | |
| `deactivated_by` | `BINARY(16)` | oui | FK `identity_user(id)` |
| `deactivation_reason` | `VARCHAR(12)` | oui | `LOST`, `COMPROMISED`, `END_OF_USE`, `OTHER` (codes, pas de texte libre) |
Index : `UNIQUE uniq_control_device_secret_hash(secret_hash)` ; `idx_control_device_scope(municipality_id, event_id, activated_at)` ; `idx_control_device_activation(activation_id)` ; index automatiques des FK.

### 18.3 Colonnes volontairement **absentes**
Adresse IP, agent utilisateur complet, position, identité du contrôleur, contenu du jeu de données, journaux de scan. Pas de colonne ajoutée à `permit_permit` : **instantané complet** en V1 (A13).

### 18.4 Option hors périmètre approuvé (A16)
Un indicateur `requires_live_check` sur la zone (`event_zone`) permettrait de marquer des zones « vérification en ligne obligatoire ». C'est une **colonne supplémentaire** non couverte par la validation des « deux tables » : à approuver séparément, sinon le mode `STRICT` par activation reste la seule réponse.

### 18.5 Risques et retour arrière
Nouvelles tables sans impact sur les données existantes ; sauvegarde vérifiée avant exécution locale ; retour arrière = `DROP TABLE control_device, control_activation` ; **purge** de l'événement à adapter (code) ; **aucune migration n'est exécutée avant approbation de ce schéma**.

---

## 19. Arbitrages ouverts avec recommandations

| # | Sujet | Options | Recommandation | État |
|---|---|---|---|---|
| **A3** | Durées | voir §13 | `warn` 12 h, `hard` 24 h (plafond), raccourcissables par commune ; entre 12 et 24 h : **positif dégradé** ; > 24 h : indéterminé ; horloge : §13.3 | à valider |
| **A5** | **Vocabulaire hors ligne** | (a) vert « valide » + bandeau ; (b) **orange « valide selon la dernière synchronisation »** + bandeau ; (c) pas de verdict positif | **(b)** (mode `STANDARD`) et **(c)** en mode `STRICT` ; jamais de vert plein, ni « vérifié / confirmé / à jour » ; textes fournis par le métier | à valider |
| **A6** | Révocations | §14 | G1–G4, procédure §14.3, mode par défaut à choisir pour le pilote | **à valider** |
| **A7** | **Journal des scans** | (a) aucun ; (b) journal local remonté à la reconnexion (SFD §9.3) ; (c) compteurs agrégés | **(a)** : cohérent avec DM-127 ; la détection d'abus repose sur les limiteurs et l'audit d'activation/synchronisation (sans donnée personnelle) ; (b) exigerait une base légale, une durée et une table | à valider |
| **A8** | **Protection locale** | (a) minimisation + durée + purge, **sans chiffrement applicatif** ; (b) chiffrement avec clé locale ; (c) chiffrement avec **clé dérivée d'un PIN** ; (d) biométrie WebAuthn | **(a) en V1, honnêtement documenté** ; (c) en option V1.1 si l'AIPD l'exige ; (b) **à ne pas retenir** (sécurité illusoire) ; consigne de **verrouillage de l'appareil** obligatoire | à valider |
| **A11** | **Données sur l'appareil** | nom + prénom + plaques + zones/fenêtres + état ; sans contact ni pièce ni motif ; empreinte du jeton | **confirmer** ; données des laissez-passer **valides ou à venir** de la commune seulement ; AIPD et validation juridique **avant donnée réelle** | à valider |
| **A13** | **Synchronisation** | instantané complet / différentiel | **instantané complet en V1** avec `ETag` ; bascule différentielle si > 2 000 laissez-passer ou > 1 Mo compressé (mesure en 09.3) ; fréquence **15 min application ouverte** et à l'ouverture ; pas d'arrière-plan sur iPhone | à valider |
| **A14** | **Plusieurs communes sur un appareil** | (a) un profil par appareil ; (b) plusieurs profils | **(a)** : réactiver avec une autre commune **remplace** et purge le profil (confirmation) ; un point de contrôle commun à deux communes utilise **un appareil par commune** ; (b) à reconsidérer après le pilote | à valider |
| **A16** | Indicateur « contrôle à jour obligatoire » par zone | oui (colonne) / non | **non en V1** ; mode `STRICT` suffit ; à reconsidérer | à valider |
| **A17** | **Origine de la PWA** | même origine / **sous-domaine dédié** | **sous-domaine dédié** à l'installation (isole le stockage) ; non bloquant pour le développement local | à valider |
| **A18** | Approbation manuelle des appareils | auto / approbation | **option par commune, désactivée par défaut** ; plafond P1–P8 en tout état de cause | à valider |
| **A19** | Conservation des empreintes révoquées > `hard` | oui / non | **oui** (§13.5), sous réserve de l'AIPD | à valider |

---

## 20. Incréments définitifs (proposition)

| Incr. | Contenu | Livrables | Dépend de | Migration | Taille |
|---|---|---|---|---|---|
| **09.0** | Fiches de décision (DM d'architecture et de confiance, amendements DM-115 §5, DM-116 §4), **dossier AIPD (liste §15.7)**, validation de A3, A5–A8, A11, A13, A14, A16–A19, **approbation du schéma §18** | documents | — | — | S |
| **09.1** | **Socle PWA en ligne et outillage** : audit des dépendances (§16), décodeur embarqué, `node:test`, route `/controle`, manifeste, Service Worker (coquille hors ligne), scan dans l'application, ouverture du contrôle en ligne, `OFFLINE_CONTROL_ENABLED` | application installable (localhost), aucune donnée locale | 09.0 | non | M |
| **09.2** | **Appareils** : migration approuvée (2 tables), activation (P1–P9), secret d'appareil, écran « Appareils », désactivation, audit, limiteurs | gestion complète des appareils | 09.1 | **oui** | M |
| **09.3** | **Jeu de données serveur** : constructeur sur `PermitRightsEvaluator`, fenêtres UTC, périmètre commune, empreintes, révoqués/remplacés, API de synchronisation, `ETag`, **mesure 100 / 1 000 / 10 000** | API testée sur identifiants forgés ; budget de performance | 09.2 | non | M-L |
| **09.4** | **Magasin local et verdicts** : IndexedDB, module de verdict pur (§13) testé, horloge, modes `STANDARD`/`STRICT`, bandeaux, préférence au temps réel | contrôle hors ligne fonctionnel en navigateur de développement | 09.3 | non | L |
| **09.5** | **Cycle de vie** : synchronisation auto/manuelle, purge (§15.2), désactivation, clôture, propagation d'effacement, intégration à la purge d'événement, runbook perte/vol/compromission, alerte de resynchronisation | exploitation | 09.4 | non | M |
| **09.6** | **Durcissement et tests** : scénarios de sécurité (isolation, abus de codes, horloge, rejeu), tests e2e hors ligne sur Chrome de développement, accessibilité, mesures de performance, revue du dossier AIPD | critères 1–13 et 17–18 | 09.5 | non | M |
| **09.7** | **Recette sur smartphones réels** (Android, iPhone), à la **fin du développement local** ; exige un **accès HTTPS** (autorisation d'infrastructure séparée) et la **sous-origine dédiée** | critères 14–16 | 09.6 + autorisation | non | M |

**Rappel d'ordonnancement** : 09.7 est un **prérequis du pilote** mais hors de la phase de développement local ; elle ne crée ni VPS ni déploiement tant qu'aucune autorisation n'est donnée. Les lots d'audit 08.1–08.6 restent indépendants ; 08.2 (entrée « Contrôle ») doit exposer l'entrée « Appareils ».

---

## 21. Critères d'acceptation (mise à jour de §10)
Les critères 1 à 18 de §10 sont maintenus avec les précisions suivantes : (2)–(5) selon le tableau de §13.2 ; (5) **inclut** la détection d'horloge reculée et de décalage à la synchronisation ; (8) désactivation **à la reconnexion** ; (9) isolation vérifiée **avec empreintes et secrets forgés** ; (11) **aucun** journal de scan ; nouveaux : **(19)** les protections P1–P8 de §17 sont testées (code expiré, épuisé, révoqué, limiteur, réponse uniforme, concurrence sur le dernier appareil) ; **(20)** le délai maximal d'invisibilité d'une révocation est affiché dans la consigne et accepté par écrit (A6) ; **(21)** le jeu 10 000 laissez-passer est construit et synchronisé dans le budget de performance fixé en 09.3 ; **(22)** aucune promesse de chiffrement local n'apparaît dans l'interface ni la documentation.

---

## 22. Interrupteurs, migrations, périmètre
- **Interrupteurs** : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0` inchangés ; `OFFLINE_CONTROL_ENABLED` sera créé à **0** en 09.1 (aucune route active tant qu'il est à 0).
- **Migrations** : **aucune** avant approbation du schéma §18 ; une seule migration prévue (09.2), additive.
- **Local** : aucun VPS, aucun déploiement, aucun push sans autorisation.
