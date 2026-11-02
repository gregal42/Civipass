Champ de recherche avec icône : nom, téléphone, e-mail, plaque ou référence.

**Gabarit :** `components/search_field.html.twig`

```twig
{% include 'components/search_field.html.twig' with { id: 'q', label: 'Rechercher une demande', placeholder: 'Nom, téléphone, e-mail, plaque ou référence' } only %}
```

## Utilisation

- Le libellé reste visible. Le texte d’exemple liste les critères acceptés.
- Dans une liste, la recherche filtre au fil de la frappe (Turbo Frame) ; sur le tableau de bord, elle envoie vers la liste.
- La recherche ignore espaces et tirets pour les plaques et téléphones (règle serveur).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
