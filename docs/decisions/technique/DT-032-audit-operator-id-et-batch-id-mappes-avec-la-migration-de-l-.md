# DT-032 — Audit : `operator_id` et `batch_id` mappés avec la migration de l'étape 3

- **Identifiant** : DT-032
- **Thème** : Audit
- **Statut** : Remplacée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Réalisée à l'étape 3 : colonnes, index, mapping dans `AuditLog` et garde-fou supprimé.

## Contexte

DT-022 (validée) : « préparer les mappings » ; contrainte : aucune migration avant l'étape 3 et `make quality` doit passer.

## Décision

Pendant l'étape 2, les contrats étaient prêts mais `AuditLog` n'était pas mappé (une colonne absente aurait cassé les écritures d'audit) et `DoctrineAuditTrail` refusait un événement portant un opérateur ou un lot. À l'étape 3, la migration ajoute `operator_id` et `batch_id` (nullables, indexés), `AuditLog` les mappe, `DoctrineAuditTrail` les persiste et le garde-fou est supprimé ; des tests MySQL vérifient l'enregistrement effectif.

## Conséquences fonctionnelles ou techniques

- Écart volontaire avec `docs/lot-02-schema.md` §9 jusqu'à l'étape 3.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
