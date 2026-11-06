Tableau de données : `<table>` en large, cartes empilées sous 640 px de conteneur, tri par en-tête.

**Gabarit :** `components/data_table.html.twig`

```twig
{% include 'components/data_table.html.twig' with { caption: '1–20 sur 153 demandes', columns: [...], rows: [...] } only %}
```

## Utilisation

- Chaque colonne déclare son rôle en compact : `title` (lien, ligne entière cliquable), `status`, `sub`, `aside`, `full` ou `hide`.
- Les colonnes `wide` disparaissent sous 880 px. Le tri se fait par lien GET (`aria-sort` sur la colonne triée).
- La légende, sous le tableau, dit la portée et le tri. Une liste vide affiche un état vide qui propose de modifier la recherche.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.


## LOT 03 — ligne ouvrable
Quand la cellule titre contient un lien `.cp-table__link`, toute la ligne ouvre la fiche (lien étiré). Survol : fond, curseur et nom souligné. En cartes (< 640 px) : chevron à droite et hauteur tactile minimale. Le lien reste le seul élément focalisable de la ligne.
