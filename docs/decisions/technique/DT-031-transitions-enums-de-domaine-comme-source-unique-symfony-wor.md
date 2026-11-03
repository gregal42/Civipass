# DT-031 — Transitions : enums de domaine comme source unique, Symfony Workflow non branché

- **Identifiant** : DT-031
- **Thème** : Architecture
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

Plan d'implémentation : « Implémentation prévue avec `symfony/workflow` » ; `workflow.yaml` est vide.

## Décision

Les transitions d'événement et de participation sont définies une seule fois, dans `EventStatus` et `ParticipationStatus` (`allowedTransitions()`), et appliquées par les méthodes d'entité qui portent aussi les invariants (motif de suspension, verrouillages, événement clôturé). Le composant Symfony Workflow n'est pas configuré à l'étape 2 : un marking store exigerait des accesseurs publics sur le statut et dupliquerait la table des transitions. Il pourra être branché à l'étape 5 si des gardes déclaratives ou des diagrammes deviennent utiles, avec un test d'égalité avec les enums.

## Conséquences fonctionnelles ou techniques

- Tableau complet des transitions testé (16 couples pour l'événement, table explicite pour les participations).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
