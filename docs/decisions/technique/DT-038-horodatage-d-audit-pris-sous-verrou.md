# DT-038 — Horodatage des écritures et des audits pris après l'acquisition des verrous

- **Identifiant** : DT-038
- **Thème** : Audit
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Dernière passe de finalisation avant validation » de TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

L'horodatage était pris au début de l'opération, avant l'éventuelle attente d'un verrou : deux opérations concurrentes pouvaient être horodatées dans un ordre différent de leur ordre réel (DT-036, DT-037).

## Décision

L'instant `now` d'une opération (mutations du domaine et audits) est lu **après l'acquisition des verrous**, dans la transaction, juste après le rappel de la politique. Pour la création d'événement, dont le seul verrou est le compteur, il est lu après l'attribution de la référence. L'identifiant d'audit (UUID v7, créé sous verrou) reste la référence d'ordonnancement. Les pré-autorisations hors transaction n'utilisent pas cet instant. Aucune autre garantie transactionnelle n'est modifiée.

## Conséquences fonctionnelles ou techniques

- Services concernés : `EventCreationService`, `EventParticipationService`, `EventLifecycleService`, `EventZoneService`, `EventZoneBulkService`.
- Test : `EventZoneConcurrencyTest::testTheAuditTimestampIsTakenAfterTheLockWaitNotBeforeIt` (et variante du cycle de vie).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [DT-036](DT-036-d3-isolation-repeatable-read-conservee-delai-de-verrou-de-5-.md), [DT-037](DT-037-d5-audit-metier-structure-sans-texte-libre-ni-donnee-personn.md)

## Écarts documentaires signalés

- Aucun écart.
