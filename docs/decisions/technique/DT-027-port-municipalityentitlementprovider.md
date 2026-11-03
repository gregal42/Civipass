# DT-027 — Port `MunicipalityEntitlementProvider`

- **Identifiant** : DT-027
- **Thème** : Plateforme
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-070 (A11 option A).

## Décision

Port applicatif `MunicipalityEntitlementProvider` renvoyant les droits d'utilisation d'une commune, chacun avec sa **source** (`SUBSCRIPTION`, `TRIAL`, `CWS_GRANT`) et sa validité (fin facultative) ; `EventCreationPolicy` reste le service centralisé et autorise la création si au moins un droit est valide (DM-076). Le module Abonnements ne sera pas développé dans TECH-02.1.

## Conséquences fonctionnelles ou techniques

- Remplace le port `SubscriptionStatusProvider` évoqué précédemment.
- Implémentation provisoire : DT-030.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
