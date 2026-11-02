Recherche et filtres d’une liste, avec filtres actifs en puces, nombre de résultats et effacement.

**Gabarit :** `components/filter_bar.html.twig`

```twig
{% embed 'components/filter_bar.html.twig' with { id: 'filters', search: {...}, count_label: '153 demandes' } only %}{% block filters %}…{% endblock %}{% endembed %}
```

## Utilisation

- Sous 640 px, les filtres se replient derrière « Filtres (n) » ; la recherche reste visible.
- Chaque filtre actif devient une puce supprimable. « Tout effacer » remet la liste à zéro.
- Le nombre de résultats est annoncé (`role=status`).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
