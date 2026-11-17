# DM-127 — Pas de compteurs persistants de scans en V1 (A5)

- **Identifiant** : DM-127
- **Thème** : LOT 07.3 — Contrôle
- **Statut** : Validée
- **Validation** : Validé par le commanditaire le 17 novembre 2026 (validation du cadrage V2 du LOT 07.3, [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md))
- **Décisions remplacées** : Aucune.

## Contexte

Cadrage du LOT 07.3 (contrôle QR web mobile existant, fiabilisation et recette terrain). Les décisions de fond de DM-115 sont inchangées.

## Décision

Aucun compteur de scans ni aucun journal des scans n'est conservé en V1 : la possibilité de « compteurs agrégés compatibles avec la confidentialité » ouverte par DM-115 §6 n'est **pas utilisée**. La détection d'abus repose sur le journal d'incidents de limitation sans donnée (DT-055), les journaux nginx masqués et la supervision système.

## Conséquences fonctionnelles ou techniques

Aucune table ni migration. Un compteur agrégé ultérieur exigerait un cadrage, une migration approuvée et une politique de conservation (DM-117).

## Documents concernés

- [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md), DM-115, DM-113.

## Écarts documentaires signalés

Aucun écart.
