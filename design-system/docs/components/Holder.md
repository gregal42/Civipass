Identité minimale du titulaire au contrôle : nom, référence du laissez-passer, événement.

**Gabarit :** `components/holder.html.twig`

```twig
{% include 'components/holder.html.twig' with { name: 'Camille Martin', ref: 'LP-2026-0156', event: '…' } only %}
```

## À éviter

- Jamais d’e-mail, de téléphone, d’adresse ni d’historique des scans.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
