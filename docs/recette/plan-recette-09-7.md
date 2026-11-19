# Plan de recette 09.7 — contrôle hors connexion sur smartphones réels

**Statut : plan préparé, NON exécuté.** Aucun serveur n'est créé, aucun DNS configuré, aucun interrupteur activé. L'exécution a lieu **après** l'installation HTTPS du serveur de recette (kit d'installation, jalon « serveur de recette »), sur autorisation explicite d'infrastructure.

Ce plan **complète** et ne remplace pas :
- [lot-10-protocole-09-7.md](../lot-10-protocole-09-7.md) : règles, prérequis d'infrastructure (I1–I12), déroulé A–P, limites iOS (§5), critères d'arrêt (§7), fin de recette (§9). Les étapes A–P y sont **la référence**; ce plan les détaille en pas à pas, ajoute les scénarios manquants et la fiche de relevé.
- [lot-09-controle-hors-connexion-cadrage.md](../lot-09-controle-hors-connexion-cadrage.md) §10 : critères d'acceptation 1–18 (cités « Cr. n »).
- [runbooks/controle-hors-connexion.md](../runbooks/controle-hors-connexion.md) : procédures d'exploitation (activation, révocation urgente, perte/vol).
- [runbooks/recette-verification-publique.md](../runbooks/recette-verification-publique.md) : recette du contrôle en ligne `/v/{jeton}` (07.3.5), dont la partie « appareil réel » est rejouée ici (VP-xx).
- [lot-10-plan-recette.md](../lot-10-plan-recette.md) : scénario R-OFF-07 (« smartphones réels ») clos par ce plan ; R-OFF-06 (consigne) et R-VP-08.

## 0. Règles non négociables

1. **Données exclusivement fictives** (communes, noms, plaques, adresses synthétiques). Aucune plaque de tiers, aucune donnée d'une commune, d'un agent ou d'un citoyen réels.
2. Serveur **dédié et jetable**, jamais la production ; détruit en fin de recette ([protocole §9](../lot-10-protocole-09-7.md)).
3. `OFFLINE_CONTROL_ENABLED=1` et `PUBLIC_VERIFICATION_ENABLED=1` **uniquement sur ce serveur**, avec accord horodaté. `RETENTION_PURGE_ENABLED`, `RIGHTS_ERASURE_EXECUTION_ENABLED` et `REFUSAL_NOTICE_ENABLED` restent à 0.
4. Aucune case n'est « Réussie » sans **preuve** (capture, vidéo, relevé). Un test automatisé ne remplace pas un appareil réel.
5. **Arrêt immédiat** (anomalie bloquante, recette interrompue, remontée immédiate) : donnée d'une autre commune reçue ; jeton, contact, pièce ou motif lisible dans le stockage de l'appareil ; verdict vert plein hors connexion ; révocation jamais visible après resynchronisation ; effacement local qui ne se produit pas à la reconnexion d'un appareil désactivé.
6. Textes de l'application utilisés **tels quels** et notés (la consigne « Contrôle » est « provisoire » : sa validation métier reste un critère distinct, Cr. 17).
7. Les limites acceptées (R-OFF-01, extraction depuis un téléphone déverrouillé, iOS) ne sont **pas** réévaluées ici : on mesure, on ne tranche pas.

## 1. Prérequis

| # | Prérequis | Source / remarque |
|---|---|---|
| Q1 | Serveur de recette HTTPS opérationnel, certificat valide, **deux origines** : gestion et `controle.<domaine>` (liste blanche de routes, 421 hors Host attendu) | [vhost-controle.md](../runbooks/vhost-controle.md), `ops/nginx/` |
| Q2 | Version déployée consignée (hash Git de `develop`), migrations existantes appliquées, `doctrine:schema:validate` sans écart | protocole I6, I9 |
| Q3 | `CONTROL_PWA_BASE_URL` = origine de contrôle ; `DEFAULT_URI` en HTTPS ; `check-deployment.sh` sans échec | `ops/nginx/bin/check-deployment.sh` |
| Q4 | Horloge serveur NTP, fuseau UTC | I8 |
| Q5 | Jeu de données fictives chargé (voir §3) ; interrupteurs activés avec accord horodaté | protocole §3 |
| Q6 | SMTP de recette disponible **ou** décision de s'en passer (le lien bénéficiaire et le PDF sont alors obtenus par « remise en main propre ») | plan SMTP |
| Q7 | Carte SIM avec partage de connexion, possibilité de mode avion, imprimante noir et blanc | protocole §2 |
| Q8 | Consigne écrite « Contrôle » imprimée (version provisoire notée) | Cr. 16 |
| Q9 | Outils de relevé : accès distant aux outils de développement du navigateur (Safari sur Mac / `chrome://inspect`) **pour la seule inspection du stockage** (étapes N, L) ; chronomètre ; capture vidéo d'écran | — |

