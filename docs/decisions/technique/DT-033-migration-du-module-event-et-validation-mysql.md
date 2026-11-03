# DT-033 — Migration du module Event et validation MySQL

- **Identifiant** : DT-033
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Étape 3 du plan d'implémentation TECH-02.1.

## Décision

Migration unique `Version20261103070731` : 7 tables, `ALTER TABLE audit_log` (deux colonnes nullables et leurs index), ligne unique du compteur, neuf `EventType`. Jouée uniquement sur `civipass_test` (MySQL 8.4.13). `DoctrinePublicReferenceGenerator` ne dépend plus que de la connexion DBAL : `UPDATE … last_number + 1` dans la transaction de création ; garanties vérifiées sur MySQL par deux connexions concurrentes et par quatre processus parallèles (aucun numéro partagé, séquence continue, aucun trou après rollback, dépassement de 999 999).

## Conséquences fonctionnelles ou techniques

- `doctrine:schema:validate` : mapping et base synchronisés.
- Base de développement et production non migrées.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
