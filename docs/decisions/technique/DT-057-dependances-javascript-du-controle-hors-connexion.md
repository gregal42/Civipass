# DT-057 — Dépendances JavaScript du contrôle hors connexion (décodeur QR, tests)

- **Identifiant** : DT-057
- **Thème** : LOT 09 — Dépendances
- **Statut** : Validée
- **Validation** : validée par le commanditaire le 17 novembre 2026 (message « LOT 09 : validation définitive et lancement du développement » : jsQR 1.4.0 embarqué, `BarcodeDetector` en amélioration progressive, aucun CDN, intégrité et licence conservées, tests `node:test`, aucun outil externe sans justification ; zxing-wasm reste un recours conditionnel, jamais ajouté automatiquement ; tout assouplissement significatif de la CSP exige une nouvelle validation)
- **Décisions remplacées** : Aucune.

## Contexte
A9 : décodeur QR embarqué et outillage de tests JavaScript, avec audit préalable. Règles 9 et 10 de `CLAUDE.md`. Audit : [dependances-js-controle-hors-connexion.md](../../dependances-js-controle-hors-connexion.md).

## Décision
1. **Décodeur** : `BarcodeDetector` natif lorsqu'il existe, repli sur **jsQR 1.4.0** (Apache-2.0) copié tel quel (`dist/jsQR.js`, SHA-256 consigné) dans `assets/vendor/jsqr/` avec sa licence ; aucune dépendance transitive, aucun script d'installation, aucun CDN.
2. **Tests JavaScript** : `node:test` (intégré, **aucune dépendance**) pour les modules purs ; e2e hors ligne par le Chrome local piloté par le protocole DevTools, sans bibliothèque ajoutée, **à confirmer** après essai.
3. **Aucune modification** de Docker, du Makefile ni de `composer.json` ; pas de `package.json` à ce stade.

## Réserves
jsQR n'est plus publié depuis avril 2021 (stable, non maintenu) ; performance à mesurer sur matériel modeste (09.1/09.7) ; Node 20 dans le conteneur (le client WebSocket intégré est absent) : le pilotage e2e s'exécute sur l'hôte.

## Documents concernés
[dependances-js-controle-hors-connexion.md](../../dependances-js-controle-hors-connexion.md).

## Écarts documentaires signalés
Aucun.
