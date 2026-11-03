> **Nouveau composant — LOT UI-02** (SFD SCR-018, CDC §5.2).

Créneaux de validité d’une zone : lecture (`validity_slot`) et saisie (`validity_form`, contrôleur `cp--validity`).

**Gabarits :** `components/validity_slot.html.twig` (dans `<ol class="cp-slots">`) et `components/validity_form.html.twig`.

```twig
<ol class="cp-slots">
  {% for s in slots %}{% include 'components/validity_slot.html.twig' with { index: loop.index, mode: s.mode, summary: s.summary, actions: s.actions } only %}{% endfor %}
</ol>
{% include 'components/validity_form.html.twig' with { id: 'slot', title: 'Ajouter un créneau', value: form.values, errors: form.errors } only %}
```

## Validité d’une zone (arbitrage du 2 novembre 2026)

Chaque zone a un choix explicite (`validity_form` avec `with_scope: true`) :

- **Pendant toute la durée de l’événement** : aucun créneau n’est nécessaire ;
- **Sur des périodes spécifiques** : un ou plusieurs créneaux.

Une zone peut être enregistrée sans validité : elle affiche « Validité à configurer » (ce n’est pas un statut métier) et n’ouvre aucun droit d’accès.

## Types de créneau

| Type | Champs | Règles |
|---|---|---|
| Plage continue | Début (date et heure) → fin (date et heure), composant Period | Fin strictement après le début (SFD SCR-018) |
| Accès ponctuel | Une date, une heure de début, une heure de fin | Fin après le début, le même jour |
| Récurrence | Date de début et date de fin ; tous les jours ou certains jours ; heure de début et heure de fin | Le jour coché est le jour où le créneau commence ; passage de minuit possible (fin plus tôt que le début = le lendemain) ; début identique à la fin refusé |

## Comportement

- `cp--validity` n’affiche que les champs utiles, désactive les autres (ils ne sont pas envoyés) et rédige un résumé en direct.
- **Chevauchements** : autorisés. Le moteur fait l’union des créneaux : la zone est valable dès qu’au moins un créneau couvre l’instant contrôlé. Le serveur peut afficher un avertissement non bloquant (« Les créneaux 1 et 2 se chevauchent… »).
- **Heure de Paris** : tous les horaires sont saisis et affichés en heure de Paris (Europe/Paris), sans aucun réglage de fuseau pour l’utilisateur. Les calculs sont faits en instants UTC par le serveur.
- **Changements d’heure** : le résumé signale le jour du changement. Une heure saisie entre 02:00 et 02:59 ce jour-là est signalée explicitement : inexistante au passage à l’heure d’été, ambiguë au retour à l’heure d’hiver. La règle appliquée est une proposition à inscrire dans les STD : instant valide suivant (03:00), et première occurrence (heure d’été).
- Le contrôleur ne valide rien : le serveur renvoie les erreurs par champ (`errors`) et conserve les saisies.

## Accessibilité

- Le choix de validité et le type de créneau sont des groupes de boutons radio (cartes Choice). Les jours sont des cases à cocher, avec le nom complet du jour pour les lecteurs d’écran.
- Le résumé est dans une zone `aria-live="polite"`.

Variables : en-tête des gabarits (`@param`) et `index.d.ts`.
