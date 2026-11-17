# DM-131 — Appareils de contrôle : activation, approbation manuelle obligatoire, désactivation, renouvellement

- **Identifiant** : DM-131
- **Thème** : LOT 09 — Contrôle hors connexion
- **Statut** : Validée (schéma et migration : voir « Réserve »)
- **Validation** : arbitrages du commanditaire du 17 novembre 2026 (A4, A18, A14, A17)
- **Décisions remplacées** : DM-116 §4 (partie « habilitation d'appareils abandonnée »).

## Contexte
[DM-129](DM-129-controle-hors-connexion-obligatoire-en-v1.md) ; SFD SCR-036 et SCR-039 ; DM-121 (séparation CWS), DM-122.

## Décision
1. **Activation par un administrateur communal** (`MUNICIPALITY_ADMIN`, authentification complète) de sa commune, pour un événement dont sa participation est active : **code de 30 minutes**, **plafond initial de 10 appareils** par code.
2. **Protections contre l'usage abusif des codes** : code à 60 bits stocké sous forme d'empreinte, affiché une fois ; usage atomique et borné ; limiteurs et réponse uniforme ; plafonds par commune (3 codes actifs, 20 appareils en attente) ; audit sans donnée personnelle ; libellé obligatoire de l'appareil (cadrage §17).
3. **Approbation manuelle obligatoire** : un appareil enregistré est `PENDING`, **aucune donnée personnelle ne lui est servie** ; l'administrateur l'approuve en **confirmant un code d'appariement affiché sur le téléphone**. **Aucune première synchronisation contenant des données personnelles sans approbation.**
4. **Désactivation explicite** par l'administrateur (motifs codés), effective à la prochaine connexion de l'appareil.
5. **Renouvellement** de l'autorisation d'un appareil par l'administrateur, dans les bornes de configuration, journalisé ; rotation du secret reportée.
6. **Un profil communal par appareil** ; changement de commune = purge du profil précédent après confirmation.
7. Aucun compte de contrôleur ; aucune donnée sur sa personne ; aucun rôle CWS dans l'activation (DM-121).

## Conséquences fonctionnelles ou techniques
- Tables `control_activation` et `control_device` (principe validé ; **schéma définitif et migration à approuver avant exécution**) : cadrage §28.
- Audit : `CONTROL_ACTIVATION_CREATED/REVOKED`, `CONTROL_DEVICE_REGISTERED/APPROVED/DEACTIVATED/RENEWED`, `CONTROL_SYNC_REFUSED`.

## Réserve
Le schéma des deux tables est validé **dans son principe** ; son détail (§28) et la migration sont soumis à approbation (règle 3 de `CLAUDE.md`).

## Documents concernés
[lot-09-controle-hors-connexion-cadrage.md](../../lot-09-controle-hors-connexion-cadrage.md) §17, §26, §28.

## Écarts documentaires signalés
- SFD SCR-036 : « 10 appareils / 30 min réglable par CWS » → réglage par la commune dans des bornes d'environnement ; CWS n'intervient pas (DM-121).
