# DT-029 — Mécanismes de sécurité préparés pour les permissions CWS

- **Identifiant** : DT-029
- **Thème** : Sécurité
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-074 : TECH-02.1 prépare les mécanismes sans développer les fonctions.

## Décision

`CwsPermission` (`CWS_IMPERSONATE`, `CWS_FORCE_CLOSE_EVENT`), port `CwsPermissionChecker` et implémentation `DenyAllCwsPermissionChecker` (refus par défaut, `superAdmin` sans effet) sont **implémentés à l'étape 2** (module Identity). Aucune persistance ni attribution (lot Administration CWS).

## Conséquences fonctionnelles ou techniques

- Sans implémentation, personne n'a ces permissions (sécurisé par défaut).
- Point T3 : lieu de persistance.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
