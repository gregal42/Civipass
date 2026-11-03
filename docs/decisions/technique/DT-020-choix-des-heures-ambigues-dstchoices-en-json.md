# DT-020 — Choix des heures ambiguës : `dstChoices` en JSON

- **Identifiant** : DT-020
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des derniers arbitrages TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

A5.3 impose une représentation non ambiguë ; la structure n'est pas décidée.

## Décision

Option A validée. `dstChoices` est conservé en JSON sur `EventZoneValidity` (liste `{date, field: start|end, occurrence: first|second}`) ; la cohérence du JSON est garantie par le domaine (ensemble exact des couples ambigus). Les choix devenus invalides sont supprimés lors d'une modification ; les nouveaux choix nécessaires sont explicitement demandés.

## Conséquences fonctionnelles ou techniques

- Une modification crée un nouveau créneau (DM-075, T4) : ses `dstChoices` ne reprennent que les choix encore valides (même date, même champ, heure inchangée) ; tout autre couple ambigu exige un choix explicite.
- Les créneaux supprimés conservent leur `dstChoices` (historique).
- Revalidation des créneaux futurs en cas de changement des données de fuseaux (commande, hors TECH-02.1).

## Documents concernés

- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
