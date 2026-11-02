# Provenance du design system Civipass

> Fichier généré par `design-system/tools/import.mjs` — ne pas modifier à la main. Les empreintes de `provenance.json` sont contrôlées par un test (`DesignSystemIntegrityTest`) : modifier un fichier importé sans repasser par l'outil fait échouer `make quality`.

| | |
|---|---|
| Source | archive « civipass-design-system 2 » (dépôt du design system, `package.json` : `civipass-design-system` 1.0.0) |
| Référentiel de tokens | version 1 — Prototype UI Civipass V1 (direction « Signalétique civique »), validé le 01/11/2026 |
| Date d'import | 2026-11-02 |
| Contenu importé | 64 gabarits de composants, 2 layouts du design system, 56 exemples, 15 contrôleurs Stimulus, 2 feuilles de style, 5 polices, 218 fichiers au total dont 27 adaptés |

## Où se trouve chaque chose

| Source (archive) | Destination (Civipass) | Statut |
|---|---|---|
| `dist/tokens.css` | `assets/styles/civipass/tokens.css` | adapté (A1–A3) |
| `dist/civipass.css` | `assets/styles/civipass/civipass.css` | identique |
| `fonts/*.woff2`, `OFL.txt` | `assets/fonts/` | identique |
| `src/controllers/cp/*.ts` | `assets/controllers/cp/*.js` | transpilé TypeScript → JavaScript (A4) |
| `templates/components/*.twig` | `templates/components/` | identique sauf `topbar` et `mobile_menu` (A5, A6) |
| `templates/layouts/{app,public}.html.twig` | `templates/layouts/` | adapté (A7, A8) |
| `examples/*.twig` | `templates/dev/ui/examples/` | identique (galerie dev) |
| `prototype/templates/*.twig` | `templates/dev/ui/screens/` | copies adaptées (P1–P9), dev uniquement |
| `prototype/data.mjs`, `scripts/catalog.mjs` | `src/Shared/UI/DevUi/Resources/*.json` | exportés sans modification, dev uniquement |
| `tokens/tokens.json`, `docs/` | `design-system/` | identique (référence) |

**Non importés** : `node_modules/`, `dist/` (sauf les deux CSS ci-dessus), `scripts/` de construction, `prototype/proto.js`, `proto.css`, `viewer.html` (simulation du serveur par le prototype), `package*.json`, `tsconfig.json`. **Créés par Civipass, hors design system** : `assets/styles/civipass-app.css`, `templates/layouts/auth.html.twig`, les pages d'authentification recomposées, `templates/dev/ui/*` (galerie), `src/Shared/UI/DevUi/*`.

## Adaptations appliquées (44 substitutions, vérifiées une à une à chaque import)

