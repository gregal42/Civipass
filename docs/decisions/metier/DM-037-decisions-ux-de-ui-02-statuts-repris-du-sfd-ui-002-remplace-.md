# DM-037 — Décisions UX de UI-02 : statuts repris du SFD, UI-002 remplacé, champs de zone, validité dans le périmètre, pas de refus d'invitation

- **Identifiant** : DM-037
- **Thème** : UX
- **Statut** : Validée
- **Validation** : Tableau « Décisions du commanditaire (phase 1) » de docs/ux/UX-LOT-UI-02-architecture.md §2 ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Tableau « Décisions du commanditaire (phase 1) » de UI-02 §2 (D2, D3 et D8 sont enregistrées en DM-006 à DM-009).

## Décision

D1 : statuts, rôles et transitions repris du SFD et du STD, sans en inventer. D4 : le lot UI-002 est remplacé par UI-02 (ZoneMap obsolète, assistant en 3 étapes, bibliothèque et écran Utilisateurs retirés du prototype). D5 : champs d'une zone = ceux de SCR-017 (libellé, type, description, état). D6 : la validité des zones est dans le périmètre. D7 : une commune invitée ne peut pas décliner (Invitée → Active, invitation sans expiration).

## Conséquences fonctionnelles ou techniques

- Le périmètre UX de UI-02 repose sur ces décisions.

## Documents concernés

- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§2)

## Écarts documentaires signalés

- D7 : l'impossibilité de décliner une invitation entraîne la question de la clôture avec invitations en attente (arbitrage A6.1).
