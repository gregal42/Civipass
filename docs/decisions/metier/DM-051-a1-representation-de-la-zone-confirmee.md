# DM-051 — A1 — Représentation de la zone « confirmée »

- **Identifiant** : DM-051
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD : la « Commune entière » est proposée mais jamais active sans confirmation (SCR-012, SCR-015, SCR-017).

## Décision

Option A. La confirmation est distincte du statut administratif : champ `confirmedAt` (nullable). Une zone créée manuellement par un administrateur communal est confirmée dès sa création ; pour une zone manuelle de type « Commune entière », l'administrateur confirme explicitement le périmètre dans le formulaire. Une zone « Commune entière » proposée automatiquement reste non confirmée. Une zone non confirmée n'ouvre aucun droit d'accès. Aucun statut « À confirmer » n'est introduit ; les statuts de zone restent ceux du SFD.

## Options examinées

- (a) Champ `confirmedAt` — **retenue**
- (b) Toutes les zones à confirmer — écartée
- (c) Nouvel état « À confirmer » — écartée

## Conséquences fonctionnelles ou techniques

- `EventZone.confirmedAt` nullable ; `NULL` = proposée, non confirmée.
- Garde d'exploitation et d'activation : `confirmedAt IS NOT NULL`.
- Enum d'état de zone inchangée (ACTIVE, SUSPENDED).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-012, SCR-015, SCR-017)

## Écarts documentaires signalés

- Aucun écart résiduel. Remplace l'ambiguïté signalée à DM-010.
