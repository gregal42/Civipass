# DM-072 — A14 — Référence publique d'événement `EVT-YYYY-NNNNNN`

- **Identifiant** : DM-072
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

`public_reference` du schéma V1.0 (reporté en A14 initial).

## Décision

A14 précisé par P5 (option B). Format `EVT-YYYY-NNNNNN`. **Compteur global permanent, sans réinitialisation annuelle** ; l'année est celle de la création ; six chiffres minimum, extensibles au-delà de 999 999 ; référence unique et immuable ; génération transactionnelle garantissant l'unicité. Distincte de l'identifiant technique ; utilisable dans les interfaces, les échanges entre communes, les documents et le support CWS.

## Conséquences fonctionnelles ou techniques

- Table de compteur à ligne unique (DT-025) verrouillée dans la transaction de création.
- Exemple : `EVT-2026-000154`, puis `EVT-2027-000155` l'année suivante.
- Une référence séquentielle n'est jamais un contrôle d'accès.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point P5 levé. Résiduel T6 : fuseau retenu pour « année de création » (proposition : UTC).
