En-tête des écrans publics avec le statut de connexion permanent, et le bandeau de l’appareil de contrôle activé.

**Gabarit :** `components/public_header.html.twig, connection.html.twig`

```twig
{% include 'components/public_header.html.twig' with { title: 'Vérification d’un laissez-passer', connection: 'offline', device: [...] } only %}
```

## Utilisation

- Statuts : « En ligne — vérification temps réel », « Hors ligne » (avec « données du … » dans le bandeau), « Serveur injoignable ».
- Aucune navigation municipale ni connexion sur les écrans publics.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
