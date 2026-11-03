> **Nouveau composant — LOT UI-02.** Aucune cartographie en V1 : une zone est décrite par du texte.

Zone d’un événement : libellé, type, état, commune qui la gère, description, résumé des créneaux de validité, actions permises.

**Gabarit :** `components/zone_card.html.twig` — à placer dans `<div class="cp-zones">` (grille de cartes).

```twig
{% include 'components/zone_card.html.twig' with { id: 'z-' ~ zone.id, name: zone.label, type: 'Rue', status: 'active', commune: 'Le Temple',
  description: zone.description, validity: summaries, href: path_zone, actions: allowedActions } only %}
```

## Utilisation

- Champs : ceux du SFD SCR-017 (libellé, type, description, état). Types : Commune entière, Quartier, Rue, Lieu-dit, Personnalisée.
- `status` :
  - `active` : la zone est active ;
  - `suspended` : la zone est suspendue ; elle neutralise aussi le droit « Commune entière » (SFD §6.2) ;
  - `unconfirmed` : « Commune entière » proposée, jamais active sans confirmation (SFD SCR-015). Le libellé « À confirmer » est l’hypothèse Q6, à confirmer.
- `validity_scope` : `event` affiche « Pendant toute la durée de l’événement », `periods` liste les résumés des créneaux. Sans validité, la carte affiche « Validité à configurer » : ce n’est pas un statut métier, la zone n’ouvre simplement aucun droit d’accès.
- `select` ajoute la case de sélection multiple (BulkBar), uniquement pour les zones que l’utilisateur peut modifier.
- Zone d’une autre commune : pas d’actions, `readonly` précise « Zone gérée par … » (décision D3).
- Seul l’administrateur communal de la commune voit les actions de création et de modification (décision D8).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
