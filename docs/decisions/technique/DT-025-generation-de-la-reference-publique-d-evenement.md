# DT-025 — Génération de la référence publique d'événement

- **Identifiant** : DT-025
- **Thème** : Données
- **Statut** : Proposée
- **Validation** : Recommandation de l'agent — NON validée par le commanditaire
- **Décisions remplacées** : Aucune.

## Contexte

DM-072 impose unicité et concurrence sûre.

## Décision

Compteur **global permanent** (DM-072) : table `event_reference_counter` à ligne unique (`id` SMALLINT UNSIGNED constant 1, `last_number` BIGINT UNSIGNED), initialisée par la migration. Dans la transaction de création, `DoctrinePublicReferenceGenerator` exécute `UPDATE … SET last_number = last_number + 1` (verrou de ligne tenu jusqu'à la fin de la transaction) puis relit la valeur : deux créations concurrentes se sérialisent, un rollback annule l'incrément. Référence `EVT-{année UTC de création}-{numéro, 6 chiffres minimum}`. SQL direct plutôt qu'`PESSIMISTIC_WRITE` + `refresh` : le rafraîchissement d'une entité déjà gérée écrase l'incrément non flushé (défaut constaté pendant l'étape 2).

## Conséquences fonctionnelles ou techniques

- `UNIQUE(public_reference)` en base ; contrat `PublicReferenceGenerator` ; l'entité `EventReferenceCounter` n'existe que pour le mapping.
- Alternatives écartées : compteur annuel, AUTO_INCREMENT.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
