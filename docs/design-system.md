# Civipass — Design system dans Symfony

Le design system Civipass (direction « Signalétique civique ») est intégré sous forme de **copie versionnée**. Provenance, adaptations et écarts : `design-system/PROVENANCE.md` (généré). Règles d'usage (couleur, ton, typographie, accessibilité) : `design-system/docs/README.md` et `design-system/docs/sections/`.

## Où est quoi

| Chemin | Rôle |
|---|---|
| `assets/styles/civipass/tokens.css`, `civipass.css` | Tokens (clair/sombre) et CSS des composants, scopés sous `.cp-root` |
| `assets/styles/civipass-app.css` | **Extensions Civipass** (hors design system) : écrans d'authentification, prévisualisation. N'utilise que les tokens |
| `assets/fonts/` | Atkinson Hyperlegible Next et Mono (WOFF2, licence OFL : `OFL.txt`), servis par l'application, sans CDN |
| `assets/controllers/cp/*.js` | 15 contrôleurs Stimulus `cp--*` (JavaScript généré depuis le TypeScript du design system) |
| `templates/components/` | 64 gabarits de composants |
| `templates/layouts/app.html.twig`, `public.html.twig` | Layouts du design system (espace municipal, écrans publics/contrôle) |
| `templates/layouts/auth.html.twig` | Layout Civipass des pages d'authentification (composition de composants du design system) |
| `templates/dev/ui/`, `src/Shared/UI/DevUi/` | Prévisualisation, **dev uniquement** |
| `design-system/` | Provenance, `tokens.json`, documentation du design system, outil d'import |

## Utiliser un composant

```twig
{% include 'components/button.html.twig' with { label: 'Enregistrer', type: 'submit', size: 'l' } only %}

{% embed 'components/panel.html.twig' with { id: 'titre-h', title: 'Titre', level: 1 } only %}
  {% block body %}…{% endblock %}
{% endembed %}
```

