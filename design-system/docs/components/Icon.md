Icône en ligne de 24 px, trait de 2 px, qui prend la couleur du texte.

**Gabarit :** `components/icon.html.twig`

```twig
{% include 'components/icon.html.twig' with { name: 'check', size: 's' } only %}
```

## Utilisation

- Accompagnez toujours l’icône d’un libellé visible ; seule, passez `label` pour lui donner un nom accessible.
- Tailles : `s` 16 px (dans une ligne de texte), `m` 20 px (boutons, navigation), `l` 28 px (carte d’événement compacte), `xl` 40 px (verdict de contrôle).
- Une nouvelle icône se dessine dans le même style (trait 2 px, extrémités arrondies) et s’ajoute à la table `paths` du gabarit.

## À éviter

- Ne pas utiliser d’emoji ni une autre bibliothèque d’icônes.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
