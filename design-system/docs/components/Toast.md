Message bref et non critique, en bas de l’écran (Civipass.toast ou événement cp:toast).

**Gabarit :** `components/toasts.html.twig`

```twig
Civipass.toast('Dossier remis « En attente ».')
```

## Utilisation

- Une zone par page, dans le layout. Disparaît après 4 s.

## À éviter

- Ne jamais annoncer une décision ou une erreur uniquement par un toast : ces informations restent dans la page.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
