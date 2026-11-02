Pagination d’une liste : portée affichée et pages.

**Gabarit :** `components/pagination.html.twig`

```twig
{% include 'components/pagination.html.twig' with { from: 1, to: 20, total: 153, noun: 'demandes', page: 1, pages: 8, href_pattern: '?page=__PAGE__' } only %}
```

## Utilisation

- Affiche la première, la dernière et les pages voisines ; les autres sont résumées par « … ». Cibles de 44 px.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
