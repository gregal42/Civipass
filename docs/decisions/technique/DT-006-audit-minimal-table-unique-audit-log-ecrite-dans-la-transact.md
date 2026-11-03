# DT-006 — Audit minimal : table unique `audit_log` écrite dans la transaction

- **Identifiant** : DT-006
- **Thème** : Audit
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D5, D10, D20 de `docs/lot-01-schema.md` ; réutilisable au LOT 07.

## Décision

Les changements sensibles sont tracés dans une table unique `audit_log` (append-only, sans clé étrangère, valeurs avant/après sans donnée personnelle), écrite par les services applicatifs dans la même transaction.

## Conséquences fonctionnelles ou techniques

- `AuditTrail`, `AuditEvent`, `AuditLog` ; nouvelles actions sans migration (colonnes `VARCHAR`).

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md) (§8)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
