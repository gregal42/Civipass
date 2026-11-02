Civipass sert à délivrer et contrôler des laissez-passer pendant une crise (incendie, inondation, tempête). Chaque écran doit se comprendre vite, sous stress, sur un ordinateur de mairie comme sur un téléphone au soleil. La direction « Signalétique civique » vient du prototype V1 validé : fond papier froid, encre bleu préfecture, et un seul signal ambre réservé à ce qui attend une action.

## Contenu et ton

- Écrivez en français, au vouvoiement, en phrases courtes. Nommez les choses comme les agents et les administrés les nomment : « demande », « laissez-passer », « commune entière », « plaque », jamais le vocabulaire technique.
- Un bouton dit exactement ce qu’il fait : « Approuver… », « Confirmer l’approbation », « Ouvrir l’événement ». Les points de suspension annoncent une étape de plus avant l’action définitive.
- Une confirmation dit ce qui s’est passé et ce qui en découle : « Demande approuvée — laissez-passer LP-2026-0156 émis ».
- Un message d’erreur dit ce qui s’est passé, ce qui est conservé et quoi faire : « L’approbation n’a pas été enregistrée. Le serveur ne répond pas. Vos choix sont conservés : vérifiez la connexion puis confirmez à nouveau. »
- Une action irréversible ou lourde de conséquences dit sa conséquence avant la confirmation (révocation, refus, suspension).
- Pas d’emoji, pas de point d’exclamation, pas de formule d’excuse. Le vocabulaire reste civil : jamais de termes policiers ou de surveillance.
- Dates au format `01/11/2026 à 09:42`, période avec une flèche : `01/11/2026 10:00 → 05/11/2026 20:00`. Le fuseau (« heure de Paris ») est affiché partout où une heure engage un droit.
- Les références (`DEM-2026-0156`, `LP-2026-0156`) et les immatriculations s’écrivent en `ref` et dans le composant Plaque.

## Couleur

- Posez le fond de page en `paper`, les cartes et panneaux en `surface`, les zones en retrait (en-têtes de liste, récapitulatif) en `surface-2`.
- Écrivez le texte en `ink`, le secondaire en `ink-2`, l’aide et les horodatages en `ink-3`. Les trois passent 4,5:1 sur `paper`, `surface` et `surface-2`, dans les deux thèmes.
- Utilisez `brand` pour l’action principale, les liens et la sélection, avec `on-brand` sur les aplats. Utilisez `brand-soft` pour un élément sélectionné ou actif.
- L’ambre signale ce qui attend une action, et uniquement cela. Écrivez-le en `signal` sur `signal-soft`. N’employez `signal-fill` qu’en marque graphique (pastille, rubalise, badge de compteur avec `tape-dark`), jamais en texte ni seul porteur de sens.
- L’urgence se lit par l’ordre et la taille avant la couleur. L’événement actif passe en tête, son compteur d’attente est en `display`, la carte porte la rubalise. Aucun rouge, aucun clignotement.
- Le vert, le rouge et l’orange sont réservés aux décisions et au contrôle. `ok-band` sert au verdict « Autorisation en cours de validité » et au bouton Approuver, `bad-band` aux verdicts négatifs, `warn-band` au verdict hors ligne et `neutral-band` à l’impossibilité de vérifier. Le texte posé dessus est en `on-band`.
- Un statut n’est jamais porté par la couleur seule : chaque pastille a un libellé, chaque verdict une icône et un titre.
- Le focus clavier est un anneau `focus` de 3 px, plein, décalé de 2 px. Il ne se retire jamais.
- Le thème sombre suit le réglage du système, ou `data-theme="dark"` sur `<html>`. Il est utile au contrôle de nuit. Les plaques gardent `plate-ground` / `plate-ink` dans les deux thèmes.

## Typographie

- Atkinson Hyperlegible Next pour tout le texte, Atkinson Hyperlegible Mono pour les données. Cette famille, conçue pour la basse vision, distingue les caractères ambigus (0/O, 1/l/I). Les fichiers WOFF2 sont fournis sous licence OFL, à servir depuis l’application, sans CDN.
- Le titre de page est en `cp-h1` (36 px, `cp-h2` sous 640 px), le titre de section ou de panneau en `cp-h3`, le texte courant en `cp-body` (16 px minimum). La méta et l’aide sont en `cp-small`, les horodatages en `cp-caption`. Une information décisive n’est jamais en `cp-caption`.
- Les sur-titres sont en `cp-eyebrow`, en capitales espacées.
- La hiérarchie passe par la graisse (800 pour les titres, 700 pour les valeurs) plutôt que par la couleur. Les chiffres sont tabulaires (`cp-num`).
- `cp-display` est réservé au compteur d’attente de l’événement actif : un seul par écran.

