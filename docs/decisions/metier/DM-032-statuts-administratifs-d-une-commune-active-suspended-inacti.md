# DM-032 — Statuts administratifs d'une commune : ACTIVE, SUSPENDED, INACTIVE

- **Identifiant** : DM-032
- **Thème** : Communes
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrage 2 du LOT 01 (D2 de `docs/lot-01-schema.md`).

## Décision

Les statuts administratifs d'une commune sont ACTIVE, SUSPENDED et INACTIVE.

## Conséquences fonctionnelles ou techniques

- Enum `MunicipalityStatus` (3 valeurs) ; l'abonnement et la participation sont des notions distinctes.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (Écran 47, SCR-047)

## Écarts documentaires signalés

- Le SFD et UI-02 §3 décrivent 5 statuts de commune (en attente, active, suspension planifiée, suspendue, résiliée) : à aligner dans le lot Abonnements (écart S2).
