Onglets pour basculer entre des listes de même niveau sans changer de page.

**Gabarit :** `components/tabs.html.twig`

```twig
{% include 'components/tabs.html.twig' with { id: 'other', label: 'Autres événements', selected: 'drafts', tabs: tabs } only %}
```

## Utilisation

- Posez `data-controller="cp--tabs"` sur un parent commun à la liste et aux panneaux. Chaque panneau : `role="tabpanel"`, `id="{id}-{tab}-panel"`, `aria-labelledby="{id}-{tab}"`, `hidden` sauf l’actif.
- Affichez le nombre d’éléments dans chaque onglet, y compris zéro : l’onglet vide montre un EmptyState.
- Clavier : flèches gauche / droite, Début, Fin.

## À éviter

- Ne pas utiliser d’onglets pour des étapes successives (assistant).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
