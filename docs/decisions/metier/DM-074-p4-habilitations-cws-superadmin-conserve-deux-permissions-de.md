# DM-074 — P4 — Habilitations CWS : `superAdmin` conservé, deux permissions dédiées

- **Identifiant** : DM-074
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des derniers arbitrages TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Impersonation (DM-061) et clôture forcée (DM-065) exigent des habilitations plus fines que le booléen `superAdmin`.

## Décision

`superAdmin` est conservé pour l'administration générale de Sonata. Deux permissions distinctes sont prévues : `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT`. Elles ne sont pas attribuées automatiquement à tous les super-administrateurs. TECH-02.1 prépare les mécanismes de sécurité et d'audit nécessaires ; l'impersonation et la clôture forcée sont développées dans un lot Administration CWS dédié.

## Conséquences fonctionnelles ou techniques

- TECH-02.1 : audit (`operator_id`, `batch_id`), énumération des permissions et port de vérification refusant par défaut (proposition DT-029) ; **pas de persistance des habilitations** dans ce lot (point T3).
- Lot Administration CWS : stockage des habilitations, attribution tracée, MFA, interface Sonata.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [backoffice.md](../../backoffice.md)

## Écarts documentaires signalés

- Résiduel T3 : lieu de persistance des habilitations (colonne JSON sur `identity_user`, table dédiée tracée…) — à trancher dans le lot Administration CWS.
