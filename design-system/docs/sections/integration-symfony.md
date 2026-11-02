# Intégration Symfony

Le design system est conçu pour Symfony + Twig + Stimulus (StimulusBundle) + Turbo, sans framework SPA.

## Fichiers à reprendre

| Source (dépôt du design system) | Destination dans l’application |
|---|---|
| `dist/tokens.css`, `dist/civipass.css` | `assets/styles/` (importés dans `assets/app.css`, tokens en premier) |
| `fonts/*.woff2` | `assets/fonts/` (les chemins sont relatifs dans `tokens.css` : à ajuster selon l’emplacement) |
| `templates/components/*.html.twig` | `templates/components/` |
| `templates/layouts/app.html.twig`, `public.html.twig` | `templates/` (gabarits de base à étendre) |
| `src/controllers/cp/*_controller.ts`, `events.ts` | `assets/controllers/cp/` |

Avec AssetMapper, StimulusBundle enregistre automatiquement `assets/controllers/cp/dropdown_controller.ts` sous l’identifiant `cp--dropdown`. Les fichiers TypeScript se compilent avec `sensiolabs/typescript-bundle` ; le fichier `events.ts` n’est pas un contrôleur et n’est pas enregistré. `@hotwired/stimulus` doit être dans l’importmap. Le fichier `dist/civipass.js` (Stimulus + contrôleurs en un seul script) ne sert qu’à la documentation et aux prototypes.

## Utiliser un composant

```twig
{% include 'components/status_pill.html.twig' with { status: 'pending' } only %}

{% embed 'components/panel.html.twig' with { id: 'req-h', title: 'Ce qui est demandé' } only %}
  {% block body %}{% include 'components/facts.html.twig' with { items: facts } only %}{% endblock %}
{% endembed %}
```

- Passez toujours `only` : les gabarits ne doivent pas hériter des variables de la page (`size`, `label`…).
- Les variables de chaque gabarit sont décrites en tête de fichier (`@param`) et dans `index.d.ts`.
- Les paramètres `attrs` et `html` reçoivent du HTML de confiance, non échappé. N’y passez jamais de donnée saisie par un administré.
- Les gabarits n’utilisent que du Twig standard (pas de TwigComponent requis). Ils pourront devenir des composants anonymes `<twig:…>` sans changer de balisage.

## Contrôleurs Stimulus

| Identifiant | Rôle |
|---|---|
| `cp--dropdown` | Menu du sélecteur de commune / d’événement |
| `cp--tabs` | Onglets accessibles |
| `cp--disclosure` | Déploiement inline du formulaire d’approbation ou de refus |
| `cp--unsaved-guard` | Confirmation avant de quitter un formulaire modifié (Turbo, fermeture d’onglet) |
| `cp--dialog` | Boîte de dialogue de confirmation globale (`cp:confirm`) |
| `cp--recap` | Récapitulatif mis à jour en direct |
| `cp--choice-trace` | Note « sera tracé dans l’historique » quand l’agent s’écarte de la demande |
| `cp--plate-list` | Plaques : ajout, retrait, désactivation sans véhicule |
| `cp--submit-state` | Bouton bloqué pendant l’envoi (`turbo:submit-start/end`) |
| `cp--toast` | Zone de toasts (`cp:toast`) |
| `cp--autofocus` | Focus sur un résumé d’erreurs ou un message de succès |
| `cp--modal` | Modale et feuille mobile (`<dialog>`), ouvertes par `data-modal-open` |
| `cp--toggle` | Filtres repliés en compact |
| `cp--file-upload` | Dépôt de justificatifs : liste, refus expliqués, retrait |
| `cp--zone-select` | Communes puis zones (« Commune entière » / « Certaines zones ») |

Les contrôleurs ne portent aucune règle métier : la validation, les droits et l’audit restent côté serveur. Après une décision, le serveur renvoie la fiche (Turbo Drive ou Turbo Frame), avec l’alerte de succès `focus: true` et le statut mis à jour.

## Turbo

- `cp--unsaved-guard` intercepte `turbo:before-visit` ; sans Turbo, il protège seulement la fermeture d’onglet.
- `cp--submit-state` remet le bouton à l’état initial sur `turbo:submit-end`. En cas d’erreur (réponse 422), le serveur renvoie le formulaire avec ses valeurs et le résumé d’erreurs.

## Construire

```bash
npm install
npm run build            # dist/ : tokens.css, civipass.css, civipass.js, galerie d’exemples
npm run typecheck        # contrôle TypeScript des contrôleurs
npm run contrast         # contrôle des contrastes des tokens
npm run prototype        # dist/prototype/ : prototype navigable UI-001 (pages Twig + données fictives)
```

`tokens/tokens.json` est la source unique des couleurs, de la typographie, des espacements, des rayons et des dimensions. Ne modifiez pas `dist/tokens.css` à la main.
