# DM-064 — R4 — Récurrence contenant une heure locale ambiguë : choix explicite

- **Identifiant** : DM-064
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Application de DM-055 aux récurrences.

## Décision

Lorsqu'une récurrence contient une heure locale ambiguë, l'utilisateur choisit explicitement la première ou la deuxième occurrence ; le choix est conservé pour la date concernée ; aucun choix implicite n'est autorisé.

## Conséquences fonctionnelles ou techniques

- La structure de stockage du choix (JSON par date de transition) est une proposition à valider : DT-020.
- Conséquences d'une modification de la récurrence : voir `docs/lot-02-schema.md` §6.4 (invalidation des choix, ressaisie obligatoire).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point technique à valider (DT-020).
