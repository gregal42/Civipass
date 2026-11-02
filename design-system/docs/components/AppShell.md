Coquille de l’espace municipal : barre latérale, barre supérieure de contexte, bandeau compact et barre basse, qui s’adaptent à la largeur.

**Gabarit :** `layouts/app.html.twig (sidebar, topbar, ctxstrip, bottom_nav)`

```twig
{% extends 'layouts/app.html.twig' %}
{% block content %}…{% endblock %}

{# ou, pièce par pièce #}
{% include 'components/sidebar.html.twig' with { items: nav_items, user: user, home: path('dashboard') } only %}
```

## Utilisation

- ≥ 1024 px : barre latérale complète (Tableau de bord, Demandes, Laissez-passer, Événements, Zones, Utilisateurs, Paramètres). De 640 à 1023 px : rail avec icônes, libellés et compteur. Sous 640 px : barre basse Accueil / Demandes / Pass / Menu, et bandeau de contexte.
- Ne passez que les entrées autorisées pour l’utilisateur : les entrées sont visibles selon les permissions.
- La commune active et, dans son espace, l’événement actif sont toujours visibles et sélectionnables. Le rôle exercé est affiché.
- Le compteur de Demandes reprend le nombre de demandes en attente de la commune.
- Le gabarit `layouts/app.html.twig` inclut aussi la boîte de dialogue et la zone de toasts.

## À éviter

- Ne pas utiliser cette coquille sur les écrans publics (formulaire, contrôle QR) : voir PublicHeader.
- Le rail de 640 à 1023 px est une proposition à valider (disposition tablette non fixée par le référentiel).

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
