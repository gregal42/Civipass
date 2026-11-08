# DM-108 — Délivrance directe : approbation immédiate, remplacement de DM-099 §2 et §5

- **Identifiant** : DM-108
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : DM-099 §2 et §5 (en partie).

## Contexte

DM-099 §2 : saisie agent « sans délivrance automatique » ; CDC §7.2 : création directe d'un dossier et d'un laissez-passer.

## Décision

1. Lorsqu'un agent habilité (`MUNICIPALITY_ADMIN`, `EVENT_MANAGER` ou `INSTRUCTOR`) saisit une demande pour un tiers, il agit exclusivement pour sa commune et ne choisit que ses zones ; sa validation vaut **approbation immédiate**, sans seconde instruction.
2. Création et approbation forment une seule opération atomique : aucune part `PENDING` persistante si elle échoue avant validation complète.
3. Elle réutilise le moteur de décision commun (`MunicipalDecisionApplier`, chemin `DIRECT_ISSUANCE`) ; aucun second moteur.
4. **Remplace** DM-099 §2 (circuit normal, aucune délivrance automatique) et §5 (délivrance directe reportée) pour la saisie par un agent. Le reste de DM-099 reste valide (dépôt public pour soi, champs et référentiels identiques, attestation de confidentialité).
5. L'agent ne peut pas demander les zones d'autres communes (clôt la question 2 laissée ouverte par DM-099).

## Conséquences fonctionnelles ou techniques

- Implémentation : LOT 04.3 (non réalisée au 04.1). Le chemin `DIRECT_ISSUANCE` existe déjà dans `DecisionPath`.
- Le laissez-passer lui-même reste du LOT 05.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)

## Écarts documentaires signalés

- DM-099 §2 et §5 : remplacés (fiche DM-099 annotée, texte d'origine conservé).
- Docblock de `RequestSubmissionService::submitByAgent` (zones d'autres communes) obsolète : le code les refuse déjà.
