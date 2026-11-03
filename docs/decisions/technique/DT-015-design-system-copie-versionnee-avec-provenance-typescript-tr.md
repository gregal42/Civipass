# DT-015 — Design system : copie versionnée avec provenance, TypeScript transpilé hors projet

- **Identifiant** : DT-015
- **Thème** : Interface
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (intégration du design system) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrages d'intégration du design system.

## Décision

Le design system est importé par copie versionnée avec `PROVENANCE.md` ; les contrôleurs TypeScript sont transpilés hors du projet et seul le JavaScript est importé (pas de `sensiolabs/typescript-bundle`).

## Conséquences fonctionnelles ou techniques

- `design-system/tools/import.mjs`.

## Documents concernés

- [design-system.md](../../design-system.md)
- [PROVENANCE.md](../../../design-system/PROVENANCE.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
