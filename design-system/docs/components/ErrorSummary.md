Résumé des erreurs en tête de formulaire, focalisé à l’affichage, avec un lien vers chaque champ.

**Gabarit :** `components/error_summary.html.twig`

```twig
{% include 'components/error_summary.html.twig' with { errors: [{ target: 'validity', message: '…' }] } only %}
```

## Utilisation

- Affiché après une soumission refusée par le serveur (réponse 422) : les valeurs saisies sont conservées.
- Chaque message reprend l’erreur du champ, rédigée comme une consigne.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
