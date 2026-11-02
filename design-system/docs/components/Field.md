Champ de saisie (texte, e-mail, téléphone, date, heure, recherche, zone de texte) avec libellé, aide et erreur reliés.

**Gabarit :** `components/field.html.twig`

```twig
{% include 'components/field.html.twig' with { id: 'refuse-reason', type: 'textarea', label: 'Motif du refus', optional: true } only %}
```

## Utilisation

- Le libellé est toujours visible au-dessus du champ ; le texte d’exemple ne remplace pas le libellé.
- Signalez le champ facultatif plutôt que l’obligatoire quand la plupart sont obligatoires.
- L’erreur dit quoi corriger, sous le champ, avec une icône. `valid` affiche une validation positive (format vérifié).
- `email` et `tel` renseignent `autocomplete` et le clavier mobile adapté.
- `disabled` : champ non modifiable dans ce contexte, avec une aide qui dit pourquoi ; `readonly` : valeur affichée, non modifiable (référence).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
