Menu contextuel d’actions (ligne, carte, droits accordés) ou menu du compte, ouvert par un bouton icône ou un bouton texte.

**Gabarit :** `components/action_menu.html.twig`

```twig
{% include 'components/action_menu.html.twig' with { id: 'grant-actions', label: 'Actions sur DEM-2026-0156', items: [{ label: 'Modifier les droits', icon: 'settings', href: … }, { separator: true }, { label: 'Révoquer…', icon: 'x', danger: true, href: … }] } only %}
```

## Utilisation

- Ne listez que les actions permises au rôle. Une action permise mais impossible pour l’instant est passée `disabled` (grisée).
- Les actions destructives sont en dernier, après un séparateur, en `danger`, et ouvrent une confirmation.
- Clavier (`cp--dropdown`) : flèches, Début, Fin, Échap.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
