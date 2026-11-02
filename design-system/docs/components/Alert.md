Message dans la page : succès, erreur, avertissement, information, note discrète ou ligne d’état.

**Gabarit :** `components/alert.html.twig`

```twig
{% include 'components/alert.html.twig' with { variant: 'success', title: 'Demande approuvée — laissez-passer LP-2026-0156 émis', text: '…', focus: true } only %}
```

## Utilisation

- `success` après une décision (avec `focus: true`). `error` pour un échec d’enregistrement (`role=alert`). `warning` pour une restriction en cours (participation ou zone suspendue). `info` pour 403 / 404. `quiet` pour une précision réglementaire. `inline` pour une ligne d’état (dossier réservé).
- Le titre dit ce qui s’est passé ; le texte dit la conséquence ou quoi faire. `{% block content %}` accepte un lien.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
