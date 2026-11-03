> **Nouveau composant — LOT UI-02.**

Statut de la commune active quand il limite ce qu’elle peut faire.

**Gabarit :** `components/commune_status.html.twig`

```twig
{% include 'components/commune_status.html.twig' with { state: 'free', commune: 'Pinsac-de-Lande' } only %}
```

## États

| `state` | Quand | Effet dans l’interface |
|---|---|---|
| `free` | Commune non abonnée, invitée à un événement (SFD §8, CDC §3.2) | Participation gratuite limitée aux événements concernés ; pas de « Créer un événement » (raison écrite) |
| `suspended` | Commune suspendue (SFD SCR-047) | Ni création ni acceptation d’invitation : les actions sont retirées et le message l’explique |
| `planned` | Suspension planifiée (SFD SCR-047) | Information ; la suspension commerciale est différée tant qu’une participation est active |

Ne pas confondre une commune suspendue et une commune non abonnée : textes, couleurs et icônes sont différents.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
