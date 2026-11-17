# DM-129 — Contrôle hors connexion obligatoire en V1 (PWA limitée au contrôle terrain)

- **Identifiant** : DM-129
- **Thème** : LOT 09 — Contrôle hors connexion
- **Statut** : Validée
- **Validation** : décision métier du 17 novembre 2026 (« contrôle hors connexion obligatoire en V1 ») ; approche confirmée par le commanditaire le 17 novembre 2026 (messages « LOT 09 : validation de principe » et « arbitrages V2 »)
- **Décisions remplacées** : DM-115 §5 (partie « PWA hors ligne… évolution distincte à redéfinir »), DM-116 §4 (partie « habilitation d'appareils contrôleurs abandonnée »), report de la PWA consigné au 17 novembre 2026 dans le roadmap. Le reste de DM-115 et de DM-116 est inchangé.

## Contexte
CDC §9.2 et SFD §1.1, §7.3, SCR-036…039 prévoient une PWA hors ligne ; DM-115 l'avait reportée. Le report est levé : la vérification hors connexion est un prérequis du premier pilote.

## Décision
1. Le **contrôle hors connexion est obligatoire en V1**, avant le premier pilote ; **le contrôle en ligne existant est conservé** et reste le mode préféré dès que le réseau répond.
2. Il prend la forme d'une **PWA limitée au contrôle terrain** : aucune application native, **aucun compte permanent de contrôleur**.
3. **Architecture : synchronisation temporaire des autorisations** sur le terminal, avec un **moteur de droits unique côté serveur**. **Les QR signés sont exclus de la V1** : ils ne sont compatibles ni avec la règle « aucune donnée personnelle dans le QR », ni avec la stabilité du QR (DM-113 §3), ni avec l'affichage de l'identité (DM-115 §2).
4. Le contrôle hors connexion est activé par l'interrupteur `OFFLINE_CONTROL_ENABLED`, **désactivé par défaut**, distinct de `PUBLIC_VERIFICATION_ENABLED`.
5. Aucune donnée personnelle réelle n'est utilisée avant validation de l'AIPD et acceptation du risque résiduel par les responsables concernés ([DM-130](DM-130-modele-de-confiance-hors-connexion.md)).

## Conséquences fonctionnelles ou techniques
- `ControllerActivation` et `ControllerDevice` (retirés au LOT 05) sont remplacés par `control_activation` et `control_device` ([DM-131](DM-131-appareils-de-controle-activation-et-approbation.md)).
- Cadrage : [lot-09-controle-hors-connexion-cadrage.md](../../lot-09-controle-hors-connexion-cadrage.md). Architecture : [DT-056](../technique/DT-056-architecture-de-la-pwa-de-controle-hors-connexion.md).
- Le mode papier 48 h reste reporté (fiche d'arbitrage 1) ; le besoin « sans réseau » est couvert par la PWA.

## Documents concernés
`security.md`, `architecture.md`, `data-model.md`, `development-roadmap.md`, [audit-couverture-fonctionnelle.md](../../audit-couverture-fonctionnelle.md).

## Écarts documentaires signalés
- Lève l'incohérence I1 de l'audit (SFD/CDC incluent la PWA en V1).
- Contredit la décision du 16 novembre « développement local uniquement » pour la **recette** sur appareils réels : celle-ci est maintenue **à la fin du développement local**, avec autorisation d'infrastructure séparée.
