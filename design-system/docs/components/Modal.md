Modale générique (<dialog>) pour consulter un contenu sans quitter la page : justificatif, aide.

**Gabarit :** `components/modal.html.twig`

```twig
{% embed 'components/modal.html.twig' with { id: 'doc-viewer', title: 'Justificatif', size: 'wide', has_foot: true } only %}…{% endembed %}
```

## Utilisation

- Tout élément `data-modal-open="{id}"` l’ouvre. Échap, Fermer ou un clic sur le voile la ferment ; le focus revient au déclencheur.
- Tailles : défaut 640 px, `wide` 960 px, `sheet` (feuille basse, menu mobile).

## À éviter

- Jamais pour approuver ou refuser une demande : la décision se prend dans la fiche.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
