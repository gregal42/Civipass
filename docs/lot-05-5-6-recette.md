# LOT 05.5.6 — Recette transversale : matrice, anomalies, recettes restantes, proposition de clôture

**Statut : recette exécutée sur ce qui est exécutable dans cet environnement (10 et 11 novembre 2026) ; **clôture conditionnelle du LOT 05.5 VALIDÉE le 11 novembre 2026** ; décisions D1 et D2 prises (§6) ; recettes manuelles de terrain NON exécutées et obligatoires avant la production ; 5 anomalies corrigées. Aucun commit, aucun staging, aucune migration, aucune modification d'infrastructure ; `PUBLIC_VERIFICATION_ENABLED=0` en dehors des tests isolés.**

Périmètre : opérations des LOT 05.5.1 à 05.5.5 et contrôles publics du LOT 05.4.4. Document de référence pour la clôture du LOT 05.5. Entrées : [lot-05-5-3-cadrage.md](lot-05-5-3-cadrage.md) §18, [lot-05-5-4-cadrage.md](lot-05-5-4-cadrage.md) §18, [lot-05-5-5-cadrage.md](lot-05-5-5-cadrage.md) §15 et §16.

## 1. Méthode et vocabulaire des statuts

Chaque scénario porte : préconditions, actions, résultat attendu, **résultat observé**, **preuve**, **mode** et **statut**.

| Mode | Signification |
|---|---|
| **A** | Automatisé : test PHPUnit exécuté avec les vrais services et MySQL (scénario de bout en bout ou test ciblé) |
| **S** | Semi-réel : exécuté pour de vrai mais avec un substitut du poste réel (vrai serveur SMTP MailCatcher au lieu d'une vraie boîte aux lettres ; vrai navigateur Chrome dans une iframe de 390 px au lieu d'un téléphone) |
| **M** | Manuel : exige un appareil, un compte ou un environnement réel |

| Statut | Signification |
|---|---|
| **Réussi** | exécuté, résultat conforme (mode A ou S) |
| **Échoué → corrigé** | défaut reproduit, corrigé à la source, test de non-régression ajouté |
| **Non exécuté** | recette manuelle (M) non faite ; **jamais déclarée réussie sur la base d'un test automatisé** |
| **Bloqué** | impossible dans cet environnement (appareil ou environnement absent) |

Un scénario de mode A ou S « Réussi » **ne valide pas** la recette manuelle correspondante : elles sont listées séparément au §5.

Environnement : MySQL 8.4 (conteneur), PHP 8.4, Symfony 8.1, MailCatcher du projet (SMTP réel), Chrome (macOS) pour le rendu. Horloge figée au 12 juin 2026 09:00 UTC pour les scénarios A ; vraie horloge pour le rendu.

## 2. Matrice de couverture

Preuves : les noms de tests sont ceux de `tests/` ; `R-TR-n` désigne les scénarios de bout en bout ajoutés par ce lot (`tests/Integration/Recette/TransversalRecetteTest.php`) ; « MC » = recette MailCatcher (`tests/Integration/Recette/MailCatcherRecette055Test.php`, exécutée avec `CIVIPASS_RECETTE_MAILCATCHER=1`) ; « Rendu 390 » = captures de `tests/Functional/Recette/MobileSnapshotRecetteTest.php` (HTML réel) rendues par Chrome (`var/recette/screens390/`, non versionnées).

### 2.1 Autorisations (LOT 05.5.1 et 05.5.2)

