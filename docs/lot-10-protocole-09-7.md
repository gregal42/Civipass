# LOT 10 / 09.7 — Protocole de recette réelle du contrôle hors connexion (iPhone et Android)

Statut : **protocole préparé, non exécuté**. Rien n'est créé, déployé ni activé par ce document. Il complète [lot-09-controle-hors-connexion-cadrage.md](lot-09-controle-hors-connexion-cadrage.md) §10 (critères 1-18), [runbooks/controle-hors-connexion.md](runbooks/controle-hors-connexion.md), [runbooks/recette-verification-publique.md](runbooks/recette-verification-publique.md) et DT-056. Scénario associé du registre : R-OFF-07, « Non exécutée — recette réelle à réaliser ».

## 0. Règles

1. **Données exclusivement fictives** : communes de test, noms, plaques et adresses synthétiques ; aucune donnée d'une commune, d'un agent ou d'un citoyen réels ; aucune plaque de tiers.
2. Un **serveur dédié et jetable**, jamais la production ni un serveur partagé, détruit à la fin (§ 9).
3. `OFFLINE_CONTROL_ENABLED=1` et `PUBLIC_VERIFICATION_ENABLED=1` **uniquement dans l'environnement de ce serveur**, avec accord horodaté ; `RETENTION_PURGE_ENABLED` et `RIGHTS_ERASURE_EXECUTION_ENABLED` restent à 0 ; `.env` et la production ne sont jamais modifiés.
4. Aucune case n'est « réussie » sans **preuve** (capture d'écran, vidéo, journal, relevé du stockage). Un test automatisé ne remplace pas un appareil réel.
5. Textes de l'application (bandeaux, consigne « Contrôle ») : **utilisés tels quels et notés** ; leur validation métier est un critère distinct (cadrage §10, critère 17).
6. Un défaut d'isolation, de confidentialité ou de permission **interrompt** la recette et est remonté immédiatement.

## 1. Prérequis d'infrastructure (à autoriser, aucun déploiement à ce stade)

