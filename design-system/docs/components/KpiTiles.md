Indicateurs synthétiques : demandes en attente, approuvées, refusées, laissez-passer valides.

**Gabarit :** `components/kpi_tiles.html.twig`

```twig
{% include 'components/kpi_tiles.html.twig' with { tiles: [{ label: 'Demandes en attente', value: 18, urgent: true }, …] } only %}
```

## Utilisation

- Une seule tuile `urgent` (fond ambre) : ce qui attend une action.
- Le pied de tuile dit la portée de la valeur (« à instruire par Le Temple », « depuis l’activation »).
- 4 colonnes au-dessus de 680 px de conteneur, 2 × 2 en dessous.

## À éviter

- Pas de graphique ni d’export en V1 (référentiel §4).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
