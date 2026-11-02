Plaques autorisées dans une approbation : cocher, retirer, ajouter une immatriculation ; désactivé si le mode Véhicule n’est pas accordé.

**Gabarit :** `components/plates_field.html.twig`

```twig
{% include 'components/plates_field.html.twig' with { id: 'plates', name: 'plates[]', vehicle: 'mode-vehicle', plates: [{ number: 'AB-123-CD' }] } only %}
```

## Utilisation

- Contrôleur `cp--plate-list` : l’ajout normalise en capitales, refuse les doublons et ajoute une carte « Ajout agent ».
- Quand Véhicule est décoché, les plaques sont désactivées et un texte l’explique.
- Un véhicule accordé exige au moins une plaque (règle reprise du formulaire public, à confirmer pour l’agent).

## À éviter

- Le format des plaques n’est pas contrôlé : règle non tranchée.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
