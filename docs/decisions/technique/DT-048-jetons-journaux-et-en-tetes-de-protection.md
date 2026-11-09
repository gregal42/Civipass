# DT-048 — Jetons, journaux d'accès et en-têtes de protection

- **Identifiant** : DT-048
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-113, DM-114, DM-115 : des jetons figurent dans des chemins d'URL ; les journaux d'accès nginx enregistrent la requête complète.

## Décision

1. **Formats** : jeton de vérification 160 bits (base32 minuscule, 32 caractères, `random_bytes`), stocké en clair ; jeton bénéficiaire 256 bits (base64url), **empreinte SHA-256 seule**. Aucune donnée personnelle ni référence dans un jeton.
2. **Réponses publiques futures** (05.2, 05.4) : `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, noarchive`, réponse uniforme pour un jeton inconnu, mal formé, révoqué ou expiré, limitation de débit par IP (modèle `invitation_link_ip`).
3. **Journaux** : aucun jeton dans les traces applicatives, exceptions ni audits. **Journaux nginx : masquage obligatoire** des chemins portant un jeton ; adaptation **préparée mais non appliquée** (demande de validation avant toute modification d'infrastructure) : voir `lot-05-schema.md` §10. Les journaux d'erreur et ceux de Traefik doivent être contrôlés.
4. Tests de non-divulgation (en-têtes, corps d'erreur, journaux applicatifs) accompagnent chaque route publique.

## Conséquences fonctionnelles ou techniques

- Aucune route publique n'est créée au LOT 05.1.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
