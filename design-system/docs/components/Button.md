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

## Proposition UI-002 : action indisponible (`unavailable_reason`)

> **Évolution additive, à valider.** Sans ce paramètre, le rendu du bouton est strictement inchangé.

Le lot UI-002 demande de ne jamais masquer une action sensible par une simple désactivation visuelle. Avec `unavailable_reason`, le bouton :

- reste focalisable (`aria-disabled="true"`, pas d’attribut `disabled`), sans lien ni action ;
- affiche la raison dessous, avec une icône cadenas, reliée par `aria-describedby`.

```twig
{% include 'components/button.html.twig' with { id: 'close-event', label: 'Clôturer l’événement…', variant: 'danger-ghost',
  unavailable_reason: 'Impossible pour l’instant : 2 communes n’ont pas clôturé leur participation (Le Temple, Saumos).' } only %}
```

Le serveur décide si l’action est possible et rédige la raison. Une action non permise au rôle n’est toujours pas affichée.
