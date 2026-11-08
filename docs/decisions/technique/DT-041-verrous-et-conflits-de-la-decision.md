# DT-041 — Ordre des verrous et conflits de la décision municipale

- **Identifiant** : DT-041
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DT-024, DT-035, DT-036, DT-038.

## Décision

Ordre : événement (partagé) → participation de la commune (exclusif) → zones demandées (approbation seulement) → part (exclusif, relue sous verrou). Les identifiants à verrouiller sont lus **sans verrou et sans entité** (`PartLocator`) ; l'état, les droits et l'horodatage ne sont lus qu'après. La part décidée par une opération concurrente donne `OperationConflict(StaleState)` ; la violation de `uniq_request_decision_part` est traduite en conflit. Aucun verrou fonctionnel de 15 minutes. Le verrou du **dossier** (laissez-passer unique, LOT 05) viendra après les participations et avant le compteur de références.

## Conséquences fonctionnelles ou techniques

- Le verrou exclusif de participation sérialise les décisions d'une commune pour un événement (même contrainte que les dépôts) : risque de débit accepté en V1.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
