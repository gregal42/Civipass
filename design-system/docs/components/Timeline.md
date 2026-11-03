Historique audité d’un dossier, du plus ancien au plus récent.

**Gabarit :** `components/timeline.html.twig`

```twig
{% include 'components/timeline.html.twig' with { items: [{ time, text, kind: 'agent' }] } only %}
```

## Utilisation

- `ok` pour une approbation ou une émission, `bad` pour un refus ou une révocation, `agent` pour une action ou un écart de l’agent (zone ajoutée, plaque retirée).
- Chaque écart par rapport à la demande apparaît sur sa propre ligne.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.

## Proposition UI-002 : valeurs avant / après (`detail`)

> **Évolution additive, à valider.** Sans `detail`, le rendu d’un item est strictement inchangé.

Pour une modification tracée (nom, dates, description d’un événement), chaque item peut porter `detail: [{ label, before, after }]`. L’ancienne valeur est barrée, la nouvelle en gras, et les lecteurs d’écran entendent « Avant : … Après : … ».

```twig
{ time: '01/11/2026 · 08:20', text: 'Informations modifiées par Sophie Lambert.', kind: 'agent',
  detail: [ { label: 'Fin prévue', before: '04/11/2026 à 20:00', after: '05/11/2026 à 20:00' } ] }
```
