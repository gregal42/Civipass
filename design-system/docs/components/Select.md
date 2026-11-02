Liste déroulante native, stylée, pour choisir parmi quelques options connues.

**Gabarit :** `components/select.html.twig`

```twig
{% include 'components/select.html.twig' with { id: 'statut', label: 'Statut', options: [{ value: '', label: 'Tous les statuts' }, …] } only %}
```

## Utilisation

- Au-delà d’une dizaine d’options ou s’il faut chercher dans la liste, préférez SearchField ou des cartes de choix.
- `hide_label` masque le libellé visuellement sans le retirer pour les lecteurs d’écran.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
