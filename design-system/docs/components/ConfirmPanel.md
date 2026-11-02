Panneau de confirmation inline pour une action irréversible ou lourde : ce qui va se passer, ce qui ne change pas, motif, bouton destructif.

**Gabarit :** `components/confirm_panel.html.twig`

```twig
{% embed 'components/confirm_panel.html.twig' with { id: 'revoke', title: 'Révoquer le laissez-passer pour Le Temple', confirm_label: 'Révoquer définitivement', consequences: [...], keeps: [...] } only %}…{% endembed %}
```

## Utilisation

- Les conséquences sont écrites avant le bouton, y compris les limites (appareils hors ligne). « Ce qui ne change pas » rassure (droits des autres communes, QR stable).
- Motif obligatoire pour la révocation et la suspension (référentiel §5.4, §9).
- Le bouton dit l’action exacte (« Révoquer définitivement »).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
