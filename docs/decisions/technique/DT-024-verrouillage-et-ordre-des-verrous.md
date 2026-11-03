# DT-024 — Verrouillage et ordre des verrous

- **Identifiant** : DT-024
- **Thème** : Transactions
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Révise l'ordre initial de la proposition DT-024 (compteur en tête).

## Contexte

Cohérence des gardes d'activation et de clôture sous concurrence.

## Décision

Ordre des verrous validé (D2) : **événement → participations (identifiant croissant) → zones (ordre déterministe) → compteur global de références, uniquement lors de la création, aussi tard que possible, après validation des préconditions**. Lors de la création, l'événement n'existe pas encore : aucun verrou d'événement n'est simulé ; le seul verrou est la ligne du compteur, prise après validation complète ; le verrou de ligne de l'événement créé est implicite à son insertion. Modes : exclusif sur l'événement pour modification, activation, suspension, clôture, invitation ; partagé pour les participations et les zones ; exclusif sur la participation.

## Conséquences fonctionnelles ou techniques

- Remplace l'ordre initial « compteur → événement → participations → zones ».
- Implémenté par `EventLocker` : verrouiller une participation exige l'événement déjà verrouillé (signature), vérifie l'appartenance, mode toujours explicite.

## Documents concernés

- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
