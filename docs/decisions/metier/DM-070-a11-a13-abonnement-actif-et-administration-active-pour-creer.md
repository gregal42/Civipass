# DM-070 — A11 / A13 — Abonnement actif et administration active pour créer un événement

- **Identifiant** : DM-070
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-033 et DM-034 (LOT 01) ; SFD §8 ; A11 : l'entité d'abonnement reste dans son propre lot (port `SubscriptionStatusProvider`).

## Décision

A11/A13, précisés par P1. Seules les communes administrativement actives disposant d'un **abonnement actif ou d'une période TRIAL valide** peuvent créer de nouveaux événements ; l'expiration d'un abonnement interdit la création de nouveaux événements. Les événements déjà ouverts continuent de fonctionner normalement et les communes conservent les capacités nécessaires à leur gestion. Une commune non abonnée peut accepter une invitation, participer à un événement organisé par une commune abonnée, gérer ses zones et délivrer des laissez-passer dans le cadre de cette participation, selon les permissions métier. Une suspension administrative pour motif de sécurité reste distincte de l'expiration d'abonnement et peut entraîner des restrictions spécifiques (DM-034, LOT 01 D16/D17). Le droit de créer un événement est contrôlé côté serveur dans un **service métier centralisé**. Le module complet de gestion des abonnements n'est pas développé dans TECH-02.1.

## Conséquences fonctionnelles ou techniques

- Service centralisé : `EventCreationPolicy` (existant, à faire évoluer) ; entrée : statut administratif + `SubscriptionAccess` fourni par le port `SubscriptionStatusProvider` (DT-027).
- `SubscriptionAccess` doit exposer le statut ET la validité de la période TRIAL (date de fin) : « TRIAL valide » ne se déduit pas du seul statut.
- Aucune vérification d'abonnement sur les opérations des événements existants.
- DM-034 / D16 / D17 restent en vigueur pour la suspension administrative (voir DM-073).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [lot-01-schema.md](../../lot-01-schema.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§8)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point P1 levé ; **règle complétée par DM-076** (accès commercial CWS). Les mentions « abonnement actif ou TRIAL valide » se lisent « droit d'utilisation valide ».
