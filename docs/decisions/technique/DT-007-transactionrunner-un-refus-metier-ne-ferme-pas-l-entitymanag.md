# DT-007 — TransactionRunner : un refus métier ne ferme pas l'EntityManager

- **Identifiant** : DT-007
- **Thème** : Transactions
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Revue indépendante du LOT 01.1 (E1).

## Décision

Les services passent par un `TransactionRunner` qui garde l'EntityManager ouvert après un refus métier, le ferme après un flush ou une modification en attente, et refuse l'imbrication.

## Conséquences fonctionnelles ou techniques

- `DoctrineTransactionRunner`.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§14 bis)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
