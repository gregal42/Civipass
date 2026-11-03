# DM-052 — A2 — Suppression logique de toutes les zones

- **Identifiant** : DM-052
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD : « suppression logique si utilisée » ; CLAUDE.md règle 8.

## Décision

Option A. Toutes les suppressions de zones sont logiques (`deletedAt`). Aucune suppression physique. Une zone supprimée n'est plus opérationnelle et disparaît des listes opérationnelles ordinaires ; son historique reste consultable selon les permissions d'audit ; les références historiques sont conservées ; aucune réactivation implicite.

## Options examinées

- (a) Toujours logique — **retenue**
- (b) Physique si jamais utilisée — écartée

## Conséquences fonctionnelles ou techniques

- `EventZone.deletedAt` nullable ; clés étrangères en RESTRICT.
- Toute requête opérationnelle filtre `deletedAt IS NULL`.
- Pas de réactivation : une zone supprimée n'est pas rétablie implicitement (une éventuelle restauration explicite n'est pas décidée).
- Les créneaux de validité suivent la même logique (DM-075) ; la suppression logique d'une zone n'est pas propagée physiquement à ses créneaux.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§2, SCR-016)

## Écarts documentaires signalés

- Le SFD distingue suppression logique (zone utilisée) et possible suppression physique ; la décision uniformise : à répercuter dans la révision du SFD.
