Données d’un dossier sous forme de liste de définitions : libellé à gauche, valeur à droite, puis empilés en compact.

**Gabarit :** `components/facts.html.twig`

```twig
{% include 'components/facts.html.twig' with { items: [{ label: 'Motif', value: …, note: '· sans commentaire' }, { label: 'Immatriculations', html: plates }] } only %}
```

## Utilisation

- Mettez en premier ce qui fonde la décision : motif, territoire, déplacements, plaques, justificatifs.
- `html` accepte d’autres composants (plaques, puces de mode). `note` ajoute une précision discrète.
- Une valeur absente s’écrit (« Aucun justificatif joint »), elle ne reste pas vide.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
