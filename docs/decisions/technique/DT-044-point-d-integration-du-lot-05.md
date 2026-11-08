# DT-044 — Point d'intégration du LOT 05 : port transactionnel, effets après commit

- **Identifiant** : DT-044
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

STD §2.2 (laissez-passer créé à la première approbation), DM-108.

## Décision

`ApprovedPartListener::partApproved(PermitRequestDecision)` est appelé dans la transaction de décision après l'écriture de la décision et de l'audit ; en LOT 04, `NullApprovedPartListener` ne fait rien (aucun QR, PDF, notification, dépendance asynchrone). Contrat LOT 05 : écritures en base seulement, idempotentes (find-or-create du laissez-passer unique, `UNIQUE(request_id)`, sous verrou du dossier ; autorisation unique par laissez-passer et participation). La « première approbation » est l'absence de laissez-passer au find-or-create, pas un drapeau (deux communes simultanées ne peuvent pas se croire premières). QR, PDF et e-mails partent **après commit** (Messenger) à partir des lignes écrites ; aucune outbox dédiée n'est introduite au 04.1.

## Conséquences fonctionnelles ou techniques

- Une exception du port annule l'approbation (testé).

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
