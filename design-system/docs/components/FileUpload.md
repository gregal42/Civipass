Dépôt de justificatifs par glisser-déposer ou sélection, avec la liste des fichiers et les refus expliqués.

**Gabarit :** `components/file_upload.html.twig`

```twig
{% include 'components/file_upload.html.twig' with { id: 'docs' } only %}
```

## Utilisation

- Règles du référentiel : 5 fichiers maximum, 10 Mo par fichier, PDF / JPG / JPEG / PNG / DOC, dépôt facultatif.
- Chaque fichier refusé reste listé avec sa raison et n’est pas envoyé ; un bouton permet de retirer un fichier.
- Le serveur revalide tout ; aucun ajout de pièce après soumission en V1.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
