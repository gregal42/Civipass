# LOT 05.5.6 — Checklist des recettes de terrain

**Aucune case n'est cochée : toutes les recettes ci-dessous sont « Non exécuté ». Une recette n'est « Réussie » qu'après exécution réelle, avec une preuve (capture, photo, message reçu).** Document d'accompagnement de [lot-05-5-6-recette.md](lot-05-5-6-recette.md) §5 (identifiants TER-xx et NOT-06).

Matériel : un iPhone, un Android, une boîte e-mail réelle, l'accès aux comptes de **deux communes** (un administrateur d'une commune, un instructeur de l'autre), un navigateur mobile, un lecteur PDF.

## 0. Prérequis (à valider avant de commencer)

| # | Prérequis | État |
|---|---|---|
| P0 | Les téléphones atteignent l'application. Le domaine de développement (`civipass.cws.gregory.localhost`) n'est résolu **que sur le Mac** : un téléphone ne l'atteint pas sans réseau commun et résolution de nom. Sans cela, utiliser un environnement de recette accessible depuis le réseau local (**à valider avant mise en place**, aucune modification d'infrastructure ici). | **À confirmer** |
| P1 | La page publique `/v/{jeton}` est **désactivée** (`PUBLIC_VERIFICATION_ENABLED=0`). Pour scanner un QR, il faut l'activer **uniquement dans l'environnement de recette isolé**, avec **votre accord explicite**, puis la remettre à 0. | **Accord requis** |
| P2 | Deux laissez-passer d'essai approuvés par les **deux communes** : A avec e-mail réel du testeur, B **sans e-mail**. Deux plaques fictives, deux zones. Aucune donnée réelle d'un tiers. | À préparer |
| P3 | Réception réelle : un relais SMTP vers une vraie boîte (le MailCatcher du projet n'en est pas une). Configuration à fournir par vous. | **À fournir** |

## 1. Scénarios réalisables dès que P0 à P3 sont remplis (sans nginx natif)

| ID | Matériel | Étapes | Attendu | Résultat / preuve | Statut |
|---|---|---|---|---|---|
| TER-01 | iPhone | Afficher le QR (page du bénéficiaire A), le scanner avec l'appareil photo ; noter la page. Puis remplacer le QR (administrateur) ; rescanner l'**ancien** (écran ou impression), puis le **nouveau** | Avant : droits corrects. Ancien après remplacement : « Ce QR code a été remplacé » (410). Nouveau : mêmes droits. Aucun défilement horizontal | | Non exécuté |
| TER-02 | Android | Mêmes étapes que TER-01 avec l'appareil photo ou Google Lens | Identique à TER-01 | | Non exécuté |
| TER-03 bis | iPhone et Android | Ouvrir sur chaque téléphone : page publique, lien du bénéficiaire, écrans « Droits », « Modifier les plaques », « Remplacer le QR code », en **thème clair puis sombre** | Aucun débordement, texte lisible, boutons utilisables au doigt, listes d'aperçu lisibles | | Non exécuté |
| TER-09 | iPhone ou Android | Écran « Modifier les zones » : afficher l'**aperçu** « Ce qui changerait » (ajouter ou retirer une zone) | Liste lisible (icône et texte), marges correctes | | Non exécuté |
| TER-04 | Lecteur PDF + téléphone | Télécharger le PDF du laissez-passer **avant** un remplacement, remplacer le QR, télécharger un 2ᵉ PDF. Ouvrir les deux (Aperçu ou Acrobat, puis lecteur mobile) et scanner leur QR | PDF lisible ; QR du 1ᵉʳ PDF : 410 ; QR du 2ᵉ : droits corrects ; mention « seul le contrôle du QR code fait foi » | | Non exécuté |
| NOT-06 | Boîte e-mail réelle | Déclencher : zones retirées, plaques retirées, remplacement du QR, raccourcissement, révocation. Lire les messages sur téléphone | Messages reçus hors courrier indésirable, objets attendus, lisibles sur mobile, **aucun QR, jeton, plaque, motif ni lien de contrôle** | | Non exécuté |
| TER-05 | Compte agent + téléphone | Laissez-passer **B sans e-mail** : remplacer le QR ; lire le message de l'écran ; ouvrir « Liens et notification du bénéficiaire » ; ouvrir le lien côté bénéficiaire | Le lien affiche le **nouveau** QR et propose un PDF à jour | | Non exécuté |
| TER-06 | Deux comptes de communes | Approbations par les deux communes ; commune 1 : ajout de zone, remplacement de plaque, remplacement du QR ; commune 2 : prolongation puis révocation de **sa** autorisation | Chaque commune ne voit que ses détails ; ligne neutre pour l'autre ; la commune 1 garde ses droits ; les plaques sont communes | | Non exécuté |
| LEG-01 | Compte agent | Historique d'une commune **révoquée** ; référence `LP-` dans les messages « véhicules » ; écrire de vrais commentaires de motif et noter les refus à tort du détecteur de plaques | Conformes à la matrice ; relever les faux positifs résiduels | | Non exécuté |
| CLO-01 | Téléphone | Après la **clôture** de l'événement d'essai, scanner le QR en vigueur | Page « Événement terminé » seule : ni nom, ni plaque, ni zone | | Non exécuté |

## 2. Scénarios qui exigent l'environnement nginx natif (non réalisables aujourd'hui)

| ID | Objet | Attendu | Statut |
|---|---|---|---|
| TER-07 | Page publique **derrière nginx** (HTTPS, domaine réel) : en-têtes (`Cache-Control`, CSP, `X-Robots-Tag`, sans cookie), 404 uniforme d'un jeton inconnu, 410 d'un jeton retiré, limitation de débit avec la vraie adresse IP, **aucun jeton dans les journaux nginx** (voir `docs/exploitation-verification-publique.md`, `ops/nginx/README.md`) | Conformes à la matrice des réponses publiques | **Bloqué** |
| TER-07 bis | Scan d'un QR en **HTTPS** sur le domaine réel depuis les deux téléphones (certificat, ouverture dans le navigateur) | Page affichée sans avertissement | **Bloqué** |
| TER-07 ter | Contrôle des variables de production avec `ops/nginx/bin/check-deployment.sh` (HTTPS, `APP_ENV=prod`, aucun proxy déclaré en trop) | Aucun échec | **Bloqué** |

## 3. Règles de la recette

- **Une recette échouée** : noter l'écart exact et la preuve, ne rien « corriger à la main » en base ; ouvrir une anomalie.
- **Après la recette** : remettre `PUBLIC_VERIFICATION_ENABLED` à 0 dans l'environnement de recette ; supprimer les données d'essai.
- **Aucun secret** (jeton, lien du bénéficiaire) dans les captures ou rapports partagés.
