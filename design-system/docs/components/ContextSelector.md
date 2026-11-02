Sélecteur de la commune active ou de l’événement actif, avec un menu déroulant accessible au clavier.

**Gabarit :** `components/context_selector.html.twig`

```twig
{% include 'components/context_selector.html.twig' with { id: 'commune', kicker: 'Commune active', value: 'Le Temple', options: communes } only %}
```

## Utilisation

- Chaque option peut décrire les rôles de l’utilisateur dans cette commune (`description`).
- Le choix d’une option recharge le contexte (lien) ; un formulaire modifié est protégé par `cp--unsaved-guard`.
- Contrôleur `cp--dropdown` : Entrée ou Espace ouvre, flèches pour naviguer, Échap ferme et rend le focus.

## À éviter

- Ne pas y mettre d’actions (créer, supprimer) : ce menu ne sert qu’à changer de contexte.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
