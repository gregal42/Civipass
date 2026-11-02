Bouton d’action ou lien-bouton, en six variantes selon le rôle de l’action.

**Gabarit :** `components/button.html.twig`

```twig
{% include 'components/button.html.twig' with { label: 'Approuver…', variant: 'approve', icon: 'check', size: 'l', attrs: 'data-action="cp--disclosure#open"' } only %}
```

## Utilisation

- `primary` : l’action principale de l’écran (une seule par zone). `secondary` : actions secondaires et Annuler. `ghost` : action de faible poids, en lien.
- `approve` / `refuse` : réservés à la décision sur une demande, en taille `l` (52 px), clairement séparés (Approuver à gauche, Refuser à droite, sur desktop).
- `danger` : bouton destructif plein, réservé au bouton final d’une confirmation (« Révoquer définitivement »).
- `danger-ghost` : action destructrice secondaire (Révoquer…), toujours suivie d’une confirmation qui en dit la conséquence.
- Les points de suspension (« Approuver… ») annoncent une étape de plus avant l’action définitive.
- Avec `href`, le composant rend un `<a>` (navigation) ; sinon un `<button>`. Avec `loading_label` et le contrôleur `cp--submit-state` sur le formulaire, le bouton est bloqué et affiche « Émission en cours… » pendant l’envoi.

## À éviter

- Ne pas utiliser `approve` pour une validation générique (enregistrer, continuer) : le vert est réservé aux droits accordés.
- Ne pas désactiver un bouton sans dire pourquoi à côté.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
