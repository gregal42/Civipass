# DM-073 — P1 — Continuité opérationnelle : abonnement expiré ≠ suspension administrative

- **Identifiant** : DM-073
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des derniers arbitrages TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Levée de la contradiction apparente entre A13 et LOT 01 D16/D17.

## Décision

L'expiration d'un abonnement n'interdit que la création de nouveaux événements ; les événements ouverts et les capacités de gestion des communes continuent. La suspension administrative pour motif de sécurité est une notion distincte qui peut entraîner des restrictions spécifiques (DM-034).

## Conséquences fonctionnelles ou techniques

- Aucun contrôle d'abonnement hors création d'événement.
- D16/D17 s'appliquent à la commune administrativement `SUSPENDED` ou `INACTIVE`, jamais à une commune dont seul l'abonnement a expiré.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [lot-01-schema.md](../../lot-01-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Contradiction A13 / D16-D17 résolue ; règle complétée par DM-076 (droits d'utilisation). T5 (motif de suspension non modélisé) à confirmer.
