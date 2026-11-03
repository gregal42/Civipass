# DM-058 — A8 — « Commune entière » proposée automatiquement

- **Identifiant** : DM-058
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-012, SCR-015, SCR-022 ; DM-010.

## Décision

Option A. À la création d'une participation communale, Civipass propose automatiquement une zone « Commune entière — [nom de la commune] », initialement non confirmée. Une commune dont la participation est `INVITED` ne peut pas modifier ses zones. Après acceptation, son administrateur peut confirmer et configurer la zone. La proposition automatique ne rend jamais la zone opérationnelle.

## Options examinées

- (a) proposée à l'ajout de la participation, zones modifiables seulement dès ACTIVE — **retenue**

## Conséquences fonctionnelles ou techniques

- La zone proposée est créée avec la participation (même transaction) avec `confirmedAt = NULL`.
- Écriture sur les zones refusée tant que la participation est `INVITED` ; autorisée si `ACTIVE` ou `SUSPENDED` (DM-066).
- La zone proposée est supprimable et n'est jamais recréée automatiquement (DM-068).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-012, SCR-015, SCR-022)

## Écarts documentaires signalés

- Point R6 levé par DM-066 : une participation SUSPENDED garde l'édition de ses zones (aucune zone opérationnelle). Seule la participation INVITED interdit l'écriture.
