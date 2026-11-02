Indicateur de validité d’un laissez-passer : icône, libellé et précision de date.

**Gabarit :** `components/validity.html.twig`

```twig
{% include 'components/validity.html.twig' with { state: 'valid', detail: 'jusqu’au 05/11/2026 à 20:00' } only %}
```

## Utilisation

- États : valide, partiellement valide (certaines communes seulement), pas encore valide, expiré, révoqué, suspendu.
- La précision donne la date utile (fin, début, révocation).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
