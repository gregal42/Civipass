Résumé d’autorisation : droits accordés commune par commune (période, zones, déplacements, plaques, qui et quand).

**Gabarit :** `components/authorization.html.twig`

```twig
{% include 'components/authorization.html.twig' with { grants: [{ commune, mine, by, period, zones, modes, plates }] } only %}
```

## Utilisation

- Votre commune est étiquetée ; les droits des autres communes sont en lecture seule.
- Une commune sans décision ou ayant refusé n’apparaît pas ici : voir CommuneDecisions.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