| # | Besoin | Précision | Statut |
|---|---|---|---|
| I1 | **Autorisation explicite d'infrastructure** (écrite) | Hébergeur, région UE, offre, durée maximale (proposition : 7 jours), responsable de la destruction | À obtenir |
| I2 | Un serveur Linux (Debian 12/13 ou Ubuntu 22.04/24.04), **2 vCPU / 2 Go** minimum | nginx stable ≥ 1.25.1, PHP-FPM 8.4, MySQL 8.4, outils du kit `ops/nginx/` | À obtenir |
| I3 | **HTTPS avec certificat valide** (Let's Encrypt) | Obligatoire : le Service Worker et l'installation de la PWA n'existent pas en HTTP sur un appareil réel | À obtenir |
| I4 | **Sous-domaine dédié au contrôle** (DT-056, point 4 : isolation du stockage local) | Une origine distincte de l'application de gestion, qui ne sert que `/controle/` et ses ressources ; **à trancher avant** : soit un second vhost nginx sur le même serveur, soit un sous-domaine pointant sur la même application avec routes restreintes | **Décision d'architecture à prendre** |
| I5 | Nom de domaine et DNS | Ex. `recette.<domaine>` (gestion et `/v`) et `controle.recette.<domaine>` ; aucune réutilisation du domaine de production | À fournir |
| I6 | **Version déployée** | Référence Git de `develop` (hash noté) ; aucune modification de `main` | À fixer |
| I7 | **SMTP de recette** | Boîtes de test réelles pour recevoir les liens bénéficiaires et les PDF (aucun envoi hors comptes de recette) | À fournir |
| I8 | Réglage horloge serveur | NTP actif, fuseau UTC | Prérequis |
| I9 | Base dédiée, migrations **existantes** appliquées, aucune migration nouvelle | `doctrine:schema:validate` sans écart | Prérequis |
| I10 | Accès et exposition | SSH par clé uniquement ; pare-feu 22, 80, 443 ; liste des personnes ayant un accès | À fournir |
| I11 | Contrôles du kit nginx | `ops/nginx/tests/verify-nginx.sh`, `verify-rotation.sh`, `check-deployment.sh` sans échec | À rejouer |
| I12 | Destruction du serveur en fin de recette | Procédure du runbook § 11, preuve de destruction | Prérequis |

Charge de travail estimée : préparation du serveur ≈ 1 jour ; exécution ≈ 1 à 2 jours à 2 personnes (un testeur, un administrateur communal) ; rapport ≈ ½ jour. Les coûts d'hébergement sont à fournir par l'hébergeur retenu (non estimés ici).

## 2. Appareils et matériel

| Besoin | Minimum | À noter |
|---|---|---|
| iPhone | 1, récent, **Safari**, application ajoutée à l'écran d'accueil | version d'iOS, modèle, espace libre, mode économie d'énergie |
| Android | 1, récent, **Chrome** | version d'Android et de Chrome, modèle |
| Appareil ancien | 1 si possible (A12 : « matériel modeste ») | version, RAM |
| Second téléphone | 1 | pour l'administrateur communal (approbation) |
| Réseau | Carte SIM avec partage de connexion ; possibilité de mode avion | couper et rétablir le réseau |
| QR à lire | PDF imprimé en noir et blanc, écran de smartphone, papier A6 | qualité d'impression |
| Testeurs | 1 agent non technique (critère 16) | n'a que la consigne écrite |

## 3. Données fictives de recette

Une commune organisatrice, une commune invitée, une commune étrangère ; un événement actif ; **laissez-passer** couvrant : valide, expiré, à venir, zone suspendue, révoqué, remplacé (QR changé), hors périmètre (commune B pour un appareil de A), événement clos (après clôture), 1 000 laissez-passer (volumétrie, § 5 étape L). Les jeux de saisie sont ceux de [lot-10-jeux-de-donnees.md](lot-10-jeux-de-donnees.md) (adresses en `@recette.invalid`, téléphones `06 00 00 00 xx`).

## 4. Déroulé (pour chaque appareil ; une fiche par appareil au § 6)

| Étape | Action | Résultat attendu | Preuve | Cadrage |
|---|---|---|---|---|
| A. Installation | Ouvrir l'adresse de l'application de contrôle en HTTPS ; **iPhone** : Partager › « Sur l'écran d'accueil » ; **Android** : proposition d'installation ou menu › « Installer l'application » | Icône sur l'écran d'accueil ; ouverture en plein écran ; Service Worker actif ; aucune erreur de sécurité | Capture de l'écran d'accueil, version | 14 |
| B. Activation | Code d'activation (valable 30 min) saisi ou QR d'activation scanné ; nom d'appareil | Code d'appariement à 6 chiffres affiché ; **aucune donnée** reçue avant approbation | Capture | 1, 12 |
| B2. Refus | Code **expiré**, **déjà épuisé**, **erroné** | Refus uniforme, sans indication sur la cause | Captures | 1, 13 |
| C. Approbation | L'administrateur saisit le code d'appariement (second téléphone) | L'appareil se synchronise automatiquement ; jeu reçu | Capture, heure | 1, 7 |
| D. Synchronisation | Observer le premier jeu ; relancer « Synchroniser » ; 15 min application ouverte | Nombre de laissez-passer, taille, durée ; mise à jour des modifications | Relevé (taille, durée) | 7 |
| E. Scan réel | Scanner le QR d'un **PDF noir et blanc imprimé**, d'un **écran**, d'un **papier A6** ; avec la caméra **dans l'application** | Verdict en **moins de 2 s** après la lecture ; nom, plaques, zones, périodes | Vidéo avec chronomètre | 15 |
| E2. Verdicts | Valide, expiré, à venir, zone suspendue, révoqué, remplacé | Verdicts conformes (orange « valide selon la dernière synchronisation » jamais en vert plein ; révoqué / remplacé signalés) | Captures par cas | 3 |
| E3. Inconnu et hors périmètre | QR d'un autre laissez-passer non synchronisé ; QR d'une autre commune | « Indéterminé », jamais un refus | Captures | 4, 9 |
| F. Hors connexion | **Mode avion** (et non simple coupure Wi-Fi), rouvrir l'application, scanner | Bandeau « hors connexion + date » permanent ; verdicts identiques ; mode **Strict** : aucune validation positive | Captures, vidéo | 2, 3 |
| G. Veille et redémarrage | Verrouiller 30 min, 2 h, une nuit ; fermer l'application de force ; redémarrer le téléphone ; mettre à jour le navigateur si possible | Données conservées ; âge recalculé ; aucune ressaisie ; application rouverte hors connexion | Captures avant / après | 2, 5 |
| H. Horloge | Avancer de **+7 h** et de **+13 h**, reculer de 10 min, changer de fuseau, rétablir l'heure automatique | +7 h : données vieillissantes ; +13 h : indéterminé ; recul : indéterminé « horloge incohérente » ; fuseau : sans effet ; retour à l'heure auto : état normal | Captures par cas | 5 |
| I. Révocation | Révoquer un laissez-passer côté commune **pendant** que l'appareil est hors connexion | Reste affiché « valide selon la dernière synchronisation » ; **après resynchronisation** : révoqué ; délai maximal d'invisibilité ≤ 12 h (pilote) | Captures, heures | 3, 7 |
| J. Vérification en ligne | Avec réseau : scanner avec l'appareil photo natif | La page de vérification en ligne s'ouvre ; verdict en temps réel ; consigne « contrôle en ligne préféré » | Captures | 6 |
| K. Désactivation et purge | L'administrateur désactive l'appareil ; l'appareil est hors réseau puis reconnecté | Tant qu'il est hors réseau : données lisibles dans la limite de 12 h ; **à la reconnexion** : refus et **effacement local** ; événement clos : jeu vide | Captures ; inspection du stockage avant / après | 7, 8 |
| L. Limites de stockage iOS | Voir § 5 | Voir § 5 | Voir § 5 | 14, § 15 |
| M. Isolation | Appareil de A : QR de B, empreintes forgées | Aucune donnée de B reçue ni lisible | Inspection du stockage, captures | 9, 10 |
| N. Confidentialité | Inspecter le stockage local (outils de développement distants) | Aucun jeton en clair, aucun contact, aucune pièce, aucun motif ; aucun journal de scan | Relevé | 10, 11 |
| O. Agent non technique | Un agent sans formation installe et réalise son premier scan avec la **seule consigne écrite** | Réussite sans aide ; remarques notées | Chronomètre, observations | 16 |
| P. Perte / vol | Simulation : désactivation d'un appareil « perdu » | Procédure du runbook respectée | Fiche | 18 |

## 5. Limites de stockage et comportements propres à iOS (étape L)

À **mesurer sur l'appareil**, sans présumer du résultat :
1. **Volumétrie** : synchroniser 100, 1 000, 2 000 puis 10 000 laissez-passer ; noter taille du jeu, durée sur réseau faible (partage de connexion bridé), occupation du stockage local, temps de verdict (critère 15). Seuil de passage au différentiel du cadrage : > 2 000 laissez-passer ou > 1 Mo compressé.
2. **Quota** : relever l'estimation de quota du navigateur (`navigator.storage.estimate()`) sur iPhone (Safari et application installée) et Android.
3. **Persistance** : le navigateur peut effacer des données du site après une période sans usage ; vérifier si l'application **installée** et l'**onglet Safari** se comportent de la même façon, après plusieurs jours sans ouverture (une relecture à J+7 est prévue si l'appareil reste disponible).
4. **Séparation Safari / application installée** : le stockage de l'onglet et celui de l'application installée peuvent être distincts ; l'activation doit être refaite dans l'application installée ; vérifier ce qui se passe si l'agent ouvre le lien dans Safari.
5. **Navigation privée**, **mode économie d'énergie**, **mémoire faible** (application tuée en arrière-plan) : l'application doit se rouvrir sans perte ; sinon, noter la procédure.
6. **Caméra** : autorisation demandée une fois ; comportement après refus ; scan dans l'application seulement.

Tout comportement bloquant la consigne (par exemple effacement inattendu) devient une **anomalie bloquante** ; sinon limite documentée dans la consigne de terrain.

## 6. Fiche de résultats par appareil

| Champ | À remplir |
|---|---|
| Appareil, modèle, OS, navigateur et version | |
| Date, lieu, testeur, administrateur | |
| Réseau (4G / 5G / Wi-Fi / partage de connexion) | |
| Étapes A à P : résultat (Réussi / Échoué / Non exécuté), preuve (lien ou nom de fichier), anomalie liée | |
| Temps de verdict (3 mesures) | |
| Volumétrie (étape L) : taille, durée, stockage utilisé | |
| Remarques UX (lisibilité du bandeau, bouton, consigne) | |

## 7. Critères de réussite et d'arrêt

- **Réussite** : toutes les étapes A à K, M et N réussies sur **au moins un iPhone et un Android** ; critères 14 à 16 du cadrage atteints ; aucune anomalie bloquante ni majeure ouverte.
- **Arrêt immédiat** : donnée d'une autre commune reçue, jeton ou donnée de contact lisible sur l'appareil, verdict vert plein hors connexion, révocation jamais visible après resynchronisation.
- **Non évalué ici** (hors périmètre technique) : AIPD, validation juridique des textes, acceptation écrite des limites de §3.4 (critère 17).

## 8. Gouvernance

Ce protocole est soumis à validation avant tout achat, création de serveur ou activation. Il ne vaut ni autorisation d'infrastructure ni déploiement.

## 9. Fin de recette

Désactivation des interrupteurs, suppression des comptes et données fictives, **destruction du serveur** (runbook § 11), preuve de destruction jointe au rapport, effacement des applications installées sur les appareils de test.