| Id | Fichier(s) | Description |
|---|---|---|
| A1 | `assets/styles/civipass/tokens.css` | Chemins des polices ajustés à l’emplacement AssetMapper (assets/styles/civipass → assets/fonts). |
| A2 | `assets/styles/civipass/tokens.css` | Classes typographiques préfixées cp- (écart à signaler à la source : tokens.css devrait les générer préfixées). |
| A3 | `assets/styles/civipass/tokens.css` | Classes .eyebrow, .plate, .plate-lg retirées : doublons des composants .cp-eyebrow et .cp-plate, avec risque de collision. |
| A4 | `assets/controllers/cp/disclosure_controller.js`, `assets/controllers/cp/plate_list_controller.js`, `assets/controllers/cp/unsaved_guard_controller.js` | Import relatif « ./events » → « ./events.js » (le navigateur et AssetMapper exigent l’extension). |
| A6 | `templates/components/mobile_menu.html.twig` | Déconnexion du menu mobile : formulaire POST avec jeton CSRF. |
| A5 | `templates/components/topbar.html.twig` | Déconnexion : lien GET remplacé par un formulaire POST avec jeton CSRF « logout » (la déconnexion Civipass est POST uniquement). |
| A7 | `templates/layouts/app.html.twig`, `templates/layouts/public.html.twig` | Feuilles de style chargées par <link> (pas par import JavaScript : pas de flash de page non stylée, fonctionne sans JS) et meta Turbo no-cache (pages à données personnelles). |
| A8 | `templates/layouts/app.html.twig`, `templates/layouts/public.html.twig` | Point d’entrée JavaScript AssetMapper (Stimulus, Turbo, contrôleurs cp--*). |
| P1 | `templates/dev/ui/screens/dashboard.html.twig`, `templates/dev/ui/screens/requests.html.twig`, `templates/dev/ui/screens/request.html.twig`, `templates/dev/ui/screens/forbidden.html.twig` | Layout de prévisualisation Civipass (layouts/app + bandeau « données fictives ») à la place du layout statique du prototype. |
| P2 | `templates/dev/ui/screens/dashboard.html.twig`, `templates/dev/ui/screens/requests.html.twig`, `templates/dev/ui/screens/request.html.twig`, `templates/dev/ui/screens/forbidden.html.twig` | Liens relatifs du prototype (tableau-de-bord.html, demandes.html…) remplacés par les routes /dev/ui via la variable « links ». |
| P3 | `templates/dev/ui/screens/dashboard.html.twig`, `templates/dev/ui/screens/requests.html.twig`, `templates/dev/ui/screens/request.html.twig`, `templates/dev/ui/screens/forbidden.html.twig` | Liens « #todo:… » (écrans prévus dans un lot ultérieur) remplacés par « # » : le prototype les signalait par un toast simulé, non repris. |
| P4 | `templates/dev/ui/screens/dashboard.html.twig`, `templates/dev/ui/screens/requests.html.twig`, `templates/dev/ui/screens/request.html.twig`, `templates/dev/ui/screens/forbidden.html.twig` | Attributs data-proto-* (simulation du serveur par proto.js) retirés. |
| P8 | `templates/dev/ui/screens/requests.html.twig` | Valeurs courantes des filtres transmises au bloc « filters » (embed … only). |
| P5 | `templates/dev/ui/screens/requests.html.twig` | Gabarits <template> et JSON de simulation du prototype retirés ; filtres, tri et pagination sont rendus côté serveur (données fictives). |
| P6 | `templates/dev/ui/screens/request.html.twig` | Zone de message : alerte de prévisualisation après envoi d’un formulaire (simulation du serveur retirée). |
| P9 | `templates/dev/ui/screens/forbidden.html.twig` | Lien de retour : « links » transmis au bloc de l’alerte (embed … only). |
| P7 | `src/Shared/UI/DevUi/Resources/prototype.json` | Données fictives du prototype (aucune personne réelle) exportées en JSON depuis data.mjs, sans modification. |

## Écarts constatés dans l'archive (à corriger à la source)

1. **`tokens.css`** génère des classes typographiques **non préfixées** (`.h1`, `.small`, `.caption`, `.body`, `.display`, `.eyebrow`, `.plate`…) alors que la documentation parle de `cp-h1`, `cp-body`… ; `.h1` et `.small` existent dans Bootstrap (Sonata). **Adaptation temporaire A2/A3** ; correction attendue à la source : préfixer en `cp-` (hors `eyebrow`/`plate`, déjà composants).
2. **Chemins de polices** relatifs à `dist/` (`fonts/…`) : à ajuster selon l'emplacement (A1). Un chemin configurable à la construction éviterait l'adaptation.
3. **Déconnexion en lien GET** (`topbar`, `mobile_menu`) : incompatible avec une déconnexion POST + CSRF (A5, A6). À prévoir à la source : un paramètre de formulaire de déconnexion.
4. **Layouts sans points d'accroche d'assets** (blocs `stylesheets`/`javascripts` vides) : renseignés à l'import (A7, A8). Meta Turbo `no-cache` ajoutée (pages à données personnelles).
5. **Contrôleurs en TypeScript** : transpilés hors Civipass, JavaScript seul importé ; l'import `./events` sans extension est réécrit en `./events.js` (A4).
6. **5 styles en ligne** dans des gabarits de composants (`file_upload`, `mobile_menu`, `data_table`, `freshness`, `skeleton`) : à revoir avant une politique CSP stricte.
7. **Le prototype** utilise des liens « #todo:… » et une simulation du serveur (`proto.js`) : non repris ; filtres, tri et pagination de la liste sont rendus côté serveur dans la prévisualisation.
8. **Points fonctionnels non tranchés** (22, dont l'affichage des décisions des autres communes, le journal des scans, la consultation par l'administré) : voir `design-system/docs/sections/etats-et-arbitrages.md`. Ils ne sont pas des choix d'interface : ils relèvent des règles métier et de `docs/security.md`.

## Mettre à jour le design system

1. Placer la nouvelle archive hors dépôt (ex. `DesignImport/`, ignoré par Git).
2. `node design-system/tools/import.mjs "<archive>"` (poste de développement ; l'outil n'écrit rien dans l'archive).
3. Si une adaptation échoue (« n occurrence(s) … attendue(s) »), l'archive a changé de forme : revoir l'adaptation dans l'outil, jamais corriger le fichier importé.
4. `git diff` pour relire, `make quality` (compilation des 56 exemples, intégrité, auth), consulter `/dev/ui`.
5. Commiter l'import seul (outil, fichiers importés, `provenance.json`, `PROVENANCE.md`).
