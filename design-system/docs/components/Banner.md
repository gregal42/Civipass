Bannière pleine largeur sous la barre supérieure, pour une situation qui touche tout l’écran.

**Gabarit :** `components/banner.html.twig`

```twig
{% include 'components/banner.html.twig' with { variant: 'warning', text: 'Événement suspendu…', link: { label: 'Voir le motif', href: … } } only %}
```

## Utilisation

- `warning` : événement ou participation suspendu (les droits sont inutilisables). `info` : changement de commune, information de contexte. `assist` : mode assistance CWS, permanent (référentiel §11), avec retour à l’administration.
- Une seule bannière à la fois ; le message dit la conséquence.

## À éviter

- Ne pas l’utiliser pour le résultat d’une action : utiliser Alert dans la page.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
