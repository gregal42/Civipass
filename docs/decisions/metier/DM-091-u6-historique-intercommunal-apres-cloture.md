# DM-091 — U6 — Historique intercommunal après clôture

- **Identifiant** : DM-091
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-084 : exception à « ne pas exposer les participations CLOSED aux autres participantes ».

## Décision

Après la clôture d'un événement, une commune ayant effectivement participé (participation avec `activated_at`) peut consulter l'historique des autres communes participantes. La projection n'expose que l'identifiant de la commune, son nom et un indicateur d'organisatrice. Les invitations jamais acceptées restent invisibles aux autres communes ; la commune dont l'invitation n'a jamais été acceptée ne voit que sa propre invitation. Les droits de modification restent bloqués après clôture.

## Conséquences fonctionnelles ou techniques

- Nouveau niveau `ParticipantListVisibility::History`, projection `EventParticipantHistoryEntry`, requête `EventReadModel::historyOf`.
- L'organisatrice (rôles de gestion) garde la visibilité complète ; son instructeur reçoit l'historique.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun « statut final » exposé (toutes les participations sont CLOSED).
