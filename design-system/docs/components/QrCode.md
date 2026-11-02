QR code d’un laissez-passer, avec sa référence : jeton opaque, stable à la réimpression.

**Gabarit :** `components/qr_code.html.twig`

```twig
{% include 'components/qr_code.html.twig' with { svg: qr_svg, ref: 'LP-2026-0156', size: 'm' } only %}
```

## Utilisation

- Le SVG est généré par le serveur ; le QR ne contient aucune donnée personnelle en clair.
- Fond blanc (`plate-ground`) dans les deux thèmes pour rester lisible par un appareil photo. Tailles 72, 120, 200 px.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
