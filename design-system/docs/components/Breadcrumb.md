Fil d’Ariane au-dessus de 640 px, remplacé par un lien retour en dessous.

**Gabarit :** `components/breadcrumb.html.twig`

```twig
{% include 'components/breadcrumb.html.twig' with { items: [...], back: { label: 'Demandes · Incendie', href: path } } only %}
```

## Utilisation

- Le dernier élément est la page courante (`aria-current`). Passez `mono: true` pour une référence.
- Le lien retour mène au niveau parent, pas à la page précédente de l’historique.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
