# DT-056 — Architecture de la PWA de contrôle hors connexion

- **Identifiant** : DT-056
- **Thème** : LOT 09 — Architecture
- **Statut** : Validée (schéma SQL et dépendances : décisions séparées)
- **Validation** : arbitrages du commanditaire du 17 novembre 2026
- **Décisions remplacées** : Aucune.

## Contexte
[DM-129](../metier/DM-129-controle-hors-connexion-obligatoire-en-v1.md) à [DM-132](../metier/DM-132-donnees-locales-du-controle-hors-connexion.md) ; règle 9 (pas de build TypeScript), règle 10 (dépendances).

## Décision
1. **Moteur de droits unique côté serveur** (`PermitRightsEvaluator`) : le serveur matérialise des **fenêtres de validité en UTC** par autorisation, bornées à `[synchronisation, synchronisation + hard]` ; **aucun calcul de droits en JavaScript** ; l'appareil compare l'heure aux fenêtres.
2. **Recherche par empreinte SHA-256 du jeton** ; le QR, le PDF et les jetons existants sont inchangés.
3. **PWA** : route `/controle`, manifeste, Service Worker de périmètre `/controle/`, **scan dans l'application** ; aucun cookie de session ; CSP dédiée sans script tiers ; code JavaScript en modules ES servis par AssetMapper, sans transpilation.
4. **Sous-domaine dédié comme architecture cible** (isolation du stockage local) ; la mise en place relève de l'installation et d'une autorisation d'infrastructure ; en développement local, `localhost`.
5. **Synchronisation** : appareil authentifié par **secret d'appareil** en en-tête `Authorization` (empreinte stockée) ; **instantané complet ou différentiel par compartiments** avec **condensat racine** ; **repli sur instantané complet** au moindre écart ; application atomique côté appareil (cadrage §27.5).
6. **Interrupteur `OFFLINE_CONTROL_ENABLED`**, défaut `0` : routes `/controle/*` en 404 uniforme tant qu'il est à 0 ; créé au développement (incrément 09.1).
7. **Paramètres de confiance** : plafond technique 24 h (constante) ; plafond de configuration `CONTROL_TRUST_HARD_MAX_SECONDS` (pilote : 43 200).
8. **Pas de chiffrement applicatif présenté comme protection** ; durcissement par minimisation, durée, isolation d'origine, CSP.

## Conséquences fonctionnelles ou techniques
Découpage en incréments 09.1 à 09.7 : [lot-09-controle-hors-connexion-cadrage.md](../../lot-09-controle-hors-connexion-cadrage.md) §20 et §29.

## Documents concernés
`security.md`, `architecture.md`, [DT-055](DT-055-limitation-de-debit-du-controle-public-echecs-et-garde-fou-d-acces.md) (limiteurs).

## Écarts documentaires signalés
Aucun.