| ID | Scénario (préconditions → actions) | Attendu | Observé | Preuve | Mode | Statut |
|---|---|---|---|---|---|---|
| AUT-01 | Laissez-passer approuvé par 2 communes → l'une révoque son autorisation | L'autre commune est inchangée (zones, états, période) ; la révoquée est « Révoquée » | Conforme : états de l'organisatrice identiques avant/après ; partenaire `Revoked` | R-TR-01 ; `AuthorizationRevocationTest` | A | Réussi |
| AUT-02 | Révocation → toute modification ultérieure de cette commune | Refusée (définitive), l'autre commune peut encore agir | Refus « révoquée » pour zones, plaques, QR, période | `AuthorizationRevocationTest`, `PermitVehicleServiceTest`, `PermitTokenReplacementServiceTest` | A | Réussi |
| AUT-03 | Prolongation d'une période en cours | Nouvelle fin ; période précédente conservée | Conforme | `AuthorizationPeriodServiceTest` ; R-TR-04 | A | Réussi |
| AUT-04 | Raccourcissement (fin à une heure donnée) | Droits terminés à l'heure choisie, message prioritaire | Conforme (valide avant, expirée après la fin raccourcie) | R-TR-04 ; `PeriodNotificationTest` ; MC | A, S | Réussi |
| AUT-05 | Expiration puis reprise | Nouvelle période, aucun droit rétabli rétroactivement | Conforme : expirée, puis valide après reprise | R-TR-04 | A | Réussi |
| AUT-06 | Enchaînement : zone ajoutée → prolongation (autre commune) → remplacement de plaque → remplacement du QR → révocation (autre commune) | Chaque opération produit ses révisions, rien d'autre n'est modifié | Ordre `ZONE_ADDED, PERIOD_EXTENDED, VEHICLE_REPLACED_OUT, VEHICLE_REPLACED_IN, TOKEN_REPLACED, AUTHORIZATION_REVOKED` | R-TR-01 | A | Réussi |
| AUT-07 | Historique après d'autres opérations | Les révisions déjà écrites sont **identiques** (id, type, instants, motifs, états, lots) | Instantané avant/après identique | R-TR-01 ; `VehicleRevisionArchitectureTest`, `ZoneRevisionArchitectureTest` (aucun mutateur) | A | Réussi |
| AUT-08 | Écran « Droits du laissez-passer » à 390 px | Lisible, sans débordement | Lisible ; **défaut d'état technique affiché corrigé (AN-04)** | Rendu 390 (écran 03) | S | Échoué → corrigé |

### 2.2 Zones (LOT 05.5.3)

| ID | Scénario | Attendu | Observé | Preuve | Mode | Statut |
|---|---|---|---|---|---|---|
| ZON-01 | Ajout, retrait, réintégration, correction de libellé | Retrait logique, ligne réutilisée, libellé synchronisé, notifications adaptées | Conforme | `AuthorizationZoneServiceTest`, `ZoneNotificationTest` | A | Réussi |
| ZON-02 | Six révisions dans la **même seconde** (zones puis plaques) | Ordre d'application conservé ; un lot par opération | Un seul instant (`occurred_at`) pour les 6 ; ordre exact par identifiant UUID v7 | R-TR-02 | A | Réussi |
| ZON-03 | Succession ajout → retrait → réintégration d'une même zone | Une seule ligne, rattachée à la fin ; historique complet | Conforme | R-TR-02 | A | Réussi |
| ZON-04 | Zone **rattachée** mais suspendue | Comptée comme rattachée, **pas accessible** ; visible comme telle | `ZoneSuspended` ; 3 rattachées / 2 accessibles (public) ; libellé « Rattachée · Zone suspendue » après correction | R-TR-03 ; `PermitZoneScreenTest` ; Rendu 390 | A, S | Échoué → corrigé (AN-04) |
| ZON-05 | Opérations de zones de deux communes en parallèle | Chaque commune ne touche que ses zones ; aucun interblocage | Conforme | `ZoneConcurrencyTest` | A | Réussi |
| ZON-06 | Aperçu « Ce qui changerait » à 390 px | Lisible | Défaut constaté à l'écran sur les aperçus des plaques et du QR (liste écrasée dans une colonne de 20 px) ; l'aperçu des **zones** a le même gabarit, corrigé de la même façon mais **non rendu** à l'écran ; verrouillé par un test de structure | Rendu 390 (05, 04b) ; `PermitTemplateStructureTest` | S, A | Échoué → corrigé |
| ZON-07 | **Plusieurs révisions à la même seconde entre deux processus** | Ordre relatif | **Non garanti** entre deux processus (exact dans un même processus). **Accepté pour la V1 (D2)** : l'ordre n'entre jamais dans le calcul des droits ; les lots et les liens de remplacement sont conservés ; l'écran dit que cet ordre n'est pas garanti | R-TR-02 (un processus) ; `RevisionOrderIsDisplayOnlyTest` ; `PermitRightsScreenTest` | A | Réussi (limite acceptée, D2) |

