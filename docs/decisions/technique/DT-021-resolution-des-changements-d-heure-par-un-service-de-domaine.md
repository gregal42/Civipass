# DT-021 — Résolution des changements d'heure par un service de domaine explicite

- **Identifiant** : DT-021
- **Thème** : Données
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-055 (validée) ; la forme technique du service est une proposition.

## Décision

Un service pur du domaine valide les horaires locaux dans `Europe/Paris` : refuse un horaire inexistant, signale un horaire ambigu et exige un choix. Il n'utilise pas le comportement implicite de PHP (`DateTimeImmutable` accepte et décale silencieusement). Tests sur les deux transitions annuelles, ponctuels et récurrences.

## Conséquences fonctionnelles ou techniques

- Détection par comparaison aller-retour (local → instant → local) et décalages de transition `DateTimeZone::getTransitions`.

## Documents concernés

- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
