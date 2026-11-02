Carte de choix (case ou bouton radio) à grande cible, avec étiquette d’origine et note de traçabilité.

**Gabarit :** `components/choice.html.twig`

```twig
{% include 'components/choice.html.twig' with { name: 'zones[]', value: 'sfn', id: 'zone-sfn', label: 'Secteur forestier nord', trace_texts: { on: 'Ajout non demandé — sera tracé dans l’historique', off: 'Zone active, non demandée' } } only %}
```

## Utilisation

- Les valeurs demandées sont présélectionnées et étiquetées « Demandée ». Une option ajoutée par l’agent annonce « sera tracé » (contrôleur `cp--choice-trace` sur le formulaire).
- Une option indisponible (zone suspendue) reste visible, désactivée, avec l’étiquette `off` et sa raison.
- `cp-choices--grid` aligne les options courtes (modes, plaques) en colonnes.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
