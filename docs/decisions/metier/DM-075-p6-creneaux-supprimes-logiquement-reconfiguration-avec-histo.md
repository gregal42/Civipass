# DM-075 — P6 — Créneaux supprimés logiquement ; reconfiguration avec historique

- **Identifiant** : DM-075
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des derniers arbitrages TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Remplace la suppression physique proposée ; cohérence avec CLAUDE.md règle 8 et DM-052.

## Décision

Les créneaux temporels sont supprimés logiquement : `deletedAt` sur `EventZoneValidity`. Lors d'une reconfiguration, les anciens créneaux sont conservés et marqués supprimés, puis les nouveaux créneaux sont créés. Les créneaux supprimés sont systématiquement exclus des calculs d'accès ; l'historique et l'audit des modifications sont conservés. La suppression logique d'un créneau ne se confond pas avec la suspension d'une zone.

## Conséquences fonctionnelles ou techniques

- `event_zone_validity.deleted_at` nullable, index `(zone_id, deleted_at)`.
- Toute lecture opérationnelle filtre `deleted_at IS NULL` ; mode `EVENT_DURATION` : aucun créneau **non supprimé**.
- Pas de restauration implicite d'un créneau supprimé.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Résiduel T4 : la modification d'un créneau unique est traitée comme remplacement (suppression logique + création), par cohérence avec l'historique — à confirmer.
