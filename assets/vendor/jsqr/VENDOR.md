# jsQR 1.4.0 — copie embarquée (LOT 09, DT-057)

- Source : paquet npm `jsqr@1.4.0` (dépôt `cozmo/jsQR`), fichier `dist/jsQR.js` copié **tel quel**, non modifié.
- Licence : Apache-2.0 (`LICENSE` ci-contre, à conserver).
- Publication : 24 avril 2021. Projet **non maintenu** (stable) ; revue annuelle et veille de sécurité (voir `docs/dependances-js-controle-hors-connexion.md`).
- Aucune dépendance, aucun accès réseau, pas d'`eval` ni de `new Function`.
- SHA-256 de l'archive npm `jsqr-1.4.0.tgz` : `b5299b37917a1fe7a8cab9dd5cc6b8accf82663add80abe5bf7761a921cc6602`
- SHA-256 de `jsQR.js` : `bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859` (vérifié par `tests/Js/vendor-integrity.test.mjs`)
- SHA-256 de `LICENSE` : `c6596eb7be8581c18be736c846fb9173b69eccf6ef94c5135893ec56bd92ba08`
- Intégration : script classique (`window.jsQR`), chargé depuis l'origine de la PWA, jamais depuis un CDN. Interface utilisée : `jsQR(data, width, height, options) → { data } | null`.
