# DM-085 — D1 — Clôture d'un événement DRAFT : toutes les participations sont clôturées

- **Identifiant** : DM-085
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-077 (« participations invitées » ambiguë pour un brouillon).

## Décision

Lorsqu'un événement `DRAFT` est clôturé, toutes ses participations sont clôturées, y compris celles déjà acceptées. Les prérequis de clôture normale (A6.3 : autres participations ACTIVE/SUSPENDED déjà clôturées) ne s'appliquent qu'aux événements `ACTIVE` ou `SUSPENDED`. Implémentation en 5.3.

## Conséquences fonctionnelles ou techniques

- Un brouillon n'a aucune donnée opérationnelle à protéger : la clôture ferme l'organisatrice, les invitations et les participations déjà acceptées dans la même transaction, avec audit et `batch_id`.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Lève l'ambiguïté de DM-077 (D1).
- Généralisé par DM-092 : la clôture globale ferme toutes les participations ouvertes, quel que soit l'état de l'événement (et plus seulement DRAFT).
