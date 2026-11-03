# DM-080 — Immutabilité du type de zone

- **Identifiant** : DM-080
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Choix d'implémentation de l'étape 2, désormais validé.

## Décision

Le type d'une zone est immuable après sa création. Pour changer de type, il faut supprimer logiquement la zone et en créer une nouvelle. Aucune conversion directe entre types n'est autorisée.

## Conséquences fonctionnelles ou techniques

- `EventZone` n'expose aucune méthode de changement de type ; `update()` ne modifie que le libellé et la description.
- Un changement de type passe par DM-052 (suppression logique) puis une nouvelle zone.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Lève la réserve de l'étape 2.
