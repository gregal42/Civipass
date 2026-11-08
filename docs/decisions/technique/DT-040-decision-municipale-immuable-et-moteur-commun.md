# DT-040 — Décision municipale immuable et moteur de décision commun

- **Identifiant** : DT-040
- **Thème** : LOT 04 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-106, DM-108 ; modèle STD (`ApplicationDecision`).

## Décision

- `PermitRequestDecision` : append-only, sans mutateur, `UNIQUE(request_municipality_id)`, FK `RESTRICT`, CHECK MySQL sur les invariants d'une ligne (APPROVED exige un mode, `valid_until` nul si et seulement si `UNTIL_CLOSURE`, `valid_until > valid_from` ; REFUSED exige un motif et aucune durée). Créée uniquement par `PermitRequestMunicipality::approve()/refuse()`, qui portent la transition (source : enum `MunicipalRequestStatus`, DT-031).
- Un **seul** moteur : `MunicipalDecisionApplier` (sans transaction ni verrou ; l'appelant les a pris) revalide l'état verrouillé, applique les règles, crée la décision, écrit l'audit puis appelle le point d'intégration LOT 05. Points d'entrée : `MunicipalInstructionService` (instruction), service de dépôt (délivrance directe, LOT 04.3).
- Toutes les vérifications précèdent la première mutation (un refus ne laisse rien en attente dans l'EntityManager).

## Conséquences fonctionnelles ou techniques

- Audit : `REQUEST_APPROVED` et `REQUEST_REFUSED`, une ligne par décision, sans donnée personnelle ni commentaire.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
