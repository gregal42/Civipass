# DM-033 — Création d'événement : statut ACTIVE et abonnement ACTIVE

- **Identifiant** : DM-033
- **Thème** : Communes
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrage 1 du LOT 01.1 (D9 de `docs/lot-01-schema.md`) ; SFD §8 : participation gratuite d'une commune invitée.

## Décision

Une commune doit avoir un statut administratif ACTIVE et un abonnement ACTIVE pour créer un événement. Une commune non abonnée peut néanmoins participer à un événement sur invitation si son statut administratif et les règles de participation le permettent.

## Conséquences fonctionnelles ou techniques

- `EventCreationPolicy::canCreateEvent`.
- L'entité d'abonnement n'existe pas encore : arbitrage A11 (port provisoire ou entité).

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§8)

## Écarts documentaires signalés

- Évolué par DM-076 : le critère « abonnement ACTIVE » devient « droit d'utilisation valide » (abonnement actif, TRIAL valide ou accès commercial CWS). LOT 01 D9 reste le texte historique.
