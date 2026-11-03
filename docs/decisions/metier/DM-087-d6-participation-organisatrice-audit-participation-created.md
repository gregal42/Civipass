# DM-087 — D6 — Participation organisatrice : audit `PARTICIPATION_CREATED`

- **Identifiant** : DM-087
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-053.

## Décision

Lors de la création d'un événement, la participation organisatrice est créée `ACTIVE` et produit une trace d'audit `PARTICIPATION_CREATED` ; la création de l'événement produit son propre audit (`EVENT_CREATED`), ainsi que celle de la zone proposée (`ZONE_CREATED`).

## Conséquences fonctionnelles ou techniques

- Nouvelle valeur `AuditAction::ParticipationCreated` (colonne `VARCHAR`, sans migration).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Lève D6.
