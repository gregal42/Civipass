En-tête de page (sur-titre, h1, sous-titre, actions) et titre de section.

**Gabarit :** `components/page_head.html.twig, section_head.html.twig`

```twig
{% embed … %}{% block actions %}…{% endblock %}{% endembed %}
```

## Utilisation

- Un seul h1 par page. Le sous-titre date les données (« données à 10:05 »).
- Les actions passent sous le titre et prennent toute la largeur sous 640 px.
- « + Créer un événement » n’apparaît que si le rôle le permet.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
