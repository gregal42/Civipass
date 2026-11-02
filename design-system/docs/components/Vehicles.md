Véhicules autorisés (plaques en grand) et autres modes autorisés, au contrôle.

**Gabarit :** `components/vehicles.html.twig`

```twig
{% include 'components/vehicles.html.twig' with { plates: ['AB-123-CD'], modes: ['foot'] } only %}
```

## Utilisation

- Sans plaque, le bloc affiche « Sans véhicule ».

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
