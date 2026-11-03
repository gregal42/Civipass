# DT-017 — Prévisualisation du design system en environnement dev uniquement

- **Identifiant** : DT-017
- **Thème** : Interface
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (intégration du design system) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrage d'intégration du design system.

## Décision

Les routes `/dev/ui` n'existent qu'en environnement `dev` (données fictives, sans Doctrine), protégées par trois verrous indépendants.

## Conséquences fonctionnelles ou techniques

- Tests d'absence en `test` et `prod`.

## Documents concernés

- [design-system.md](../../design-system.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
