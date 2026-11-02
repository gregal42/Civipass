Résultat d’un contrôle QR, en grand, en tête d’écran : valide, aucun droit, incertain hors ligne, bloqué, impossible, en cours.

**Gabarit :** `components/verdict.html.twig`

```twig
{% include 'components/verdict.html.twig' with { state: 'valid', when: 'Vérifié le 02/11/2026 à 18:20 · heure de Paris' } only %}
```

## Utilisation

- `valid` (vert) uniquement si au moins un droit est valide en ligne, et toujours suivi de la Checklist.
- Hors ligne : `uncertain` (orange) si les données ont moins de 24 h, `blocked` au-delà. Jamais de vert hors ligne.
- `impossible` si le serveur ne répond pas et qu’aucun mode hors ligne n’est activé : ni validation ni refus.
- L’horodatage dit quand et sur quelles données le contrôle a été fait.

## À éviter

- Ne pas écrire « Accès autorisé » : un droit valide ne vaut pas accès partout.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
