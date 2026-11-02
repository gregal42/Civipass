Panneau titré qui regroupe une partie du dossier ; Contact affiche l’identité et les coordonnées du demandeur.

**Gabarit :** `components/panel.html.twig, contact.html.twig`

```twig
{% embed 'components/panel.html.twig' with { id, title, meta } only %}{% block body %}…{% endblock %}{% endembed %}
```

## Utilisation

- Le bloc `head_end` accueille une pastille ou une action.
- Les coordonnées (Contact) ne figurent que dans le back-office : jamais au contrôle QR ni sur le PDF.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
