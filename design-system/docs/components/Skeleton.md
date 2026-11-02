Squelette de chargement qui reprend la forme du contenu attendu.

**Gabarit :** `components/skeleton.html.twig`

```twig
{% include 'components/skeleton.html.twig' with { blocks: [{ h: 36, w: '320px' }, { h: 98 }] } only %}
```

## Utilisation

- La zone porte `aria-busy="true"` et un texte « Chargement… » pour les lecteurs d’écran.
- Au-delà de quelques secondes, remplacez-le par un message (Alert) plutôt que de le laisser tourner.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
