Liste des justificatifs d’une demande, avec consultation.

**Gabarit :** `components/documents.html.twig`

```twig
{% include 'components/documents.html.twig' with { docs: [{ name, type, size, date, modal: 'doc-viewer' }] } only %}
```

## Utilisation

- Nom, format, poids et date de dépôt. « Consulter » ouvre une modale de visualisation ou un lien.
- Sans justificatif : « Aucun justificatif joint. »

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
