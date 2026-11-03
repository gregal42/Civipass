> **Nouveau composant — LOT UI-02.**

En-tête de synthèse d’un événement : type, statut, dates, organisatrice, nombre de communes, lien de la commune active à l’événement, actions permises. Il porte le `h1` des pages de l’événement (Vue d’ensemble, Zones, Communes participantes) et remplace PageHead sur ces pages.

**Gabarit :** `components/event_header.html.twig`

```twig
{% embed 'components/event_header.html.twig' with { title: event.name, type: event.typeLabel, icon: 'fire', status: 'active', status_label: 'Actif',
  dates: { start: '31/10/2026 à 22:15', end: '' }, organizer: 'Le Temple', communes: 4,
  relation: { label: 'Organisatrice', text: 'Votre commune, Le Temple, organise cet événement.' } } only %}
  {% block actions %}…boutons permis…{% endblock %}
{% endembed %}
```

## Utilisation

- `status` reprend les statuts du SFD : `draft` (Brouillon), `active` (Actif), `suspended` (Suspendu), `closed` (Clôturé).
- `relation` dit toujours si la commune active est organisatrice, participante ou invitée (brief LOT 02 §3.3).
- `readonly` explique la consultation seule. On n’affiche jamais un bouton que l’utilisateur ne peut pas utiliser : soit l’action est absente (rôle), soit elle est indisponible avec sa raison (Button `unavailable_reason`).
- Sous 720 px de conteneur, les informations passent sur deux colonnes et les actions sur toute la largeur.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
