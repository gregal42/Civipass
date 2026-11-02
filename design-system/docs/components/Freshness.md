Fraîcheur des données de l’appareil de contrôle hors ligne, avec les seuils de 12 h et 24 h.

**Gabarit :** `components/freshness.html.twig`

```twig
{% include 'components/freshness.html.twig' with { synced_at: '02/11/2026 à 08:05', age: '10 h 15', hours: 10.25, level: 'Vigilance : …' } only %}
```

## Utilisation

- Affichée en permanence en mode hors ligne, avec le bouton « Actualiser ». La synchronisation automatique a lieu toutes les 15 min dès que le réseau revient.
- Au-delà de 24 h, la validation hors ligne est bloquée (Verdict `blocked`).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
