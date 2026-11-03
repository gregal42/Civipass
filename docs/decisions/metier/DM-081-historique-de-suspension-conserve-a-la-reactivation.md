# DM-081 — Historique de suspension conservé à la réactivation

- **Identifiant** : DM-081
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation de l'étape 2 et lancement de l'étape 3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Remplace le comportement de l'étape 2 (effacement à la réactivation).

## Décision

Lorsqu'un événement ou une participation passe de `SUSPENDED` à `ACTIVE`, le statut redevient `ACTIVE` et `suspensionReason` ainsi que `suspendedAt` sont **conservés** ; l'audit enregistre la réactivation. Ces champs représentent la **dernière suspension connue** : ils ne prouvent pas une suspension en cours, seul le statut courant fait autorité. Toute nouvelle suspension remplace ces valeurs par celles de la nouvelle suspension. **Complément (validation de l'ajustement final)** : `Event` porte désormais `suspendedAt`, comme `EventParticipation` : à la suspension, `suspendedAt` reçoit la date et l'heure UTC et `suspensionReason` est renseigné ; à la réactivation, les deux sont conservés ; une nouvelle suspension les actualise. Le journal d'audit conserve l'historique complet des transitions.

## Conséquences fonctionnelles ou techniques

- `EventParticipation::reactivate()` et `Event::reactivate()` n'effacent plus rien ; `suspend()` remplace motif et date, pour l'événement comme pour la participation.
- `event_event.suspended_at` (DATETIME nullable, UTC) ajouté par la migration complémentaire `Version20261103072308`, sans modification de la migration `Version20261103070731`.
- Les lectures ne doivent jamais déduire une suspension en cours de ces champs (utiliser le statut).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [README.md](../../decisions/README.md)

## Écarts documentaires signalés

- Complète DM-056 (A6.2). Point « date de suspension de l'événement non stockée » : levé.
