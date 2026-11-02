Boîte de dialogue de confirmation, réservée à la sortie d’un formulaire modifié et aux actions irréversibles.

**Gabarit :** `components/dialog.html.twig`

```twig
Civipass.confirm({ title, message, confirmLabel, cancelLabel, onConfirm })
```

## Utilisation

- Une seule par page, dans le layout ; elle répond à l’événement `cp:confirm`.
- Le titre est une question (« Quitter sans enregistrer ? »), le message dit la conséquence, les boutons disent l’action (« Rester sur le dossier », « Quitter sans enregistrer »). Le focus va d’abord sur le choix sûr.

## À éviter

- Jamais pour l’approbation d’une demande : elle se fait dans la fiche.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
