# DT-026 — Liste blanche de fuseaux IANA (`EventTimezone`)

- **Identifiant** : DT-026
- **Thème** : Données
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-062 : identifiants IANA ; une liste blanche évite les valeurs arbitraires.

## Décision

Résolveur `TerritoryTimezoneResolver` + liste blanche `EventTimezone`, fondés sur un **référentiel territorial versionné** (fichier de données du dépôt, avec provenance et empreinte, sans interface d'administration) : fuseau par département/collectivité d'outre-mer et exceptions **par commune** pour la Polynésie française (`Pacific/Tahiti`, `Pacific/Marquesas`, `Pacific/Gambier`). Proposé par défaut à partir du code INSEE de la commune organisatrice. Le contenu doit être établi à partir d'une source officielle (COG INSEE) et validé avant intégration. Principe validé par DM-062 ; structure proposée.

## Conséquences fonctionnelles ou techniques

- Seuls `Europe/Paris` et `America/Miquelon` ont une heure d'été.
- Aucune table en base nécessaire : évolution du référentiel par livraison de code, avec tests.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
