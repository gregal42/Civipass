Carte d’événement : la variante active met le compteur d’attente en avant et ouvre le tableau de bord de l’événement ; la variante compacte liste brouillons, suspendus et participations externes.

**Gabarit :** `components/event_card.html.twig`

```twig
{% include 'components/event_card.html.twig' with { id: 'ev', title: …, status: 'active', pending: 18, href: path('event_dashboard'), meta: [...], figures: [...] } only %}
```

## Utilisation

- La rubalise (hachures ambre / noir) est réservée à l’événement actif. Les événements actifs passent avant le reste.
- « Ouvrir l’événement » mène au tableau de bord opérationnel de l’événement, jamais directement à la liste des demandes.
- La variante compacte ne prend pas le dessus : brouillons, suspendus et participations externes vont dans des onglets.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
