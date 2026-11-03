> **Nouveau composant — LOT UI-02.**

Invitation reçue par la commune active à rejoindre l’événement d’une autre commune (tableau de bord, liste des invitations).

**Gabarit :** `components/invitation_card.html.twig`

```twig
{% include 'components/invitation_card.html.twig' with { id: 'inv-' ~ event.id, title: event.name, type: 'Inondation', organizer: 'Saumos', invited_on: '01/11/2026 à 08:02', href: path_invitation } only %}
```

## Utilisation

- L’invitation n’expire pas et ne se refuse pas en V1 (SFD SCR-014, décision D7) : la commune accepte, ou l’invitation reste en attente.
- L’acceptation se fait depuis l’écran de l’invitation, par un administrateur de la commune invitée.
- `blocked` explique pourquoi la commune ne peut pas accepter (commune suspendue) ; le lien reste une consultation.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
