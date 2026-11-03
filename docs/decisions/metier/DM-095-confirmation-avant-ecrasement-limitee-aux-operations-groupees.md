# DM-095 — Confirmation avant écrasement : opérations groupées ; l'individuel est une sauvegarde explicite

- **Identifiant** : DM-095
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Dernière passe de finalisation avant validation » de TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-025 (thème « Opérations groupées ») exige une confirmation avant le remplacement de configurations existantes. Sa phrase de décision ne nomme pas explicitement les opérations groupées ; son thème, son contexte (« écrasement involontaire ») et son usage dans le plan 5.1 (`confirmOverwrite` de l'opération groupée) les visent.

## Décision

La confirmation explicite avant remplacement d'une configuration existante s'applique aux **opérations groupées** (DM-022 à DM-025). Une modification individuelle d'une zone reste une sauvegarde explicite de l'utilisateur : elle remplace la configuration sans confirmation supplémentaire (DM-075, DM-088). DM-025 n'est pas modifiée.

## Conséquences fonctionnelles ou techniques

- `EventZoneBulkService` : refus en bloc d'un remplacement différent sans `confirmOverwrite` ; une configuration identique n'est jamais un écrasement (DM-088).
- `EventZoneService::configureValidity` : aucune confirmation.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [DM-025](DM-025-confirmation-avant-ecrasement-des-configurations-existantes.md), [DM-075](DM-075-p6-creneaux-supprimes-logiquement-reconfiguration-avec-histo.md), [DM-088](DM-088-d7-remplacement-de-creneaux-identique-aucune-ecriture-ni-aud.md)

## Écarts documentaires signalés

- Lecture littérale de DM-025 ambiguë sur le périmètre (individuel ou groupé) ; **aucune contradiction** : DM-095 la précise sans la réécrire.