### 2.3 Véhicules (LOT 05.5.4)

| ID | Scénario | Attendu | Observé | Preuve | Mode | Statut |
|---|---|---|---|---|---|---|
| VEH-01 | Ajout, retrait, réactivation, remplacement | Retrait logique, ligne réutilisée, deux révisions liées pour un remplacement | Conforme | `PermitVehicleServiceTest` ; R-TR-02 | A | Réussi |
| VEH-02 | Dixième plaque / onzième | Refusée sur l'état final, y compris deux ajouts simultanés | Conforme | `VehicleChangePlannerTest`, `VehicleConcurrencyTest` | A | Réussi |
| VEH-03 | Une commune retire/remplace une plaque **utilisée par les deux communes** | Effet commun, confirmation de portée, ligne neutre pour l'autre, notification prioritaire | Plaques à jour sur la page publique ; l'autre commune : ligne neutre « Véhicules modifiés » sans plaque, motif ni auteur | R-TR-01 ; `PermitVehicleScreenTest` | A | Réussi |
| VEH-04 | Historique neutre d'une commune **révoquée** | Elle ne voit plus les plaques ; lignes neutres sans détail | Conforme | R-TR-01 ; `PermitVehicleServiceTest` | A | Réussi |
| VEH-05 | Plaque saisie dans un commentaire (SIV, FNI, séparateurs divers) | Refusée, jamais conservée | **Faux négatif trouvé** : tiret insécable, point, souligné, barre oblique passaient ; **corrigé (AN-01)** | `CommentDetectorCorpusTest` | A | Échoué → corrigé |
| VEH-06 | Commentaires ordinaires (30 phrases) | Jamais refusés | 0 refus sur 30 | `CommentDetectorCorpusTest` | A | Réussi |
| VEH-07 | Phrases à quantités (« 1200 kg 45 passagers », « 1234 du 12 juin »…) | Acceptées (décision D1) | Avant : 12 refusées sur 12. **Après le correctif ciblé : 0 refusée sur 12**, 0 faux positif sur 30 commentaires ordinaires ; 2 faux positifs restants connus et figés (lettres en majuscules : « place 118 BC 45 ») | `CommentDetectorCorpusTest` | A | Échoué → corrigé (AN-05) |
| VEH-09 | Tentatives de **dissimuler** une plaque derrière l'exemption des phrases (majuscules, tirets, points, soulignés, barres obliques, forme collée, espaces doublées ou insécables, plaque après une phrase, forme SIV « DE 200 KG ») | Toutes refusées ; aucune plaque ne passe | 19 présentations dissimulées et 20 plaques réelles : 0 faux négatif | `CommentDetectorCorpusTest` | A | Réussi |
| VEH-08 | Écran « Modifier les plaques » et son aperçu à 390 px | Lisible | Lisible après correction (AN-02, AN-03) | Rendu 390 (04b) | S | Réussi |

### 2.4 QR (LOT 05.5.5) et contrôles publics (LOT 05.4.4)

