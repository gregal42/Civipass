# DM-096 — Plafonds de sécurité : 500 créneaux par configuration, 100 zones par opération groupée

- **Identifiant** : DM-096
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Dernière passe de finalisation avant validation » de TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Aucune borne n'existait sur la taille d'une configuration de validité ni d'une opération groupée : risque de transactions démesurées (verrous longs, audits volumineux). Aucune règle métier ne fixe de nombre.

## Décision

Une configuration de validité comporte **au plus 500 créneaux**, une opération groupée porte sur **au plus 100 zones**. Ces plafonds sont des garde-fous techniques, appliqués côté backend avant toute lecture ou écriture, **configurables** (`EVENT_MAX_SLOTS_PER_VALIDITY`, `EVENT_MAX_ZONES_PER_BULK_OPERATION`, défauts 500 et 100). Un dépassement est un refus de validation (`DomainRuleViolation`) ; la valeur limite elle-même est acceptée.

## Conséquences fonctionnelles ou techniques

- Aucun changement de schéma.
- Les plafonds ne se cumulent pas : 100 zones × 500 créneaux dans un même lot restent possibles et lourds (jusqu'à 50 000 créneaux et autant d'audits dans une transaction). Risque accepté temporairement par le commanditaire ; **point bloquant avant toute exposition HTTP des opérations groupées** (plafond cumulé ou autre garde à décider ; plan 5.1 §23).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)

## Écarts documentaires signalés

- Aucun écart.
