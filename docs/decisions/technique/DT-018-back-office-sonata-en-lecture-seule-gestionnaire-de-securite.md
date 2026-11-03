# DT-018 — Back-office Sonata en lecture seule, gestionnaire de sécurité limité à LIST/VIEW

- **Identifiant** : DT-018
- **Thème** : Back-office
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (back-office Sonata) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Validation du commanditaire (étapes 3 et 4 du back-office).

## Décision

Le back-office Sonata est réservé à `ROLE_SUPER_ADMIN` avec authentification complète ; trois modules en lecture seule (utilisateurs, communes, appartenances) ; gestionnaire par rôles limité à LIST et VIEW ; routes et méthodes d'écriture refusées ; titre « BO Civipass » et monogramme du design system.

## Conséquences fonctionnelles ou techniques

- `ReadOnlySecurityHandler`, `ReadOnlyAdmin`.

## Documents concernés

- [backoffice.md](../../backoffice.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
