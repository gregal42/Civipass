Communes et territoires demandés, avec la décision de chaque commune ; seule votre commune décide pour son territoire.

**Gabarit :** `components/commune_decisions.html.twig`

```twig
{% include 'components/commune_decisions.html.twig' with { items: [{ commune, territory, status, mine, who }] } only %}
```

## Utilisation

- Votre commune est mise en avant (« À instruire par vous »). Les autres sont en lecture seule (« Décision de Saumos »).
- Une commune pas encore active sur l’événement est `concerned` : aucune décision possible pour elle.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
