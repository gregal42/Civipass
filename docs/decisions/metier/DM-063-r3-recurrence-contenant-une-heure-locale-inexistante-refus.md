# DM-063 — R3 — Récurrence contenant une heure locale inexistante : refus

- **Identifiant** : DM-063
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Application de DM-055 aux récurrences.

## Décision

Une récurrence contenant une occurrence dont l'heure locale n'existe pas lors d'un changement d'heure est refusée. L'application identifie la date problématique, explique l'erreur et demande une correction. Aucun ajustement automatique, aucune suppression silencieuse d'occurrence.

## Conséquences fonctionnelles ou techniques

- Validation de la série entière à l'enregistrement (toutes les dates de transition comprises dans la période et correspondant aux jours sélectionnés).
- Le message nomme la date et l'horaire fautifs.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Conséquence : une récurrence quotidienne à 02:30 couvrant le dernier dimanche de mars ne peut pas être enregistrée telle quelle (l'utilisateur modifie l'horaire ou la période).