## 2. Rôles

| Rôle | Tâches |
|---|---|
| **Testeur** (T1) | Manipule les téléphones, relève résultats et temps. |
| **Administrateur communal fictif** (A1) | Crée codes d'activation, approuve, révoque, désactive. Utilise un **second téléphone ou un poste**. |
| **Agent non technique** (T2) | Ne dispose que de la consigne écrite (VP-O). |
| **Responsable de recette** | Consigne les preuves, tranche « arrêt immédiat », signe la fiche. |
| **Observateur serveur** (peut être T1) | Relève les journaux applicatifs (`app:ops:status`, audit `CONTROL_*`) ; vérifie qu'aucun jeton/secret n'y figure. |

## 3. Matrice des appareils (à compléter avant exécution)

| ID | Appareil | OS / version | Navigateur / version | Mode d'usage | RAM / espace libre | Obligatoire |
|---|---|---|---|---|---|---|
| D1 | iPhone récent | iOS ____ | Safari ____ | **Application installée** (écran d'accueil) | ____ | **Oui** |
| D1b | iPhone (même) | idem | Safari, **onglet** (non installé) | Onglet | — | Oui (étape L4) |
| D2 | Android récent | Android ____ | Chrome ____ | Application installée | ____ | **Oui** |
| D3 | Appareil ancien/modeste (A12 ou équivalent, ≤ 3 Go RAM) | ____ | ____ | Installée | ____ | Si disponible (Cr. 15) |
| D4 | Second téléphone ou poste | — | navigateur ____ | Administration | — | Oui (approbation) |

Consigner pour chaque appareil : modèle, version exacte de l'OS et du navigateur, mode économie d'énergie (état), verrouillage d'écran (activé : obligatoire), langue, fuseau.

**Données fictives** (étendent [protocole §3](../lot-10-protocole-09-7.md)) : commune organisatrice A, commune invitée B, commune étrangère C ; un événement actif ; laissez-passer : **LP-VALIDE**, **LP-EXPIRE**, **LP-AVENIR**, **LP-ZONE-SUSP** (zone suspendue), **LP-REVOQUE**, **LP-REMPLACE** (ancien QR), **LP-B** (hors périmètre pour A), **LP-CLOS** (événement clôturé ultérieurement), **LP-REVOC-LIVE** (réservé à l'étape I), + lot de 1 000 (volumétrie, étape L). QR en trois supports : **PDF noir et blanc imprimé**, **écran de smartphone**, **papier A6**. Deux QR volontairement abîmés (pli, reflet) pour le scénario lumière.

## 4. Convention des tableaux

- **Gravité** d'un échec : **Bloquante** (arrêt, §0.5, ou critère d'acceptation 1–14 non atteint) · **Majeure** (fonction dégradée sans contournement, ou critère 15–16 non atteint) · **Mineure** (confort, texte, lisibilité) · **Limite documentée** (comportement propre à la plateforme à inscrire dans la consigne de terrain).
- **Résultat** : Réussi / Échoué / Non exécuté (motif obligatoire).
- **Preuve** : nom de fichier (`D1-C03-capture.png`) ou référence du relevé. Convention de nommage : `<appareil>-<ID scénario>-<n>`.
- Colonne « Réf. » : étape du [protocole](../lot-10-protocole-09-7.md) (A–P) et critère du cadrage (Cr.).

## 5. Scénarios

Chaque scénario est joué sur **D1 et D2** (D3 si disponible) sauf mention. Pas à pas numérotés.

### 5.1 Installation de la PWA (C01–C03) — protocole A, Cr. 14

**C01 — Ouverture et installation**
1. Sur le téléphone, ouvrir `https://controle.<domaine>/controle/` dans Safari (D1) / Chrome (D2). *Attendu* : page chargée sans alerte de certificat ; aucun cookie posé (relever via inspecteur) ; pas de message d'erreur.
2. D1 : Partager › « Sur l'écran d'accueil ». D2 : proposition d'installation ou menu › « Installer l'application ». *Attendu* : icône Civipass sur l'écran d'accueil.
3. Ouvrir depuis l'icône. *Attendu* : plein écran, Service Worker actif (inspecteur : `navigator.serviceWorker.controller` non nul), aucune erreur console de sécurité.
4. Relever `navigator.storage.estimate()` (quota/usage) et `navigator.storage.persisted()`.

*Preuve* : capture de l'icône, capture plein écran, relevé quota. *Réussite* : 1–3 conformes. *Échec* : installation impossible, page d'erreur, cookie posé → **Bloquante** (impossible d'installer) ; icône générique ou nom tronqué → **Mineure**.

**C02 — Coquille hors réseau après installation**
1. Installation faite (C01), réseau actif, ouvrir l'application une fois.
2. Mode avion, fermer l'application de force, la rouvrir. *Attendu* : la coquille s'ouvre (pas de page « Safari ne peut pas se connecter »), bandeau « hors connexion » visible.

*Preuve* : capture. *Échec* → **Bloquante** (Cr. 2).

**C03 — Isolation d'origine**
1. Depuis l'origine de contrôle, tenter d'ouvrir une route de gestion (`/communes/...`, `/admin`, `/connexion`). *Attendu* : 404 générique, aucune redirection vers la gestion, aucun cookie.
2. Depuis l'origine de gestion, tenter `/controle/`. *Attendu* : refus (G3).

*Preuve* : captures/journal Nginx. *Échec* → **Majeure** (**Bloquante** si une page de gestion est servie sur l'origine de contrôle).

### 5.2 Activation et approbation (C04–C08) — protocole B, B2, C, Cr. 1, 12, 13

**C04 — Activation par QR**
1. A1 (D4) : page de l'événement › Contrôle hors connexion › « Créer un code d'activation » (mode Standard, 1 appareil, durée de confiance 12 h, avertissement 6 h). Le code ne s'affiche qu'une fois ; ne pas le copier ailleurs que pour la recette.
2. T1 scanne le QR d'activation dans l'application installée (ou saisit le code), nomme l'appareil `D1-iPhone`.
3. *Attendu* : code d'appariement à 6 chiffres affiché ; **aucune donnée de laissez-passer** reçue (inspecter le stockage : aucun jeu).

*Preuve* : capture, relevé stockage. *Échec* : donnée reçue avant approbation → **Bloquante**.

**C05 — Approbation**
1. A1 lit le code d'appariement **à l'écran du téléphone**, le saisit dans « Approuver… ».
2. *Attendu* : l'appareil se synchronise tout seul ; nombre de laissez-passer, date de dernière synchronisation affichés. Relever heure d'approbation et durée jusqu'au premier jeu.

*Preuve* : capture + heures. *Échec* → **Bloquante** (Cr. 1).

**C06 — Refus uniformes**
1. Générer puis tenter : code **expiré** (attendre 30 min ou durée raccourcie en recette), code **déjà épuisé**, code **erroné**.
2. *Attendu* : message **identique** dans les trois cas, sans indication de cause (Cr. 13).

*Preuve* : trois captures comparées. *Échec* (message distinct) → **Majeure** (énumération).

**C07 — Code d'appariement erroné ou périmé**
1. A1 saisit un code d'appariement faux. *Attendu* : refus, aucune donnée envoyée, limiteur d'échecs respecté.

*Échec* → **Bloquante** si des données sont envoyées.

**C08 — Activation dans Safari puis dans l'application installée (iPhone)** — protocole L4
1. D1b : activer dans un onglet Safari. Puis ouvrir l'application installée. *Attendu* : constater si le stockage est partagé ou séparé et ce que voit l'agent (activation à refaire, message clair).
2. Noter la procédure à inscrire dans la consigne.

*Résultat* : **Limite documentée** si le stockage est séparé et que le message est clair ; **Majeure** si l'application affiche un état trompeur (par exemple « prêt » sans données).

### 5.3 Synchronisation (C09–C11) — protocole D, Cr. 7

**C09 — Premier jeu et resynchronisation**
1. Après C05, relancer « Synchroniser ». *Attendu* : réponse « inchangé » rapide ; nouveau laissez-passer créé côté commune puis synchronisation → présent hors ligne.
2. Laisser l'application ouverte 15 min : synchronisation automatique (≈ toutes les 15 min). Relever taille et durée du jeu.

*Preuve* : relevé (taille, durée, nombre). *Échec* → **Majeure** (**Bloquante** si la mise à jour n'arrive jamais).

**C10 — Volumétrie (étape L1)**
1. Jeux de 100, 1 000, 2 000, puis 10 000 laissez-passer sur réseau bridé (partage de connexion). Relever taille compressée, durée de synchronisation, occupation du stockage, temps de verdict.
2. Seuil du différentiel : > 2 000 laissez-passer ou > 1 Mo compressé (cadrage).

*Réussite* : synchronisation aboutit ; temps de verdict < 2 s (C15). *Échec* : synchronisation tronquée, mémoire saturée, application tuée → **Majeure** (D3 : **Limite documentée** si le matériel modeste est hors cible).

**C11 — Intégrité**
1. Couper le réseau en cours de synchronisation (mode avion à mi-transfert). *Attendu* : l'ancien jeu reste intact et utilisable ; la prochaine synchronisation reprend.

*Échec* (jeu corrompu ou vide) → **Bloquante**.

### 5.4 Contrôle en ligne (C12–C13) — protocole J, Cr. 6

**C12 — Scan natif avec réseau**
1. Appareil photo natif sur un QR valide (PDF imprimé). *Attendu* : ouverture de `https://<domaine>/v/{jeton}` dans le navigateur ; verdict en temps réel ; périmètre minimal (nom, prénom, plaques, zones, validité) ; consigne « contrôle en ligne préféré ».
2. Scans de LP-EXPIRE, LP-AVENIR, LP-REVOQUE, jeton inconnu : verdicts conformes, jeton inconnu = réponse neutre (VP-02).

*Preuve* : captures par cas. *Échec* → **Bloquante** (Cr. 6).

**C13 — Cache avant/arrière et historique** (VP-14)
1. Après un verdict, utiliser « page précédente » puis « suivante » (Safari : geste de balayage). *Attendu* : aucune donnée périmée servie depuis le cache (`Cache-Control: no-store`) ; la page est rechargée ou redemande le serveur.
2. Révoquer côté commune, revenir en arrière dans l'historique. *Attendu* : le verdict affiché n'est **pas** l'ancien « valide ».
3. Noter que l'URL (porteuse du jeton) est dans l'historique du navigateur (risque **accepté**, [ecarts-techniques-connus.md](../ecarts-techniques-connus.md)).

*Échec* du point 2 → **Bloquante** (verdict périmé présenté comme valide) ; point 3 : **Limite documentée**.

### 5.5 Scan réel et verdicts hors connexion (C14–C18) — protocole E, E2, E3, F, Cr. 2–5, 15

**C14 — Scan dans l'application, conditions réelles**
1. Réseau actif puis **mode avion** (pas seulement la coupure Wi-Fi), rouvrir l'application. Autoriser la caméra (noter le comportement de la demande d'autorisation et du refus).
2. Scanner : PDF noir et blanc imprimé, écran de smartphone (luminosité basse puis haute), papier A6, QR abîmé (pli, reflet), en lumière forte, en lumière faible, avec/sans flash. Chronométrer **3 mesures** de la lecture jusqu'au verdict.
3. *Attendu* : verdict < 2 s après la lecture (Cr. 15), nom, plaques, zones, périodes ; bandeau « hors connexion + date de dernière synchronisation » **toujours visible**.

*Preuve* : vidéo avec chronomètre. *Échec* : > 2 s sur D1/D2 → **Majeure** ; sur D3 → **Limite documentée** (repli jsQR/zxing-wasm = décision séparée) ; QR non lisible dans des conditions normales → **Majeure**.

**C15 — Matrice des verdicts**
1. Hors connexion, scanner LP-VALIDE, LP-EXPIRE, LP-AVENIR, LP-ZONE-SUSP, LP-REVOQUE, LP-REMPLACE (ancien QR), LP-B, jeton inconnu.
2. *Attendu* : valide = **orange** « valide selon la dernière synchronisation » (**jamais vert plein**, Cr. 3) ; expiré / à venir / suspendu / révoqué / remplacé connus à la dernière synchronisation = signalés comme tels ; LP-B et jeton inconnu = « **indéterminé** », jamais un refus (Cr. 4).
3. Mode **Strict** : activer, répéter LP-VALIDE : aucune validation positive (« confirmation en ligne requise »).

*Échec* : vert plein, ou refus affiché pour un inconnu → **Bloquante**.

**C16 — Agent non technique** (protocole O, Cr. 16)
1. T2, sans aide, avec la seule consigne écrite : installe, active (avec A1), réalise son premier scan.
2. Relever la durée, les blocages, les questions posées, la lisibilité du bandeau et des couleurs (daltonisme : le texte doit suffire).

*Échec* (réussite impossible sans aide) → **Majeure** ; remarques de texte → **Mineure**.

### 5.6 Expiration et durée de confiance (C17–C19) — Cr. 5

Paramètres du pilote : avertissement **6 h**, seuil dur **12 h** (plafond `CONTROL_TRUST_HARD_MAX_SECONDS`). Pour ne pas attendre 12 h, la recette emploie une durée de confiance **raccourcie** (réglable par la commune, jamais rallongée) **et** un cycle réel de 6 h/12 h sur au moins un appareil par plateforme (nuit).

**C17 — Âge < 6 h** : scanner LP-VALIDE : orange normal, âge affiché. 

**C18 — Entre 6 h et 12 h** : *attendu* « données anciennes » (avertissement visible), verdict toujours orange avec âge. Mesure réelle : synchroniser à 18 h, vérifier à 00 h 30 (≥ 6 h) et à 06 h 30.

**C19 — Au-delà de 12 h** : réseau coupé, vérifier après 12 h 30. *Attendu* : « **indéterminé** » (aucune validation) ; au retour du réseau, resynchronisation puis retour à l'état normal.

*Preuve* : captures horodatées avant/après chaque seuil ; relevé de l'âge affiché. *Échec* : validation positive au-delà du seuil dur, ou avertissement absent → **Bloquante** (au-delà du seuil) / **Majeure** (avertissement).

### 5.7 Révocation (C20–C22) — protocole I, K, Cr. 7, 8

**C20 — Révocation pendant l'absence de réseau** (R-OFF-01 mesuré)
1. Appareil hors réseau, jeu à jour, LP-REVOC-LIVE affiché orange.
2. A1 révoque LP-REVOC-LIVE côté commune (heure H0).
3. Hors réseau : le scan reste « valide selon la dernière synchronisation » (**comportement attendu et documenté**, pas un défaut).
4. Réseau rétabli, synchronisation (heure H1). *Attendu* : « révoqué » ; relever H1 − H0. Le délai maximal d'invisibilité est ≤ 12 h au pilote.

*Preuve* : captures et heures H0/H1. *Échec* : jamais révoqué après resynchronisation → **Bloquante** (arrêt).

**C21 — Remplacement du QR** : remplacer le QR de LP-REMPLACE côté commune ; hors réseau l'ancien reste orange (limite connue) ; après synchronisation, l'ancien QR est signalé « remplacé », le nouveau valide.

**C22 — Désactivation d'un appareil (perte/vol simulé)** — protocole K, P
1. A1 désactive D1 (motif « Perdu ou volé »), D1 hors réseau.
2. Hors réseau : données lisibles dans la limite de 12 h (limite documentée, protocole §3.4).
3. Réseau rétabli, ouverture de l'application. *Attendu* : refus et **effacement local** (inspecter le stockage avant/après : vide). Appliquer le runbook « Perte, vol ou compromission » (§4) et relever sa faisabilité.
4. Événement clos : appareil reconnecté → jeu vide, message « événement terminé ».

*Échec* : données encore présentes après reconnexion → **Bloquante**.

### 5.8 Réseau et cycle de vie de l'application (C23–C26) — protocole F, G

**C23 — Perte puis retour du réseau**
1. Application ouverte et synchronisée ; basculer en mode avion ; scanner ; rétablir le réseau (Wi-Fi, puis 4G seule).
2. *Attendu* : bandeau « hors connexion » apparaît à la coupure et disparaît au retour ; synchronisation automatique à la reconnexion (ou à la prochaine échéance de 15 min), sans ressaisie ; réseau instable (coupures répétées) : pas de blocage ni de boucle.

*Échec* : bandeau absent ou persistant à tort → **Majeure** ; boucle ou blocage → **Majeure**.

**C24 — Fermeture et réouverture**
1. Verrouiller l'écran 30 min, 2 h, une nuit ; fermer de force ; redémarrer le téléphone ; mettre le navigateur à jour si possible.
2. *Attendu* : données conservées, âge **recalculé** à la réouverture, aucune ressaisie ; application rouverte hors connexion fonctionnelle.

*Échec* : données perdues sans cause (hors C25) → **Majeure** (anomalie bloquante pour la consigne si l'effacement est inattendu, [protocole §5](../lot-10-protocole-09-7.md)).

**C25 — Purge de stockage iOS** (protocole L3, DT-C3)
1. D1 (installée) et D1b (onglet) : activer, synchroniser, ne pas ouvrir pendant 1, 3 et 7 jours (relectures J+1, J+3, J+7 si l'appareil reste disponible).
2. *Attendu* : consigner l'état à chaque relecture (données présentes ? activation à refaire ? message affiché ?). Comparer installée et onglet.

*Résultat* : l'effacement par le système est une **Limite documentée** si l'application l'annonce clairement (activation à refaire) et si la consigne l'indique ; **Majeure** si l'état affiché est trompeur (« données à jour » sans données).

**C26 — Mode économie d'énergie, mémoire faible, navigation privée** (protocole L5)
1. Mode économie d'énergie activé : synchronisation et scan ; ouvrir de nombreuses applications pour forcer l'arrêt en arrière-plan ; rouvrir.
2. **Navigation privée** : ouvrir le lien de contrôle en mode privé. *Attendu* : message clair si l'activation ou le stockage est impossible ; aucune perte silencieuse. (La navigation privée n'est **pas** une condition du contrôle mais est conseillée sur appareil partagé pour le contrôle **en ligne**.)

*Échec* : perte silencieuse → **Majeure** ; refus avec message clair → **Limite documentée**.

### 5.9 Horloge (C27) — protocole H, DT-C4, Cr. 5

1. Réglages › date et heure : avancer de **+7 h** → scanner ; **+13 h** → scanner ; reculer de **10 min** → scanner ; changer de **fuseau** → scanner ; rétablir l'heure automatique → scanner.
2. *Attendu* : +7 h = données vieillissantes (avertissement) ; +13 h = **indéterminé** ; recul = indéterminé « horloge incohérente » ; fuseau = sans effet ; retour automatique = état normal.
3. Application **fermée** pendant l'avance : noter que l'avance volontaire n'est pas détectable (**Limite documentée**, bornée à 12 h).
4. Mettre l'appareil en veille 30 min : comportement de `performance.now()` en veille (relever si l'âge dérive).

*Preuve* : captures par cas. *Échec* : validation positive après recul ou +13 h → **Bloquante**.

### 5.10 Confidentialité du stockage local (C28–C29) — protocole M, N, Cr. 9–11

**C28 — Inspection du stockage** (D1/D2 en inspection distante)
1. Relever IndexedDB, Cache Storage, localStorage, cookies. *Attendu* : **aucun** jeton en clair, aucun contact, aucune pièce, aucun motif ; aucun journal de scan ; secret d'appareil non lisible en clair dans les journaux serveur.
2. Vérifier que les fichiers de la coquille (ressources) ne contiennent aucune donnée personnelle.

*Échec* → **Bloquante** (arrêt).

**C29 — Isolation entre communes**
1. Appareil activé par A : scanner LP-B (commune B) ; tenter avec des identifiants/empreintes forgés (via l'inspecteur ou une requête manuelle). *Attendu* : aucune donnée de B reçue ni lisible, « indéterminé ».

*Échec* → **Bloquante** (arrêt).

### 5.11 Plateforme et divers (C30–C32)

**C30 — Caméra** : première autorisation ; refus puis ré-autorisation par les réglages ; comportement si la caméra est utilisée par une autre application. *Échec* sans message de récupération → **Majeure**.

**C31 — Réseau mobile dégradé** : synchronisation sur 3G bridée/partage de connexion faible ; échec propre avec message ; pas de double activation.

**C32 — Mise à jour de l'application** : déployer une version corrigée du serveur (ou un bump de cache du Service Worker) : l'appareil récupère la nouvelle coquille à la prochaine ouverture, **sans perdre l'activation ni les données**. *Échec* (activation perdue) → **Majeure**.

## 6. Synthèse de couverture

| Critère du cadrage | Scénarios |
|---|---|
| Cr. 1 activation, refus uniformes | C04–C07 |
| Cr. 2, 3 hors ligne, jamais vert plein | C02, C14, C15 |
| Cr. 4 inconnu / hors périmètre | C15, C29 |
| Cr. 5 durée, horloge | C17–C19, C27 |
| Cr. 6 en ligne inchangé | C12, C13 |
| Cr. 7, 8 resynchronisation, désactivation | C09, C20–C22 |
| Cr. 9–11 isolation, confidentialité | C28, C29 |
| Cr. 13 limiteurs | C06, C07 (et automatisé) |
| Cr. 14 appareils réels | C01–C32 |
| Cr. 15 temps < 2 s | C10, C14 |
| Cr. 16 agent non technique | C16 |
| Cr. 17 textes, AIPD | **hors périmètre technique** (validations externes) |
| Cr. 18 runbook perte/vol | C22 |

## 7. Critères de réussite et d'arrêt

- **Réussite** : tous les scénarios C01–C15, C20–C22, C28, C29 réussis sur **au moins un iPhone (installé) et un Android** ; Cr. 14–16 atteints ; aucune anomalie **Bloquante** ni **Majeure** ouverte ; limites documentées reportées dans la consigne de terrain.
- **Arrêt immédiat** : §0.5.
- **Anomalies** : chaque échec crée une entrée dans le registre des anomalies (preuve, gravité, appareil, version) ; les corrections font l'objet de sous-lots séparés (jamais corrigées pendant la recette).
- **Hors périmètre** : AIPD, textes juridiques, acceptation écrite de R-OFF-01 (Cr. 17).

## 8. Fiche de relevé par appareil (vierge)

| Champ | À remplir |
|---|---|
| Appareil (ID, modèle, OS, navigateur et versions) | |
| Mode d'usage (installée / onglet) ; économie d'énergie ; verrouillage d'écran | |
| Date, lieu, testeur, administrateur, observateur | |
| Version déployée (hash Git), serveur, origines testées | |
| Réseau (4G / 5G / Wi-Fi / partage de connexion), débit relevé | |
| Quota de stockage (`storage.estimate`), persistance (`persisted()`) | |
| Temps de verdict (3 mesures, par support : imprimé, écran, A6) | |
| Volumétrie (100 / 1 000 / 2 000 / 10 000) : taille, durée, stockage | |
| Remarques UX (bandeau, couleurs, bouton, consigne) | |

| ID | Scénario | Résultat (Réussi / Échoué / Non exécuté) | Preuve (fichier) | Gravité si échec | Anomalie liée | Remarques |
|---|---|---|---|---|---|---|
| C01 | Ouverture et installation | | | | | |
| C02 | Coquille hors réseau | | | | | |
| C03 | Isolation d'origine | | | | | |
| C04 | Activation par QR | | | | | |
| C05 | Approbation | | | | | |
| C06 | Refus uniformes | | | | | |
| C07 | Appariement erroné | | | | | |
| C08 | Safari / installée | | | | | |
| C09 | Premier jeu, resynchronisation | | | | | |
| C10 | Volumétrie | | | | | |
| C11 | Intégrité (coupure en cours) | | | | | |
| C12 | Scan natif en ligne | | | | | |
| C13 | Cache avant/arrière | | | | | |
| C14 | Scan réel (lumière, supports) | | | | | |
| C15 | Matrice des verdicts, Strict | | | | | |
| C16 | Agent non technique | | | | | |
| C17 | Âge < 6 h | | | | | |
| C18 | 6 h – 12 h | | | | | |
| C19 | > 12 h | | | | | |
| C20 | Révocation hors réseau | | | | | |
| C21 | Remplacement du QR | | | | | |
| C22 | Désactivation, effacement | | | | | |
| C23 | Perte / retour du réseau | | | | | |
| C24 | Fermeture / réouverture | | | | | |
| C25 | Purge de stockage iOS (J+1/3/7) | | | | | |
| C26 | Économie d'énergie, privée | | | | | |
| C27 | Horloge | | | | | |
| C28 | Stockage local | | | | | |
| C29 | Isolation entre communes | | | | | |
| C30 | Caméra | | | | | |
| C31 | Réseau mobile dégradé | | | | | |
| C32 | Mise à jour de l'application | | | | | |

**Signatures** : testeur ______ · administrateur ______ · responsable de recette ______ · date ______.

## 9. Fin de recette

Désactivation des interrupteurs, suppression des comptes et données fictives, effacement des applications installées sur les appareils de test (et de leur stockage), destruction du serveur avec preuve ([protocole §9](../lot-10-protocole-09-7.md)). Résultats reportés dans [lot-10-registre-recette.md](../lot-10-registre-recette.md) (R-OFF-07, R-VP-08) et, pour les écarts, dans le registre des anomalies.
