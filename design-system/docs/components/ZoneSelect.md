Choix des communes puis des zones : « Commune entière » sans autre choix, ou « Certaines zones seulement ».

**Gabarit :** `components/zone_select.html.twig`

```twig
{% include 'components/zone_select.html.twig' with { id: 'terr', communes: [{ id: 'letemple', name: 'Le Temple', preselected: true, zones: [...] }] } only %}
```

## Utilisation

- Une commune peut être présélectionnée (lien propre à la commune) : elle est verrouillée et étiquetée.
- Les zones suspendues restent visibles, désactivées.
- Proposition pour le formulaire public (référentiel §8, étape 3) ; côté agent, l’approbation utilise des cartes de choix.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.


## LOT 03 — `zones_only`, `help`, `attrs`
Dans le formulaire de demande, une zone « Commune entière » est une zone comme les autres (LOT UI-02). Avec `zones_only: true`, le choix « Commune entière / Certaines zones » disparaît : cocher une commune affiche directement ses zones ouvertes aux demandes. L’erreur s’affiche sous la légende, reliée au groupe par `aria-describedby`.
