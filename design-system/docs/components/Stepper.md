> **Non utilisé — LOT UI-02.** La création d’un événement est un formulaire court sur une page (SFD SCR-012). Le composant reste disponible pour un futur parcours en plusieurs étapes, sans usage en V1.

Progression d’un assistant en plusieurs étapes (création d’un événement : informations générales, périmètre, récapitulatif et activation).

**Gabarit :** `components/stepper.html.twig`

```twig
{% include 'components/stepper.html.twig' with { label: 'Étapes de la création de l’événement', steps: [
  { label: 'Informations générales', state: 'done', href: path('event_wizard', { step: 1 }) },
  { label: 'Périmètre', state: 'current' },
  { label: 'Récapitulatif et activation' } ] } only %}
```

## Utilisation

- `state` : `done` (étape franchie, lien pour y revenir), `current` (étape affichée), `error` (étape à corriger), `todo`. L’étape en cours porte `aria-current="step"` ; chaque étape annonce aux lecteurs d’écran son rang et son état.
- On ne saute pas une étape non franchie : seules les étapes `done` sont des liens.
- Le composant n’enregistre rien. Chaque étape est un formulaire serveur ; le brouillon est enregistré à chaque passage (choix du serveur).
- Sous 560 px de conteneur : « Étape 2 sur 3 · Périmètre » et une barre segmentée. Les intitulés restent lus par les lecteurs d’écran.

## À éviter

- Plus de 5 étapes : découper autrement.
- Couleur seule pour l’état : chaque état a aussi un symbole (coche, numéro, « ! ») et un texte masqué.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
