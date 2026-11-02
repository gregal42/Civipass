Droits par commune et zone, avec leur période ; toutes les communes sont listées, sans sélection préalable.

**Gabarit :** `components/rights.html.twig`

```twig
{% include 'components/rights.html.twig' with { rights: [{ commune, zone, period, status: 'valid' }] } only %}
```

## Utilisation

- Hors ligne, chaque droit porte `sync` (« Selon synchro ») et le titre peut le rappeler.
- Un droit non valide reste listé, barré, avec sa raison : affichage d’un droit partiel proposé, à arbitrer (référentiel §14).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
