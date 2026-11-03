# DM-089 — D8 — Réinvitation après clôture interdite

- **Identifiant** : DM-089
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-056, DM-077.

## Décision

Une commune dont la participation à un événement est `CLOSED` ne peut pas être réinvitée au même événement : la clôture est irréversible (et la contrainte d'unicité l'interdit).

## Conséquences fonctionnelles ou techniques

- `EventParticipationService::invite` refuse avec « sa participation est clôturée ».

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Lève D8.
