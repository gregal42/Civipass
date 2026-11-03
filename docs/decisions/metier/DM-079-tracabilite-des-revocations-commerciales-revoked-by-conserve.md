# DM-079 — Traçabilité des révocations commerciales : `revoked_by` conservé

- **Identifiant** : DM-079
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-076, DM-078, DT-030 (`revoked_by` ajouté à l'étape 2, à confirmer).

## Décision

L'ajout de `revoked_by` à `MunicipalityCommercialAccess` est validé : l'identité de l'administrateur CWS ayant révoqué un accès est conservée, avec `granted_by`, `granted_at`, `expires_at`, `revoked_at` et `reason`. Les accès commerciaux successifs restent historisés.

## Conséquences fonctionnelles ou techniques

- Colonne `revoked_by` (FK `RESTRICT` vers l'utilisateur, nullable) dans la migration de l'étape 3.
- Aucune suppression physique.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [README.md](../../decisions/README.md)

## Écarts documentaires signalés

- Lève la réserve « à confirmer » de DT-030.
