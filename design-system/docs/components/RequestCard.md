Carte de demande : demandeur, statut pour votre commune, référence, réception, territoires, modes, plaques, justificatifs.

**Gabarit :** `components/request_card.html.twig`

```twig
{% include 'components/request_card.html.twig' with { ref, name, href, status, when, territories: [...], modes: [...], plates: [...], documents: 2 } only %}
```

## Utilisation

- Toute la carte est cliquable vers la fiche. Votre commune est en gras dans les territoires.
- Utilisée sur le tableau de bord et dans les résultats compacts ; la liste paginée utilise DataTable, qui adopte la même hiérarchie en compact.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
