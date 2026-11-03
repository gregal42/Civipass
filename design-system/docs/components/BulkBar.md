> **Nouveau composant — LOT UI-02** (configuration en masse des zones, arbitrage du 2 novembre 2026).

Barre d’actions groupées sur une sélection multiple, avec le contrôleur `cp--bulk`.

**Gabarit :** `components/bulk_bar.html.twig`. Les cases à cocher sont portées par les cartes (ZoneCard, paramètre `select`).

```twig
<div data-controller="cp--bulk cp--disclosure">
  {% include 'components/bulk_bar.html.twig' with { id: 'bulk', noun: 'zone', scope_label: 'les 7 zones de Le Temple',
    all_label: 'Appliquer à toutes mes zones', all_attrs: 'data-action="cp--bulk#selectAll cp--disclosure#open" data-cp--disclosure-panel-param="bulk"',
    actions: [ { label: 'Configurer les zones sélectionnées', icon: 'clock', variant: 'primary', attrs: '…ouvre le panneau…' }, { label: 'Activer', icon: 'check' }, { label: 'Suspendre', icon: 'x' } ] } only %}
  …panneau de configuration (ValidityForm avec with_scope)…, cartes ZoneCard avec select: { name: 'zones[]', value: id, configured: bool, form: 'bulk-form' }
</div>
```

## Comportement

- Les actions restent désactivées tant que rien n’est sélectionné. Le nombre d’éléments sélectionnés est annoncé (`aria-live`).
- « Tout sélectionner » coche toutes les zones **de la commune de l’utilisateur**. Les zones des autres communes n’ont pas de case : elles ne peuvent pas être sélectionnées.
- La cible `summary` liste la sélection et prévient quand des zones ont déjà une validité.
- Avant de remplacer une validité existante, l’application demande une confirmation explicite qui nomme les zones concernées (ConfirmDialog).
- L’activation groupée rappelle qu’aucun laissez-passer n’est délivré et qu’aucun droit d’accès n’est accordé automatiquement.
- Le serveur refait toutes les vérifications : chaque zone doit appartenir à la participation de la commune.
- En compact, la barre n’est plus collée en haut de l’écran et ses actions passent sur toute la largeur.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
