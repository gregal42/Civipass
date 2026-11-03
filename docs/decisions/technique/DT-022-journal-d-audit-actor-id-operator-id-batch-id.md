# DT-022 — Journal d'audit : `actor_id`, `operator_id`, `batch_id`

- **Identifiant** : DT-022
- **Thème** : Audit
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des derniers arbitrages TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-061 : exigence validée ; la forme ne l'est pas.

## Décision

Option A validée. Dès la première migration concernée, `audit_log` porte `actor_id`, `operator_id` et `batch_id` : le journal distingue l'utilisateur métier, l'opérateur réel et les opérations groupées. `actor_id` existe déjà ; `operator_id` et `batch_id` sont ajoutés (nullable, indexés).

## Conséquences fonctionnelles ou techniques

- `ALTER TABLE` ajoutant deux colonnes nullables : sans réécriture de données.
- `AuditActor`/`AuditTrail` étendus ; `AuditOrigin` : ajout de `SUPPORT` (code, sans migration).
- Détail : `docs/lot-02-schema.md` §9.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
