Décision d’une commune sur une demande : boutons Approuver / Refuser, puis formulaire déployé dans la fiche, avec récapitulatif et confirmation.

**Gabarit :** `components/decision_form.html.twig, recap.html.twig, action_bar.html.twig`

```twig
{% embed 'components/decision_form.html.twig' with { kind: 'approve', id: 'approve-form', title: 'Approuver pour Le Temple' } only %}…{% endembed %}
```

## Utilisation

- Le formulaire s’ouvre dans la page (`cp--disclosure`), jamais dans une modale. Approuver comporte : période continue, zones (dont une zone active non demandée), modes, plaques, récapitulatif en direct (`cp--recap`), confirmation.
- Changer de décision, annuler ou quitter la page avec une saisie en cours demande confirmation (`cp--unsaved-guard`, `cp--dialog`).
- Pendant l’envoi, le bouton est bloqué (« Émission en cours… »). En cas d’erreur réseau, une alerte dans le formulaire conserve les choix.
- Après la décision, on reste sur le dossier : alerte de succès focalisée, statut et historique à jour, sans passer au dossier suivant.
- Sous 640 px, la barre d’actions (Refuser / Approuver) est fixée au-dessus de la navigation basse et disparaît pendant la saisie.

## À éviter

- La première approbation émet immédiatement le laissez-passer : le récapitulatif le dit avant la confirmation.

Variables : en-tête du gabarit (`@param`) et `index.d.ts`.
