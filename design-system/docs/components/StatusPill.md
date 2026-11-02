Pastille de statut : un point et un libellé, jamais la couleur seule.

**Gabarit :** `components/status_pill.html.twig`

```twig
{% include 'components/status_pill.html.twig' with { status: 'pending' } only %}
```

## Utilisation

- Demandes : `pending`, `approved`, `refused`. Événements : `active`, `draft`, `suspended`, `closed`. Participations communales : `concerned`, `invited`, `active`, `suspended`, `closed`, en passant `label` pour l’accord (« Active », « Suspendue », « Clôturée »). Droits au contrôle : `valid`, `invalid`, `revoked`, `sync` (selon la dernière synchronisation).
- L’ambre (`pending`, `active`) signale ce qui attend une action ; `draft`, `invited`, `concerned` ont un point creux ; `suspended` et `closed` un point carré, pour rester distincts sans la couleur.
- `size: 'l'` en en-tête de dossier.

## À éviter

- Ne pas inventer de statut : un nouvel état métier se décide d’abord dans le référentiel.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
