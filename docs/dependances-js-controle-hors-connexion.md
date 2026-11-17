# Audit des dépendances JavaScript — contrôle hors connexion (LOT 09)

**Date** : 17 novembre 2026 · **Nature** : audit préalable (A9) · **Statut** : proposition [DT-057](decisions/technique/DT-057-dependances-javascript-du-controle-hors-connexion.md), **non validée** · **Rien n'est installé ni ajouté au dépôt.** Les archives ont été téléchargées dans un dossier temporaire isolé, uniquement pour mesure ; aucun code n'a été exécuté.

**Contraintes** : règle 9 (pas de build TypeScript) ; règle 10 (aucune dépendance sans accord) ; AssetMapper (modules ES, sans transpilation) ; CSP stricte ; aucun CDN ; iPhone sans `BarcodeDetector` ; développement local sans nouvelle infrastructure.
**Source des données** : registre npm (`npm view`, `npm pack`) au 17 novembre 2026.

## 1. Décodeur de QR

| Candidat | Version | Licence | Publication | Taille (non empaquetée / fichier utile) | Dépendances d'exécution | Scripts d'installation | Maintenance | Remarques |
|---|---|---|---|---|---|---|---|---|
| **jsQR** | **1.4.0** | **Apache-2.0** | 24 avr. 2021 | 280 ko ; `dist/jsQR.js` **257 ko** (non minifié, ~57 ko compressé) | **aucune** | aucun d'installation (scripts de développement seulement) | **non maintenu** (stable, dernière version 2021) | décodage sur pixels (`ImageData`) ; aucune API caméra ; une seule fonction ; licence à conserver |
| qr-scanner | 1.4.2 | MIT | 23 nov. 2022 | 524 ko ; `qr-scanner.min.js` 15,8 ko + `qr-scanner-worker.min.js` 44 ko | `@types/offscreencanvas` (types uniquement) | aucun | peu actif | utilise `BarcodeDetector` si présent, sinon un moteur dans un Web Worker (**provenance du moteur à vérifier**, présence éventuelle d'un code dérivé) ; gère la caméra |
| @zxing/library | 0.23.0 | Apache-2.0 | avr. 2026 | **11,8 Mo** | `ts-custom-error` | — | actif | très volumineux ; non adapté à un jeu de pré-cache léger |
| zxing-wasm | 3.1.7 | MIT | 15 nov. 2026 | 3,7 Mo (WebAssembly) | `type-fest`, `@types/emscripten` | — | **actif** | exige `wasm-unsafe-eval` dans la CSP ; plus lourd à pré-mettre en cache ; performant ; à envisager si jsQR est insuffisant en 09.7 |
| `BarcodeDetector` (API native) | — | — | — | 0 | aucune | — | — | Android/Chrome : oui ; **iPhone/Safari : non** ; à utiliser en **amélioration progressive** |

**Empreintes consignées** (archives npm du 17 nov. 2026) : jsqr-1.4.0.tgz `sha256 b5299b37…cc6602` ; fichier `dist/jsQR.js` `sha256 bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859` ; `LICENSE` `sha256 c6596eb7be8581c18be736c846fb9173b69eccf6ef94c5135893ec56bd92ba08` ; intégrité npm `sha512-dxLob7q6…zU/A==`. qr-scanner-1.4.2.tgz `sha256 c4320730bc62f77bd53905bad303cf6b257b4a125f6a8f1268c42e2f818a4aa1`.

### Recommandation
**`BarcodeDetector` natif lorsqu'il existe + repli sur jsQR 1.4.0**, copié tel quel dans `assets/vendor/jsqr/` avec son `LICENSE` Apache-2.0, empreinte SHA-256 consignée dans un fichier de provenance (`VENDOR.md`), **aucun CDN, aucune installation de paquet, aucune dépendance transitive**.
- *Pour* : zéro dépendance, licence permissive, auditée facilement (un fichier), usage minimal (décoder un `ImageData`).
- *Contre* : projet **non maintenu** (risque de correctif non publié) ; performances à valider sur matériel modeste (réduction de la taille des images, cadence d'analyse) — **mesure obligatoire en 09.1 et recette réelle 09.7** ; repli possible vers zxing-wasm (MIT, maintenu) si les résultats sont insuffisants, au prix d'une CSP `wasm-unsafe-eval` et d'un pré-cache de ~1 Mo à mesurer.
- **Plan de maintenance** : fichier de provenance, revue annuelle, veille de sécurité, remplacement possible derrière une interface d'une seule fonction (`decode(imageData) → texte|null`).
- **Sécurité du décodeur** : l'entrée est contrôlée (image caméra), le décodeur n'exécute aucune donnée ; le texte décodé n'est accepté que s'il correspond au motif d'une URL de l'origine de contrôle (`/v/{jeton}`) ; aucun accès réseau depuis le décodeur.

## 2. Outillage de tests JavaScript

| Besoin | Proposition | Dépendance ajoutée | Justification et limites |
|---|---|---|---|
| Logique pure (verdicts, horloge, intégrité des jeux, compartiments) | **`node:test` et `node:assert`** (intégrés à Node) ; modules ES sans accès DOM | **aucune** | disponible sur l'hôte (Node 26) et dans le conteneur PHP (**Node 20.20.2**) ; pas de `package.json` nécessaire ; exécution `node --test tests/Js/` |
| Stockage local (IndexedDB) et Service Worker | **e2e dans le Chrome local** (157) piloté par le protocole DevTools : mode hors ligne, effacement du stockage, horloge simulée (`Emulation.setVirtualTimePolicy`, ou `Date` surchargé) | **aucune** à ce stade | le client WebSocket intégré n'existe qu'à partir de Node 22 : l'e2e s'exécute **sur l'hôte** ; si insuffisant, `puppeteer-core` (Apache-2.0, sans téléchargement de navigateur) serait **demandé séparément** |
| Intégration à `bin/qa` | **commande séparée** `bin/qa js` (si approuvée) ; **aucune modification** de Docker ni du Makefile sans accord | — | règle 10 |
| Qualité du code JS | convention de style simple, pas d'outil de build ; analyse statique **à décider** | — | éviter l'entrée de la chaîne ESLint/Prettier sans besoin |

**Écartés** : Jest, Vitest, Playwright, Cypress (chaînes de dépendances lourdes, téléchargement de binaires) — réexamen si les tests intégrés s'avèrent insuffisants.

## 3. Décisions demandées (DT-057)
1. Retenir **jsQR 1.4.0 (Apache-2.0)** vendorisé, avec `BarcodeDetector` en amélioration progressive ?
2. Retenir `node:test` sans dépendance et l'e2e DevTools sur l'hôte ?
3. Autoriser à titre de repli, **en cas d'échec de la mesure**, le recours à zxing-wasm (réexamen CSP/volume) ?
