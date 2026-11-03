# DM-076 — T1 — Droits d'utilisation commerciaux : abonnement, TRIAL ou accès commercial CWS

- **Identifiant** : DM-076
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation du schéma et lancement contrôlé du LOT TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Complète DM-070 (la règle « abonnement actif ou TRIAL valide »).

## Contexte

Complète DM-070 et DM-073 : la règle « abonnement actif ou TRIAL valide » était incomplète.

## Décision

Une commune administrativement `ACTIVE` peut créer un événement si elle possède au moins un droit d'utilisation valide parmi : (1) abonnement actif ; (2) période d'essai TRIAL valide ; (3) accès commercial explicitement accordé par CWS. L'accès commercial CWS est attribué à une commune précise, peut être accordé sans abonnement payant, possède une date d'expiration facultative (une commune peut donc en bénéficier sans expiration), peut être révoqué, est traçable et permet la création et la gestion normale des événements. L'expiration ou la révocation d'un droit n'interdit que la création de nouveaux événements et n'interrompt pas la gestion des événements existants. Une suspension administrative pour raison de sécurité reste indépendante de ces droits. Un abonnement payant n'est donc **pas** obligatoire pour utiliser Civipass.

## Conséquences fonctionnelles ou techniques

- Conserver `EventCreationPolicy` ; le port d'abonnement devient `MunicipalityEntitlementProvider`, dont le contrat distingue la **source** du droit (abonnement, TRIAL, accès commercial CWS).
- TECH-02.1 : implémentation provisoire fonctionnelle permettant d'autoriser explicitement une commune, sans module Abonnements ni bypass global (DT-030, Proposée).
- Aucun module de facturation ni de gestion des abonnements dans TECH-02.1.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Évolue LOT 01 D9 (« abonnement ACTIVE ») : DM-033 est précisée. Le texte d'origine de `docs/lot-01-schema.md` n'est pas modifié.
