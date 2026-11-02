Feuille « Menu » de la navigation mobile : rubriques secondaires autorisées, changement de commune, déconnexion.

**Gabarit :** `components/mobile_menu.html.twig`

```twig
{% include 'components/mobile_menu.html.twig' with { items: menu_items, commune: commune, user: user } only %}
```

## Utilisation

- Ouverte par l’entrée « Menu » de la barre basse (`data-modal-open="cp-mobile-menu"`). Le gabarit `layouts/app.html.twig` l’inclut déjà.
- Elle reprend le nom, le rôle et la commune active en tête.
- Échap, le bouton Fermer ou un clic sur le voile la ferment ; le focus revient sur « Menu ».

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
