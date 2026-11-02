Liste de demandes : tableau en largeur, cartes en compact ; chaque ligne mène à la fiche dossier.

**Gabarit :** `components/request_list.html.twig`

```twig
{% include 'components/request_list.html.twig' with { rows: [{ ref, name, territory, when, status, href }] } only %}
```

## Utilisation

- Les colonnes se réduisent avec la place : le territoire disparaît sous 880 px, puis la ligne devient une carte sous 560 px (nom et statut, puis référence et heure).
- Une ligne sans `href` n’est pas cliquable (dossier hors de la commune, droits insuffisants).
- Le pied de liste indique le filtre ou la pagination.

## À éviter

- Ne pas ouvrir la fiche dans un panneau latéral seul : la fiche est une page entière.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
