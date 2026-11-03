> **Composant UI-002, retenu par LOT UI-02.**

Cycle de vie d’un événement : Brouillon → Actif ⇄ Suspendu → Clôturé, avec l’état actuel et les dates.

**Gabarit :** `components/lifecycle.html.twig`

```twig
{% include 'components/lifecycle.html.twig' with { state: 'active',
  dates: { draft: 'Créé le 31/10/2026 à 21:50', active: 'Depuis le 31/10/2026 à 22:15' },
  note: 'Le formulaire public est ouvert.' } only %}
```

## Utilisation

- Affichage seul. Les transitions (activer, suspendre, réactiver, clôturer) sont des actions de la page, autorisées et vérifiées par le serveur.
- « Suspendu » est une étape facultative (pointillés). Pour un événement clos sans suspension, elle est barrée (« non utilisé »).
- L’état actuel porte `aria-current="step"` ; chaque état a un texte masqué (« passé », « état actuel », « à venir »).
- Sous 480 px de conteneur, la frise devient verticale.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