Règles :
- **Toujours `only`** : un composant ne doit pas hériter des variables de la page. Avec `embed … only`, les variables nécessaires **dans les blocs** doivent être passées dans le `with`.
- Les paramètres `attrs` et `html` reçoivent du **HTML de confiance, non échappé** (23 `|raw` dans les gabarits). **N'y jamais passer une donnée saisie par un utilisateur ou un administré.**
- Les variables de chaque gabarit sont décrites en tête de fichier (`@param`).
- Une page d'application étend `layouts/app.html.twig` (variables : navigation autorisée, commune et événement actifs, rôle… **construites côté serveur à partir des droits réels**, jamais depuis la requête) ; une page publique ou d'authentification étend `layouts/public.html.twig` ou `layouts/auth.html.twig`.
- Les feuilles de style se chargent par `<link>` dans les layouts, **pas** par `import` JavaScript (pas de flash de page non stylée ; les pages d'authentification fonctionnent sans JavaScript). `assets/app.js` ne démarre que Stimulus et Turbo.

## Stimulus, Turbo, AssetMapper

- **Stimulus** : StimulusBundle enregistre `assets/controllers/cp/dropdown_controller.js` sous `cp--dropdown`. `events.js` n'est pas un contrôleur. Les contrôleurs ne portent aucune règle métier.
- **Turbo Drive** est actif sur tout le site (`symfony/ux-turbo`). Conséquences : les layouts posent `turbo-cache-control: no-cache` (pas d'aperçu mémorisé de pages à données personnelles) ; un formulaire qui échoue répond **422** ; un lien vers Sonata (`/admin`, ses propres scripts) doit porter `data-turbo="false"`.
- **Déconnexion** : POST + jeton CSRF `logout` (les menus du design system ont été adaptés, voir adaptations A5/A6).
- **AssetMapper** : les `url()` des polices sont réécrites avec empreinte ; en production, activer la compression du serveur (Nginx `gzip`/`brotli`), AssetMapper ne minifie pas.
- **Pas de build front dans Civipass** : aucun TypeScript, aucun bundler. La transpilation se fait hors projet, par `design-system/tools/import.mjs`.

## Prévisualisation (`/dev/ui`, environnement dev uniquement)

- `/dev/ui` : galerie des 56 composants (largeurs 375/768/1440, thème clair/sombre) et des écrans du prototype UI-001 : tableau de bord (Le Temple et Saumos), liste des demandes (recherche, filtres, tri, pagination rendus côté serveur), fiches (en attente, approuvée, refusée, deux communes) et écran 403.
- **Données fictives** (aucune personne réelle), **aucune entité Doctrine, aucune base, aucun droit, aucune écriture**. Les formulaires de décision ne font rien et le disent. Le QR est un motif fictif non scannable (la bibliothèque de QR arrivera avec le lot Laissez-passer).
- **Jamais en production**, trois verrous indépendants : `#[Route(env: 'dev')]` (les routes n'existent pas hors dev), `#[When(env: 'dev')]` (le service n'est pas enregistré hors dev), garde d'exécution dans le contrôleur. Testé dans les environnements `test` et `prod` (`DesignPreviewTest`).

## Navigateurs visés

Chrome, Edge, Firefox et Safari modernes (2023 et après), avec attention à Safari sur iPhone. Le design system s'appuie sur des fonctions récentes : *container queries* (Chrome/Edge 105, Firefox 110, **Safari/iOS 16.0**), `:has()` (Firefox 121, Safari 15.4), `color-mix()` (Chrome/Edge 111, Firefox 113, **Safari/iOS 16.2**), `overflow: clip`, `dvh`, `env(safe-area-inset-*)` (zones de sécurité des iPhone). **Plancher retenu : Chrome/Edge 111, Firefox 121, Safari/iOS 16.4.** En dessous (iOS ≤ 16.1 notamment), la mise en page se dégrade (container queries et `color-mix` absents). `text-wrap: balance` (Safari 17.5) est une amélioration progressive. Ces plafonds n'ont pas été vérifiés sur appareils réels : **recette Safari iPhone à faire** avant tout écran de contrôle.

## Mettre à jour

Voir « Mettre à jour le design system » dans `design-system/PROVENANCE.md`. Principe : **ne jamais corriger un fichier importé à la main** ; le test `IntegrityTest` compare les empreintes de `design-system/provenance.json`. Corriger l'outil d'import (adaptation) ou, mieux, la source du design system.

## LOT UI-02 (importé le 3 novembre 2026)

L'archive `DesignImport/UI-02/` (v1.0.0, tokens inchangés) a été importée avec :

```bash
node design-system/tools/import.mjs DesignImport/UI-02 DesignImport/UI-01
```

- **Deuxième argument** (facultatif) : archive d'où viennent les **écrans et données fictives de la galerie `/dev/ui`**. Il reste sur UI-01 : le contrat de variables de `dashboard` et `forbidden` a changé dans UI-02 et ces écrans de prévisualisation ne sont pas ceux de l'application. Les composants, le CSS, les contrôleurs et les exemples viennent d'UI-02.
- UI-02 n'embarque pas `node_modules` : lier celui d'UI-01 (`ln -s ../UI-01/node_modules DesignImport/UI-02/node_modules`) ou lancer `npm install` dans l'archive.
- Ajoutés : `event_header`, `zone_card`, `validity_slot`, `validity_form`, `bulk_bar`, `invitation_card`, `commune_status`, `subnav`, `lifecycle`, `stepper` et `zone_map` (obsolètes, non utilisés), contrôleurs `cp--validity` et `cp--bulk`, CSS des zones et des événements. Rétrocompatibles : `Button` (`unavailable_reason`), `Timeline`, `cp--unsaved-guard`.
- **Utilisés par l'incrément 1** (espace municipal, `/communes/…`) : `event_header`, `subnav`, `lifecycle`, `commune_status`. Les autres attendent leurs écrans (zones, créneaux, invitations, groupé).
- **Écarts connus à traiter avant les incréments concernés** : voir `docs/revisions/ECARTS-UI-02.md` §« État au 3 novembre 2026 ». En particulier `validity_form` / `cp--validity` (heure de Paris codée en dur, messages d'heure inexistante ou ambiguë contraires au moteur) avant l'incrément Créneaux.
- **Adaptation A9** (recette visuelle de l'incrément 1) : le composant `lifecycle` présentait comme opérationnelles des fonctions des lots Demandes et Laissez-passer (« formulaire public ouvert, demandes instruites », « droits inutilisables », « plus aucune demande ni droit »). Ses quatre sous-titres sont remplacés par des formulations neutres dans `import.mjs`, jamais à la main.
