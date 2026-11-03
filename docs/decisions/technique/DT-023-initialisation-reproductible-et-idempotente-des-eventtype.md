# DT-023 — Initialisation reproductible et idempotente des `EventType`

- **Identifiant** : DT-023
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation du schéma et lancement contrôlé du LOT TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-053 : liste initiale à valider avant initialisation.

## Décision

Liste validée (neuf types, DM-053). Initialisation reproductible et idempotente, clé `code` : (1) la migration `Version20261103070731` insère les neuf types absents (liste figée) ; (2) `EventTypeInitializer` et la commande `app:event-types:initialize` rejouent la même initialisation à partir de `EventTypeCatalog` : seuls les types absents sont créés ; identifiant, libellé, ordre et état d'un type existant ne sont jamais modifiés. Aucune interface d'administration au LOT 02.

## Conséquences fonctionnelles ou techniques

- Exécutée à l'étape 3 sur `civipass_test`.
- Un test unitaire garantit que le catalogue et la liste de la migration sont identiques à la liste validée.
- Après `make test-db`, les tests purgent `event_type` : relancer `app:event-types:initialize` si des types sont nécessaires sur cette base.

## Documents concernés

- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
