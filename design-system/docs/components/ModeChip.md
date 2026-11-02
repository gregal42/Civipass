Puce de mode de déplacement : véhicule, à pied, à vélo.

**Gabarit :** `components/mode_chip.html.twig`

```twig
{% include 'components/mode_chip.html.twig' with { mode: 'foot' } only %}
```

## Utilisation

- Les modes se cumulent : affichez-les côte à côte dans `.cp-modes`.
- « Véhicule » s’accompagne toujours des plaques autorisées.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
