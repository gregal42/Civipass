# DM-094 — UNCONFIGURED : état initial, jamais cible de `configureValidity`

- **Identifiant** : DM-094
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Dernière passe de finalisation avant validation » de TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-011 définit trois modes de validité dont « non configurée ». Aucune décision n'autorisait un retour à cet état.

## Décision

`UNCONFIGURED` est un état initial. Il ne peut pas être sélectionné comme cible de `configureValidity` (ni en individuel, ni en groupé). Aucune opération implicite de réinitialisation n'existe : seuls `EVENT_DURATION` et `SPECIFIC_PERIODS` sont configurables.

## Conséquences fonctionnelles ou techniques

- `ZoneValidityConfigurator::parse` refuse tout mode autre que `EVENT_DURATION` et `SPECIFIC_PERIODS`.
- Une zone configurée le reste ; pour retirer sa validité opérationnelle, on la suspend ou on la supprime logiquement (DM-052).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [DM-011](DM-011-configuration-explicite-de-la-validite-d-une-zone.md)

## Écarts documentaires signalés

- Aucun écart : précise l'usage de l'état initial de DM-011.
