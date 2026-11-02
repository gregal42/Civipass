Historique audité d’un dossier, du plus ancien au plus récent.

**Gabarit :** `components/timeline.html.twig`

```twig
{% include 'components/timeline.html.twig' with { items: [{ time, text, kind: 'agent' }] } only %}
```

## Utilisation

- `ok` pour une approbation ou une émission, `bad` pour un refus ou une révocation, `agent` pour une action ou un écart de l’agent (zone ajoutée, plaque retirée).
- Chaque écart par rapport à la demande apparaît sur sa propre ligne.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
