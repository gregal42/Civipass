# Avis de sécurité Composer — analyse du 9 novembre 2026

> **Mise à jour (LOT 05.2 bis, 9 novembre 2026) : corrigé.** Mise à jour ciblée de 24 paquets `symfony/*` (8.1.10 → 8.1.11 ; contrats 3.7 → 3.8.1, `filesystem` 8.1.9 → 8.1.11), sans mise à jour générale ; `composer audit` : **aucun avis**. `trusted_proxies: private_ranges` remplacé par Traefik seul (`SYMFONY_TRUSTED_PROXIES`). Le reste du document est l'analyse initiale, conservée.

Analyse **séparée** demandée pendant le LOT 05.2 (aucune mise à jour appliquée). Source : `composer audit` sur `composer.lock` (après ajout d'`endroid/qr-code` et `dompdf/dompdf`, qui **n'apparaissent dans aucun avis**).

## Synthèse

- **18 avis, 11 paquets, tous Symfony 8.1.10**, publiés le **4 novembre 2026** ; **un seul correctif : 8.1.11** (sortie le 4 novembre 2026, mise à jour mineure de correction du même mot-clé de version).
- **Gravité : non fournie.** Le flux Packagist (champ `severity` nul) et l'API GitHub consultée n'indiquent ni niveau ni score CVSS ; aucune classification « critique » ou « élevée » **officielle** n'est donc établie. Les niveaux ci-dessous sont une **évaluation d'exposition propre à Civipass**, non une gravité publiée.
- **Aucune vulnérabilité n'est identifiée comme exploitable aujourd'hui par un parcours public de Civipass**, sous réserve de la ligne « trusted proxies » (à traiter en priorité modérée).

## Détail et exposition

| Paquet | Avis (résumé) | Utilisé par Civipass ? | Exposition publique | Évaluation |
|---|---|---|---|---|
| framework-bundle, http-foundation | `private_ranges` : des clients présentant une adresse IPv6 transitoire (IPv4-mappée, 6to4, Teredo, NAT64) sont considérés comme proxys de confiance (GHSA-xg2q-4rrq-657q) | **Oui** : `framework.yaml` → `trusted_proxies: private_ranges` | Oui : l'IP cliente alimente les limiteurs de connexion, de mot de passe oublié et des liens bénéficiaires ; falsifiable par un client | **Modérée** : contournement possible du plafonnement par IP, pas de lecture de données |
| http-foundation | Des URL générées peuvent viser un hôte choisi par le client (GHSA-5mx8-mwrp-5693) | Oui (génération d'URL) | Indirecte | **Faible** : le QR et le lien bénéficiaire du LOT 05.2 utilisent désormais le domaine **configuré** (`DEFAULT_URI`), jamais l'en-tête Host |
| http-foundation | CVE-2026-118514 : les requêtes HEAD contournent l'option `methods` de `access_control` | Oui : règle `^/admin` avec `methods: [POST, PUT, PATCH, DELETE]` | Non : la règle suivante `^/admin` exige le superadmin pour toute méthode ; HEAD ne modifie rien | **Faible** |
| http-foundation | Règles de chemin appliquées devant PHP contournables en ajoutant le chemin au nom du script (GHSA-3h27-hjv7-fqjx) | Concerne les règles de chemin de nginx/Traefik (ex. future règle de masquage des journaux) | À vérifier à l'application de la proposition nginx | **Faible** (à revérifier avant d'appliquer le masquage, `lot-05-schema.md` § 10) |
| http-kernel | Fragments en ligne : en-têtes transmis non fiables fixent hôte/schéma/port (GHSA-wg5q-m2ww-f3c9) | Fragments ESI/inline non utilisés | Non | **Négligeable** |
| routing | CVE-2026-118516 : un espace réservé d'hôte peut correspondre au début du chemin | Routes sans condition d'hôte | Non | **Négligeable** |
| security-http | CVE-2026-118513 (X509) ; connexions programmatiques sans `checkPostAuth()` (GHSA-8f57-m8wj-x65w) | X509 non utilisé ; pas de connexion programmatique en production (seulement `loginUser` des tests) | Non | **Négligeable** |
| serializer (×3) | `UploadedFile` depuis des données de requête ; DataUri vers fichiers locaux ; traversée de chemin d'`ObjectNormalizer` | Composant présent, **aucun usage dans `src/`** | Non | **Négligeable** |
| validator | `Ip` public/private accepte des IPv6 mappées (contournement SSRF) | Contrainte `Ip` non utilisée | Non | **Négligeable** |
| http-client (×2) | Fuite d'identifiants entre origines ; rebinding DNS | Client HTTP non utilisé par le code applicatif | Non | **Négligeable** |
| config, filesystem, yaml | XXE ; chemin « scheme:// » traité comme racine ; analyse YAML coûteuse | Fichiers de configuration de confiance ; pas de chemin saisi par l'utilisateur | Non | **Négligeable** |

## Corrections proposées (non appliquées)

1. **Mise à jour corrective `symfony/*` 8.1.10 → 8.1.11** (`composer update "symfony/*" --with-all-dependencies`, version de correction) : corrige les 18 avis. Impact attendu : aucun changement d'API (même mineure) ; à valider par `make quality` et un passage sur les parcours de connexion et de dépôt. **Hors périmètre du LOT 05.2 : en attente de votre accord.**
2. **Réduire `trusted_proxies`** à l'adresse (ou au réseau) réelle de Traefik/nginx au lieu de `private_ranges` : atténue immédiatement l'avis sur l'IP cliente, sans dépendance ; relève de la configuration d'infrastructure, **en attente de validation** (à tester derrière Traefik).
3. Revérifier les règles de chemin nginx (avis `index.php/…`) lors de l'application du masquage des jetons dans les journaux.

Aucune vulnérabilité affectant directement une fonctionnalité publique avec une gravité critique ou élevée **n'est établie** ; je le signalerais sans attendre dans le cas contraire.
