État vide : dit ce qui apparaîtra ici et comment le faire apparaître.

**Gabarit :** `components/empty_state.html.twig`

```twig
{% include 'components/empty_state.html.twig' with { title: 'Aucun événement suspendu', text: '…' } only %}
```

## Utilisation

- Également pour les données indisponibles (icône `wifioff`) : ne jamais afficher de valeur par défaut à la place.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
