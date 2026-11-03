# DM-093 — Confirmation d'une zone sans configuration temporelle

- **Identifiant** : DM-093
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Dernière passe de finalisation avant validation » de TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-051 distingue la confirmation (`confirmedAt`) du statut ; DM-059 place la validité parmi les conditions d'activation. Aucune décision ne subordonnait la confirmation à une configuration.

## Décision

Une zone peut être confirmée sans configuration temporelle. La confirmation métier et la disponibilité opérationnelle restent distinctes : une zone confirmée mais non configurée n'ouvre aucun droit (DM-051) et ne compte pas pour l'activation (DM-059). Les conditions d'activation validées ne sont pas modifiées.

## Conséquences fonctionnelles ou techniques

- `EventZoneService::confirm` n'a aucune précondition temporelle ; idempotent (déjà confirmée : aucune écriture, aucun audit).
- Aucune modification de `existsActivationReadyZone`.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [DM-051](DM-051-a1-representation-de-la-zone-confirmee.md), [DM-059](DM-059-a9-conditions-d-activation-d-un-evenement.md)

## Écarts documentaires signalés

- Aucun écart : précise un point resté implicite, sans modifier DM-051 ni DM-059.
