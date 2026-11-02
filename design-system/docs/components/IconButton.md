Bouton sans libellé visible pour une action secondaire répétée (fermer, plus d’actions, retirer un fichier).

**Gabarit :** `components/icon_button.html.twig`

```twig
{% include 'components/icon_button.html.twig' with { icon: 'more', label: 'Plus d’actions' } only %}
```

## Utilisation

- Le `label` est obligatoire : il devient le nom accessible et l’infobulle.
- Cible de 44 × 44 px. `outline` quand le bouton doit se voir sur une surface chargée, `danger` pour retirer ou supprimer.

## À éviter

- Ne pas l’utiliser pour l’action principale d’un écran : un bouton avec libellé est plus clair.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
