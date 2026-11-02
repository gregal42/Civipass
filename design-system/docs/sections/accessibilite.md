# Accessibilité

Objectif : WCAG 2.2 niveau AA, et la conformité aux critères RGAA applicables. Un audit RGAA reste à mener avant la production.

## Ce que le design system garantit

- **Contrastes** : chaque paire texte / fond déclarée dans les tokens passe 4,5:1, et les bordures de contrôle et l’anneau de focus 3:1, dans les deux thèmes. `npm run contrast` le vérifie. `signal-fill` (2,1:1 sur `surface`) n’est jamais seul porteur de sens.
- **Statuts** : chaque statut a un libellé et un point, chaque verdict une icône et un titre. Rien ne repose sur la couleur seule.
- **Cibles** : 44 px minimum (`tap-min`), 52 px pour les décisions et les options (`tap-comfort`).
- **Focus** : visible partout (3 px `focus`), jamais retiré. Après une décision, le focus va sur le message de succès ; après une erreur de saisie, sur le résumé d’erreurs.
- **Clavier** :
  - lien d’évitement « Aller au contenu » ;
  - menus avec flèches, Échap, Début et Fin ;
  - onglets au motif WAI-ARIA (flèches, activation automatique) ;
  - boîte de dialogue native `<dialog>`, avec le focus rendu à l’élément d’origine.
- **Formulaires** :
  - chaque champ a un `<label>` ; les groupes sont des `<fieldset>` avec `<legend>` ;
  - aide et erreur sont reliées par `aria-describedby`, le champ en erreur porte `aria-invalid` ;
  - le résumé d’erreurs liste des liens vers les champs ;
  - la saisie est conservée en cas d’erreur réseau.
- **Annonces** : les messages de succès, le récapitulatif, les verdicts et les toasts sont dans des régions `role="status"` ou `aria-live`. Un chargement porte `aria-busy` et un texte « Chargement… » réservé aux lecteurs d’écran.
- **Mouvement** : `prefers-reduced-motion` coupe les animations.
- **Zoom** : les tailles de texte sont en `rem` et suivent la taille de police choisie. Le passage à la mise en page compacte sous 640 px de conteneur doit permettre le zoom à 200 % (critère 1.4.10) ; à vérifier écran par écran.
- **Lisibilité** : Atkinson Hyperlegible, texte courant à 16 px minimum, longueur de ligne limitée à `measure`.

## Ce qui reste à la charge des écrans

- Un seul `h1` par page, puis une hiérarchie de titres sans saut.
- Un `<title>` de page unique et descriptif (« Demande DEM-2026-0156 · Civipass »).
- Une langue déclarée (`lang="fr"`).
- Des messages d’erreur rédigés en clair : ce qui s’est passé, quoi faire.
- Ne transmettre aux gabarits que les entrées de navigation autorisées pour l’utilisateur : un lien vers un écran interdit ne doit pas apparaître.
- Les écrans 403 et 404 utilisent l’alerte `info` avec un titre explicite.