## Espacement, rayons, élévation

- Espacez avec les pas `space-1` à `space-14`. La marge latérale est de `space-6` en largeur desktop, `space-4` à 375 px. Les sections sont séparées par `space-7`.
- Les rayons sont imbriqués : `radius-l` pour les panneaux, cartes, formulaires inline et verdicts, `radius-m` pour les options, KPI, alertes et menus, `radius-s` pour les boutons, champs, étiquettes et plaques. `radius-pill` est réservé aux pastilles et puces. Un élément arrondi n’a jamais un rayon supérieur à son parent.
- Un conteneur arrondi dont un enfant touche le bord avec un fond (en-tête coloré, ligne survolée, rubalise) porte `overflow: clip`. Les coins restent nets dans toutes les largeurs.
- Une seule ombre, `shadow-raised` : carte d’événement actif, menus, toasts, dialogues. Le reste est séparé par des filets `line`.
- Toute cible tactile fait au moins `tap-min` (44 px). Les décisions (Approuver, Refuser) et les options de formulaire font `tap-comfort` (52 px).

## Mise en page

- Le contenu municipal tient dans `content-max` ; les écrans publics dans `public-max`.
- La coquille municipale s’adapte à la largeur du conteneur `.cp-app` :
  - ≥ 1024 px : barre latérale complète de `sidebar-width` ;
  - 640–1023 px : rail de `rail-width` avec icônes et libellés ;
  - < 640 px : barre supérieure compacte, bandeau de contexte et barre basse Accueil / Demandes / Pass / Menu.
- La commune active est toujours visible : sélecteur dans la barre supérieure, et bandeau de contexte en largeur compacte.
- Le résumé passe avant le détail. Les tableaux deviennent des cartes sous 560 px de conteneur, sans réduire le texte.
- Une fiche dossier s’affiche en pleine page, jamais seulement dans un panneau latéral. L’approbation se déploie dans la fiche ; une modale ne sert qu’à confirmer une sortie avec saisie non enregistrée ou une action irréversible.

## Iconographie

- Le jeu d’icônes Civipass a été dessiné pour le prototype V1 : trait de 2 px, extrémités arrondies, grille de 24 px, couleur héritée du texte. Il compte 32 glyphes (voir le composant Icon). Les SVG du groupe Icons sont tracés en `ink` (#0f1e36).
- Une icône accompagne un libellé. Seule, elle reçoit un `label` (nom accessible).
- Pas d’emoji, pas d’icône de police, pas d’autre bibliothèque. Une icône manquante se dessine dans le même style et s’ajoute au gabarit `icon`.
- Il n’existe pas de logo Civipass : la marque est le monogramme « Cp » en Atkinson Hyperlegible Mono sur un carré `brand`, suivi du nom en `cp-h3`. Il ne faut pas en dessiner un sans brief dédié.

## Mouvement

- Les animations sont rares et brèves : ouverture du formulaire inline (220 ms), toasts, rotation du chargement, balayage des squelettes. Rien ne clignote.
- `prefers-reduced-motion` coupe toutes les animations.

## Contrôle QR : règles d’affichage

- Le vert ne s’affiche que si au moins un droit est valide en ligne. Hors ligne, jamais de vert. Sans réponse du serveur ni mode hors ligne, l’écran affiche « Vérification impossible » et ne valide jamais par défaut.
- Un verdict positif ou incertain est toujours suivi de la liste « Avant de laisser passer, vérifiez » : commune, zone, immatriculation.
- L’écran montre l’identité minimale (nom, référence, événement), toutes les communes et zones, les périodes et les plaques. Il ne montre jamais d’e-mail, de téléphone, d’adresse ni d’historique des scans.
- Le statut de connexion est affiché en permanence. La fraîcheur des données hors ligne est affichée avec les seuils de 12 h et 24 h ; au-delà de 24 h, aucune validation hors ligne n’est possible.
