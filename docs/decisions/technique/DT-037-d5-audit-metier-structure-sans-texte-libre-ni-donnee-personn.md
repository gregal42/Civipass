# DT-037 — D5 — Audit métier structuré, sans texte libre ni donnée personnelle

- **Identifiant** : DT-037
- **Thème** : Audit
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Plan 5.1 §10 (D5).

## Décision

Chaque opération produit un audit structuré (action, ressource, acteur métier, opérateur CWS éventuel, commune concernée, horodatage UTC, ancien et nouvel état si nécessaire, `batch_id` pour les opérations groupées), une ligne par objet métier effectivement modifié, dans la même transaction que la modification : un rollback annule l'audit. Les états ne contiennent ni texte libre (nom, description, consignes, libellé, message d'invitation, motif) ni donnée personnelle inutile. `EventAuditRecorder` réutilise `AuditTrail` : aucun second système.

## Conséquences fonctionnelles ou techniques

- Création : `EVENT_CREATED`, `PARTICIPATION_CREATED`, `ZONE_CREATED` (origine `AUTO_PROPOSAL`), même `batch_id`, commune organisatrice.
- Invitation : `PARTICIPATION_INVITED` et `ZONE_CREATED`, même `batch_id`, commune invitée. Réexpédition : `PARTICIPATION_INVITATION_RESENT` (dates seulement).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
