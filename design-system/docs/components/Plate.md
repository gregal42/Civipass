Immatriculation rendue comme une plaque (bande latérale, chasse fixe) pour la comparer d’un coup d’œil au véhicule.

**Gabarit :** `components/plate.html.twig`

```twig
{% include 'components/plate.html.twig' with { number: 'AB-123-CD', size: 'lg' } only %}
```

## Utilisation

- Taille `m` dans le back-office, `lg` au contrôle QR. `off` barre une plaque non accordée.
- Le texte est affiché tel que saisi : le format des plaques (françaises, étrangères) n’est pas encore tranché.
- Les couleurs de la plaque (`plate-ground`, `plate-ink`, `plate-band`) sont identiques dans les deux thèmes.

## À éviter

- Ne pas reproduire une plaque réelle (drapeau européen, logo régional).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
