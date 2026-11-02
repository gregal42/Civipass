Case à cocher ou bouton radio simple, pour des filtres et des préférences.

**Gabarit :** `components/check.html.twig`

```twig
{% include 'components/check.html.twig' with { id: 'c1', name: 'with_docs', label: 'Avec justificatifs' } only %}
```

## Utilisation

- Groupez-les dans un `<fieldset>` avec `<legend>` ; `.cp-checks--stack` les empile.
- Pour un choix qui accorde un droit (zone, mode, plaque), utilisez la carte de choix (Choice).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
