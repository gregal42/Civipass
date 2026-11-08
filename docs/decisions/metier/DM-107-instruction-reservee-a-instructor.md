# DM-107 — Instruction réservée au rôle INSTRUCTOR de la commune

- **Identifiant** : DM-107
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-057 (rôles), DM-101 §4 (accès selon la participation), DM-090 (visibilité des instructeurs).

## Décision

Seul un utilisateur ayant le rôle `INSTRUCTOR` sur la commune de la part peut approuver ou refuser. Sont vérifiés : le membership effectif dans cette commune (écriture : commune `ACTIVE`), le rôle, l'événement opérationnel, la participation `ACTIVE`, et l'appartenance de la part à cette commune (déduite du membership, jamais d'un identifiant du client). `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER` sans `INSTRUCTOR` ne peuvent pas instruire. Une participation `INVITED`, `SUSPENDED` ou `CLOSED` ne peut prendre aucune nouvelle décision. Les ressources hors périmètre reçoivent le même refus uniforme qu'une ressource inexistante.

## Conséquences fonctionnelles ou techniques

- Les droits sont relus sous verrou (le membership a pu changer).
- Aucun droit CWS implicite (DM-005).

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- Aucun écart.
