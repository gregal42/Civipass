Carte de laissez-passer : QR, titulaire, référence, événement, validité, droits par commune, plaques, actions.

**Gabarit :** `components/pass_card.html.twig`

```twig
{% include 'components/pass_card.html.twig' with { ref: 'LP-2026-0156', holder, event, qr: { svg }, validity: { state: 'valid', detail }, rights: [...], plates: [...], actions: [...] } only %}
```

## Utilisation

- Jamais de coordonnées personnelles.
- Les actions dépendent des permissions (PDF, impression). Modifier ou révoquer se fait depuis le dossier, pas depuis la liste des laissez-passer (référentiel §9).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