| ID | Scénario | Attendu | Observé | Preuve | Mode | Statut |
|---|---|---|---|---|---|---|
| QR-01 | Remplacement : un seul jeton actif, ancien retiré, aucun instant à deux QR | Jamais deux QR valides ni aucun, y compris vu de la lecture publique pendant l'opération | Conforme (5 lectures pendant le verrou) | `TokenReplacementConcurrencyTest` | A | Réussi |
| QR-02 | Idempotence : même identifiant d'opération rejoué, double envoi simultané, reprise après incident dans un nouveau processus | Un seul remplacement, résultat initial restitué | Conforme | `PermitTokenReplacementServiceTest`, `TokenReplacementConcurrencyTest` | A | Réussi |
| QR-03 | Remplacement contre révocation simultanée (les deux ordres) | Révocation d'abord : refusé ; remplacement d'abord : les deux s'appliquent | Conforme | `TokenReplacementConcurrencyTest` | A | Réussi |
| QR-04 | Ancien QR | HTTP **410**, aucune donnée, même après clôture | Conforme | `PermitQrScreenTest`, `PublicVerificationClosedEventTest` ; Rendu 390 (06) | A, S | Réussi |
| QR-05 | Nouveau QR | Mêmes droits, mêmes plaques, mêmes zones | Conforme | `PermitTokenReplacementServiceTest`, R-TR-01 | A | Réussi |
| QR-06 | Événement **clos** | Statut neutre « Événement terminé » seulement (aucune donnée personnelle) | Conforme, pour toute représentation (HTML, Accept JSON/XML/PDF, AJAX, Turbo, suffixes) | `PublicVerificationClosedEventTest` ; Rendu 390 (08) | A, S | Réussi |
| QR-07 | QR inconnu ou mal formé | 404 neutre, sans rien révéler de l'événement | Conforme | `PublicVerificationClosedEventTest`, `PublicVerificationPageTest` (la capture 09 est produite mais n'a pas été relue à l'écran) | A | Réussi |
| QR-08 | Événement suspendu | « Droits suspendus », aucun droit utilisable | Conforme | `PublicVerificationClosedEventTest`, `PublicVerificationReaderTest` | A | Réussi |
| QR-09 | PDF téléchargé **avant** le remplacement | Son QR est celui de l'ancien jeton (410) ; un PDF régénéré porte le nouveau | Pixel pour pixel : ancien PDF = ancien QR, nouveau PDF = nouveau QR | `PermitQrScreenTest` | A | Réussi (lecteur réel : voir §5) |
| QR-10 | Écran « Remplacer le QR code » à 390 px | Lisible | **Liste écrasée, marges absentes : corrigé (AN-02, AN-03)** | Rendu 390 (05, 07) | S | Échoué → corrigé |
| QR-11 | Page publique active et lien du bénéficiaire à 390 px | Lisibles, sans débordement | Conformes | Rendu 390 (01, 02) | S | Réussi |
| QR-12 | Limitation de débit, en-têtes, CSP, sans cookie, sans redirection (05.4.4) | Conformes | Conforme ; une réponse « événement terminé » n'est pas un échec compté | `PublicVerification*Test`, `RateLimiterVerificationThrottleTest` | A | Réussi |

### 2.5 Notifications

| ID | Scénario | Attendu | Observé | Preuve | Mode | Statut |
|---|---|---|---|---|---|---|
| NOT-01 | **Réception réelle** (vrai Symfony Mailer, vrai SMTP, lecture dans MailCatcher) des messages : mise à disposition, zones retirées, plaques retirées, QR remplacé, fin raccourcie, autorisation retirée | Six messages dans l'ordre, un par événement prioritaire | Six messages reçus avec les objets attendus, destinataire correct, `Auto-Submitted`, multipart texte+HTML, `Message-ID` | MC | S | Réussi |
| NOT-02 | Aucune donnée sensible dans les messages reçus | Ni jeton, ni plaque, ni motif, ni commentaire, ni adresse, ni lien de contrôle `/v/` | Aucune (contrôle sur la source MIME de chacun des 6 messages) | MC | S | Réussi |
| NOT-03 | Exactitude après plusieurs modifications | Un ajout couvert par un retrait ne part pas seul ; un message obsolète est supprimé avec trace | Conforme | `ZoneNotificationTest`, `VehicleNotificationTest`, `TokenReplacementNotificationTest` ; MC | A, S | Réussi |
| NOT-04 | Panne SMTP puis reprise (QR) | Reprise unique, même `Message-ID`, aucun doublon | Panne : `PENDING`/`SMTP_TEMPORARY` ; après retour : 1 message, `SENT`, rien ne repart au passage suivant | MC ; `TokenReplacementNotificationTest` | S, A | Réussi |
| NOT-05 | Échec SMTP définitif | Abandon visible, remplacement conservé | Conforme | `TokenReplacementNotificationTest` | A | Réussi |
| NOT-06 | Réception dans un **client de messagerie réel** (rendu, courrier indésirable, mobile) | — | — | — | M | Non exécuté (§5) |

