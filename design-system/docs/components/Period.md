Période de validité d’une autorisation : une seule période continue, date et heure de début, date et heure de fin.

**Gabarit :** `components/period.html.twig`

```twig
{% include 'components/period.html.twig' with { id: 'validity', value: { start_date, start_time, end_date, end_time } } only %}
```

## Utilisation

- Le fuseau est affiché dans la légende (« heure de Paris »).
- L’erreur « La fin de validité doit être postérieure au début » est vérifiée côté serveur, et peut l’être en direct.

## À éviter

- Pas de créneaux multiples ni de récurrence : une seule période (référentiel §7).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
