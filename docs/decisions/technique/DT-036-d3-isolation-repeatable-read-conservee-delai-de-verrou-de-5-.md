# DT-036 — D3 — Isolation REPEATABLE READ conservée, délai de verrou de 5 secondes

- **Identifiant** : DT-036
- **Thème** : Transactions
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Plan 5.1 §6 et §15 (D3).

## Décision

L'isolation MySQL `REPEATABLE READ` est conservée. Les transactions métier du module Event fixent le délai d'attente de verrou de la **session** à 5 secondes (`SET SESSION innodb_lock_wait_timeout`) le temps de l'opération puis le restaurent ; la configuration globale n'est pas modifiée. Aucune nouvelle tentative automatique. Les verrous se prennent avant toute lecture métier sensible ; les méthodes `lock()` relisent l'entité sous verrou (`find` avec mode de verrou) sans `refresh()` simple, qui relirait l'instantané périmé (démontré sur MySQL).

## Conséquences fonctionnelles ou techniques

- `EventTransactions` : délai de session, traduction ciblée de `LockWaitTimeoutException`, `DeadlockException` et des unicités déclarées en `OperationConflict`.
- Tests MySQL de relecture sous verrou (exclusif et partagé), de sémantique des modes et de libération des verrous.
- **Point de vigilance** : la traduction d'une violation d'unicité en `OperationConflict` repose sur la présence du nom de l'index dans le message d'erreur MySQL (`uniq_event_participation_event_municipality`) ; un changement de format ou de nom d'index la désactiverait (l'exception d'origine remonterait alors, sans écriture).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
