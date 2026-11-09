# DT-046 — Module Permit et création atomique par le port ApprovedPartListener

- **Identifiant** : DT-046
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DT-044 (port), DT-024 et DT-041 (verrous), DT-043 (délivrance directe), DT-042 (copies à l'émission).

## Décision

1. Module `src/Permit` (`Domain`, `Application`, `Infrastructure`) dépendant de Request, Event, Municipality et Shared ; **Request ne dépend pas de Permit** : `ApprovedPartListener` (port possédé par Request) est implémenté par `PermitIssuer` (alias DI) en remplacement de `NullApprovedPartListener`.
2. **Création dans la transaction de décision.** `partApproved()` : verrou de la ligne du dossier (si le dossier est déjà persisté) puis **relecture verrouillante** du laissez-passer (REPEATABLE READ : une lecture simple ne verrait pas une création validée entre-temps) ; création sinon (référence `LP-` prise sur un compteur annuel verrouillé **en dernier**, jeton, copie des plaques) ; autorisation de la commune (fenêtre de la décision, zones de la part) ; audit. Idempotent : `UNIQUE(request_id)`, `UNIQUE(permit_id, participation_id)`, `UNIQUE(decision_id)`. Aucun PDF, SMTP ni réseau dans la transaction.
3. **Délivrance directe** : le port est appelé **avant** que le dossier soit persisté ; `PermitIssuer` ne verrouille alors rien, persiste le laissez-passer qui référence le dossier en mémoire, et le flush unique de la transaction écrit l'ensemble (aucun flush prématuré).
4. **Ordre des verrous** : événement (partagé) → participation → zones → part → ligne du dossier → **ligne du laissez-passer** (commune supplémentaire, LOT 05.3.5 : sérialise l'approbation, les demandes manuelles de notification et les futures révisions) → compteurs (`DEM-` puis `LP-`), toujours en dernier ; aucun producteur ne doit l'inverser.
5. **Rattrapage** : commande `app:permit:backfill`, développement seulement, idempotente, `--dry-run`, même service que la création normale, aucune notification.

## Conséquences fonctionnelles ou techniques

- Migration `permit_*` (LOT 05.1). Le jeton n'apparaît dans aucun audit.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
