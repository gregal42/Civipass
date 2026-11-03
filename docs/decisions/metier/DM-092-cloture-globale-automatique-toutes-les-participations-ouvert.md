# DM-092 — Clôture globale automatique : toutes les participations ouvertes sont clôturées dans la même transaction

- **Identifiant** : DM-092
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation de la 5.2 et lancement de la sous-étape 5.3 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-056 (A6.3) : « les autres participations ACTIVE ou SUSPENDED doivent être clôturées au préalable » ; DM-085 (D1) ne couvrait que le brouillon.

## Décision

Lorsque la commune organisatrice clôture un événement, **toutes les participations encore ouvertes (INVITED, ACTIVE, SUSPENDED) sont automatiquement clôturées dans la même transaction**, sans action préalable des communes participantes. La règle vaut pour tout état de clôture autorisé (DRAFT, ACTIVE, SUSPENDED). La clôture est atomique et irréversible ; chaque participation effectivement modifiée produit sa propre trace d'audit, l'événement la sienne, avec un `batch_id` commun ; en cas d'échec, modifications et audits sont annulés ensemble. L'historique intercommunal reste accessible (DM-091) ; les invitations jamais acceptées ne deviennent pas des participations historiques visibles aux autres communes. **Cette décision remplace toute exigence antérieure de clôture préalable des participations** (A6.3 de DM-056, prérequis du plan 5.1).

## Conséquences fonctionnelles ou techniques

- `EventLifecycleService::close` verrouille l'événement (exclusif) puis toutes ses participations (identifiant croissant), clôture l'événement puis chaque participation ouverte (`closeWithEvent`), un audit par objet modifié, un `batch_id` commun.
- Aucun refus au motif qu'une commune n'a pas clôturé sa participation.
- Une participation déjà `CLOSED` n'est pas modifiée et ne produit aucun audit.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Remplace l'exigence de clôture préalable de DM-056 (A6.3) ; généralise DM-085 (D1) à tous les états ; cohérent avec DM-077 et DM-091.
