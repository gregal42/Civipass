# DM-060 — Simplicité opérationnelle : configuration simple par défaut, avancée à la demande

- **Identifiant** : DM-060
- **Thème** : UX
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Principe UX validé lors de la consolidation du LOT 02.

## Décision

Civipass reste extrêmement simple à utiliser en urgence. Parcours simple : créer un événement, confirmer « Commune entière », choisir « Pendant toute la durée de l'événement », activer — sans paramétrage horaire avancé. Configuration avancée à la demande : plusieurs zones, créneaux continus, ponctuels, récurrences, application à plusieurs zones, activation ou suspension groupées. Les deux parcours utilisent le même modèle métier ; aucune architecture distincte pour le mode simple.

## Conséquences fonctionnelles ou techniques

- Aucune entité ni service spécifique au mode simple : le mode simple est un cas d'usage du modèle commun (validityMode = EVENT_DURATION).
- Les écrans UI doivent présenter le parcours simple en premier.

## Documents concernés

- [SFD-V1.1-proposition-lot02.md](../../revisions/SFD-V1.1-proposition-lot02.md)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
