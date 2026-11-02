Étiquette d’origine d’une valeur dans un formulaire de décision : demandée, ajoutée par l’agent ou indisponible.

**Gabarit :** `components/tag.html.twig`

```twig
{% include 'components/tag.html.twig' with { label: 'Ajout agent', variant: 'agent' } only %}
```

## Utilisation

- `default` (« Demandée ») : la valeur vient de la demande. `agent` : l’agent l’a ajoutée, ce qui sera tracé. `off` (« Suspendue ») : la valeur est indisponible.

## À éviter

- Ne pas confondre avec StatusPill : une étiquette qualifie une option, pas l’état d’un dossier.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