### 2.6 Recettes de terrain

| ID | Scénario | Statut | Remarque |
|---|---|---|---|
| TER-01 | Scan d'un QR sur **iPhone** (ancien : 410 ; nouveau : droits) | **Non exécuté** | Aucun appareil. Le simulateur iOS est installé mais ne dispose d'aucun appareil virtuel disponible. |
| TER-02 | Scan sur **Android** | **Non exécuté** | Aucun appareil. |
| TER-03 | Affichage mobile à **390 px** | **Réussi (S)**, défauts AN-02 à AN-04 corrigés | Rendu Chrome dans une iframe de 390 px : 10 écrans produits, 8 relus à l'écran (01, 02, 03, 04b, 05/07, 06, 08) ; les captures 04 et 09 n'ont pas été relues. **Ce n'est pas un téléphone** : pas de tactile, de barre d'adresse, de clavier, de police système ni de mode clair (les captures sont en thème sombre). |
| TER-04 | PDF dans un **lecteur réel** (Aperçu, Acrobat, lecteur mobile) | **Non exécuté** | Le contenu et le QR du PDF sont vérifiés par un inspecteur (mode A). |
| TER-05 | Parcours bénéficiaire **sans e-mail** (remise du lien par l'agent) | **Non exécuté** | Les messages d'écran et l'état « sans destinataire » sont testés (mode A). |
| TER-06 | Parcours **multicommunal complet** avec de vrais comptes | **Non exécuté** | Le scénario équivalent est automatisé (R-TR-01). |
| TER-07 | Vérification publique **derrière nginx** sur environnement natif | **Bloqué** | Environnement natif indisponible ; `PUBLIC_VERIFICATION_ENABLED` reste à 0 ; la configuration `ops/nginx` n'est pas déployée. |
| TER-08 | Vérification mobile en **thème clair** (pages publique, bénéficiaire, écrans agent) | **Non exécuté** | Les captures du 11 novembre 2026 sont en thème sombre uniquement. |
| TER-09 | **Aperçu des zones** (« Ce qui changerait ») à 390 px | **Non exécuté** | Même gabarit que l'aperçu des plaques, corrigé de la même façon et verrouillé par un test de structure, mais jamais rendu à l'écran. |

## 3. Rapport des anomalies

| ID | Anomalie | Gravité | Cause | Correction | Non-régression |
|---|---|---|---|---|---|
| AN-01 | Une plaque écrite avec un **tiret insécable** (U+2011), un point, un souligné ou une barre oblique passait dans un commentaire de modification de plaques | Haute (donnée personnelle conservée dans un commentaire) | Le détecteur ne reconnaissait que l'espace et le tiret simple, alors que la normalisation de la plaque gère les tirets Unicode | Séparateurs étendus (espaces insécables, tirets U+2010 à U+2015, point, souligné, barre oblique) dans les deux contrôles de `PermitVehicleService` | `CommentDetectorCorpusTest` (aucun faux négatif) |
| AN-02 | Listes « Ce qui changerait / se passera » (zones, plaques, QR) **écrasées dans une colonne de 20 px** à 390 px | Moyenne (écran illisible) | `<li>` de `cp-consequences` mêlant texte brut et balises : la grille du design système (icône + un seul élément) éclate | `<li>` = icône + `<span>` dans `zones`, `vehicles`, `qr` | `PermitTemplateStructureTest` |
| AN-03 | Blocs `cp-box` des écrans de période, zones, plaques, QR **sans marges** (texte collé au cadre) | Faible | Structure sans `cp-box__head` / `cp-box__body` | Structure du design système appliquée aux 4 écrans (période : 2 blocs) | `PermitTemplateStructureTest` |
| AN-04 | Liste des zones de l'écran des droits : état **technique brut** (« Rattachée · valid », « zone suspended ») au lieu d'un libellé français ; la distinction rattachée / accessible n'était pas lisible | Moyenne | Affichage de la valeur de l'énumération | Fonction `cp_state_label` (mêmes mots que la page publique) dans l'écran des droits et celui des zones | `PermitZoneScreenTest::testTheZoneListWritesTheStateInFrenchAndNeverTheTechnicalValue`, `PermitTemplateStructureTest` |
| AN-05 | **Faux positifs** du détecteur de plaques : 12 phrases à quantités sur 12 refusées (« 1200 kg 45 passagers », « 1234 du 12 juin ») | Faible (gêne de saisie, aucune fuite) | Forme FNI ancienne « nombre, 1 à 3 lettres, 2 chiffres » ressemble à de la prose ; « de 200 kg » a la forme d'une plaque SIV | **Corrigé de façon ciblée (décision D1)** : `PlateInCommentDetector` exempte seulement un mot courant (unité, article, préposition) **en minuscules entre deux espaces simples** ; SIV, majuscules, tirets et autres séparateurs, forme collée, espaces doublées ou insécables restent détectés ; plaques connues du laissez-passer toujours refusées, quelle que soit la casse | `CommentDetectorCorpusTest` |
| AN-06 | Échec intermittent de `DirectIssuanceConcurrencyTest` au premier passage complet du 05.5.5 | Faible, non reproduit | Non prouvée (hypothèse : veille de la machine) | Aucune ; 10 rejeux verts hors veille | Cadrage 05.5.5 §17 |
| AN-07 | Capture tronquée à droite avec Chrome headless en 390 px | Aucune (artefact de mesure) | Chrome impose une largeur de fenêtre minimale : le viewport réel était plus large | Méthode corrigée (iframe de 390 px) | — |

## 4. Résultats des contrôles automatisés

- **`bin/qa full`** (équivalent de `make quality`) : voir §7 (résultat final).
- **`docs/tools/verify-docs.sh`** : voir §7.
- **Doctrine** : `doctrine:schema:validate` : voir §7. Aucune migration.
- **Recette MailCatcher (SMTP réel)** : 2 tests, 144 assertions, verts ; recette MailCatcher du 05.3.3 : 2 tests, 35 assertions, verts. Ces recettes sont ignorées par défaut (variable `CIVIPASS_RECETTE_MAILCATCHER=1`).
- **Rendu mobile** : test de production des pages ignoré par défaut (`CIVIPASS_RECETTE_SNAPSHOT=1`).

## 5. Recettes manuelles restantes (à faire par une personne, avec des appareils réels)

À consigner dans ce tableau au fur et à mesure (observé, preuve, statut). Procédures abrégées ; préparer un laissez-passer d'essai approuvé par deux communes, avec e-mail, puis un second sans e-mail.

| ID | Procédure | Attendu |
|---|---|---|
| TER-01 / TER-02 | Imprimer ou afficher le QR, le scanner avec iPhone (appareil photo) puis Android ; remplacer le QR ; rescanner l'ancien, puis le nouveau | Ancien : page « Ce QR code a été remplacé » (410) ; nouveau : droits corrects ; page lisible, sans défilement horizontal |
| TER-03 bis | Ouvrir les pages publique, bénéficiaire et les écrans agent sur un téléphone réel, **thèmes clair et sombre** | Aucun débordement ; contrastes lisibles ; zones tactiles utilisables |
| TER-04 | Télécharger le PDF avant et après un remplacement ; l'ouvrir dans Aperçu, Acrobat et un lecteur mobile ; scanner son QR | PDF lisible ; QR du PDF ancien : 410 ; du PDF actuel : droits ; mention « seul le contrôle du QR code fait foi » |
| NOT-06 | Recevoir les six messages dans une vraie boîte (Gmail, Apple Mail, Outlook) ; vérifier objet, rendu mobile, courrier indésirable, liens | Messages reçus hors indésirables ; lisibles sur mobile ; aucun QR, jeton ni plaque |
| TER-05 | Laissez-passer **sans e-mail** : remplacer le QR ; suivre le message de l'écran (« Liens et notification du bénéficiaire ») ; ouvrir le lien côté bénéficiaire | Le lien affiche le nouveau QR et propose un PDF à jour |
| TER-06 | Parcours complet avec deux communes réelles : approbations, zones, plaques, période, remplacement du QR, révocation d'une commune | Chacune ne voit que ses détails ; ligne neutre pour l'autre ; l'autre commune garde ses droits |
| TER-07 | Activer la page publique **sur un environnement natif derrière nginx** (uniquement avec accord) ; contrôler en-têtes, journaux sans jeton, limitation de débit, 404 uniforme, HTTP 410 | Voir `docs/exploitation-verification-publique.md` et `ops/nginx/README.md` |
| TER-08 | Pages publique, bénéficiaire et écrans agent sur un téléphone réel en **thème clair** | Aucun débordement ; contrastes lisibles |
| TER-09 | Aperçu « Ce qui changerait » de l'écran des **zones** à 390 px | Liste lisible (icône et texte), marges correctes |
| LEG-01 | Historique neutre d'une commune révoquée, référence `LP-` des messages `VEHICLES_*` et faux positifs du détecteur sur de vrais commentaires (héritage du 05.5.4 §18) | Conformes à la matrice ; relever les faux positifs résiduels sur de vrais commentaires |

## 6. Décisions du commanditaire (11 novembre 2026)

- **D1 — Détecteur de plaques : correction ciblée, option « majuscules seulement » refusée.** Conservés : plaques connues du laissez-passer (toute casse), formats SIV et FNI caractéristiques, séparateurs alternatifs, validation côté serveur. Amélioration : distinction entre une plaque FNI et une séquence numérique ordinaire, sans contournement évident (`PlateInCommentDetector`, voir AN-05 et VEH-07, VEH-09). **Résidu documenté** : une plaque dont les lettres sont exactement un mot de la liste (unités, articles, prépositions), saisie en minuscules avec des espaces simples (« 1234 du 75 »), n'est pas détectée *dans un commentaire* ; la plaque du laissez-passer reste, elle, toujours refusée. Deux faux positifs restants (lettres en majuscules entre deux nombres) sont connus et figés.
- **D2 — Ordre des révisions : accepté pour la V1.** L'absence d'ordre chronologique total garanti entre deux processus dans la même seconde est acceptée, aux conditions suivantes, toutes vérifiées : (1) l'ordre n'est **jamais** utilisé pour calculer les droits ou l'état courant (le calcul des droits ne lit pas les révisions : `RevisionOrderIsDisplayOnlyTest`) ; (2) les regroupements par `batch_id` et les liens explicites de remplacement (numéro de remplacement, types `VEHICLE_REPLACED_OUT` / `VEHICLE_REPLACED_IN`) sont conservés ; (3) l'écran de l'historique ne prétend pas à un ordre certain et le dit (mention « même seconde ») ; (4) **aucune migration de séquence** pour ce lot. Limite documentée aussi dans [lot-05-5-3-cadrage.md](lot-05-5-3-cadrage.md) §18.

## 7. Résultats finaux et proposition de clôture

### 7.1 Résultats (11 novembre 2026)

| Contrôle | Résultat |
|---|---|
| `bin/qa full` (= `make quality` : PHP-CS-Fixer, PHPStan niveau 6, PHPUnit) | **EXIT 0**, « All quality checks passed » : **2 930 tests, 21 747 assertions, 6 ignorés** (2 909 / 21 607 / 3 avant ce lot), après les corrections des décisions D1 et D2 |
| `docs/tools/verify-docs.sh` | OK (empreintes des originaux, liens relatifs) |
| `doctrine:schema:validate` | mapping correct, schéma synchronisé ; **aucune migration** |
| Tests ajoutés par la recette | 4 scénarios transversaux (`TransversalRecetteTest`), 6 tests des détecteurs (`CommentDetectorCorpusTest`), 3 tests de structure des gabarits (`PermitTemplateStructureTest`), 3 tests de l'ordre des révisions (`RevisionOrderIsDisplayOnlyTest`), 2 tests d'écran (état en français, mention d'ordre), 2 tests MailCatcher et 1 test de rendu mobile (ces trois derniers **ignorés par défaut**) |
| Recettes SMTP réel et rendu 390 px | exécutées à la main avec `CIVIPASS_RECETTE_MAILCATCHER=1` et `CIVIPASS_RECETTE_SNAPSHOT=1` : voir §4 |

Les 6 tests ignorés : GNU `date` requis (1, Linux), recette MailCatcher du 05.3.3 (2), recette MailCatcher du 05.5.6 (2), production des pages pour le rendu mobile (1). Aucun n'est une recette manuelle de terrain.

### 7.2 Risques résiduels et blocages préproduction

- **Bloquant avant la production (LOT 05.6)** : conservation et anonymisation des plaques de `permit_revision_vehicle` et des lignes retirées de `permit_vehicle` ; durée de conservation des jetons retirés (gardés pour répondre 410) ; règles de conservation administrative (DM-117).
- **Recettes de terrain non faites** (§5) : scan iPhone et Android, lecteur PDF réel, boîte aux lettres réelle, parcours sans e-mail, parcours multicommunal avec de vrais comptes, thème clair sur téléphone. Les défauts d'affichage trouvés au rendu à 390 px montrent que le rendu réel révèle des défauts que les tests ne voient pas : cette recette ne doit pas être sautée.
- **Vérification publique derrière nginx** : non exécutée, environnement natif indisponible ; `PUBLIC_VERIFICATION_ENABLED` doit rester à 0 jusque-là.
- **Décisions** : D1 et D2 prises le 11 novembre 2026 (§6) ; résidus documentés (plaque FNI aux lettres d'un mot courant, en minuscules avec espaces simples, dans un commentaire ; ordre non garanti de deux révisions de la même seconde entre deux processus).
- **Incident intermittent** AN-06 : non reproduit en 10 rejeux, cause non prouvée.
- **Limites des captures** : 8 captures relues sur 10 produites, en thème sombre, rendu Chrome et non téléphone ; l'aperçu des zones n'a pas été rendu.
- **Plaques et QR déjà distribués** : un QR imprimé ou un PDF déjà téléchargé reste irrécupérable (410) jusqu'à ce que le bénéficiaire rouvre son lien.

### 7.3 Décision de clôture du LOT 05.5

**Clôture CONDITIONNELLE du LOT 05.5 (05.5.1 à 05.5.6) VALIDÉE par le commanditaire le 11 novembre 2026.** Développement et recette automatisée et semi-réelle : validés. Recettes de terrain et infrastructure : **non validées, obligatoires avant la production**.

1. **Acquis, à valider** : développement et recette automatisée et semi-réelle des LOT 05.5.1 à 05.5.5 conformes ; 4 anomalies de la recette corrigées et verrouillées ; aucune migration au-delà des deux validées ; `bin/qa full` vert.
2. **Conditions de la clôture définitive** : (a) D1 et D2 : tranchées le 11 novembre 2026 ; (b) exécuter les recettes manuelles du §5 et du §2.6 (scan iPhone et Android, ancien et nouveau QR, PDF réel, notification en boîte réelle, parcours sans e-mail, parcours multicommunal, nginx natif, mobile en thème clair, aperçu des zones) ; (c) consigner leurs résultats dans ce document. **Aucune recette non exécutée n'est marquée réussie.**
3. **Non nécessaire à la clôture du LOT 05.5 mais bloquant avant la production** : le mécanisme de conservation et d'anonymisation du **LOT 05.6**, et la recette de la vérification publique derrière nginx.
4. **Suite** : ne pas commencer le LOT 05.6 avant votre validation de cette recette ; les corrections de ce lot (12 fichiers modifiés ou nouveaux en plus de la documentation) ne sont **pas commitées**.

