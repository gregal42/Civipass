# DM-114 — Lien bénéficiaire distinct du QR de contrôle

- **Identifiant** : DM-114
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

Le bénéficiaire n'a pas de compte (CLAUDE.md règle 6) et doit récupérer son laissez-passer ; la référence `DEM-` ne suffit pas à y accéder.

## Décision

1. Le bénéficiaire reçoit un **lien sécurisé distinct** du QR : secret aléatoire de **256 bits**, **stocké sous forme d'empreinte SHA-256**, donnant accès à sa page personnelle et au PDF, sans compte.
2. Expiration **30 jours après la fin effective de l'événement** (clôture anticipée comprise : la fin effective est la clôture si elle précède la fin prévue) ; plusieurs liens peuvent être actifs ; réémission possible ; **révocation globale** en cas de fuite.
3. Un rejeu technique d'une notification ne crée pas indéfiniment de nouveaux liens.
4. Le lien bénéficiaire et le jeton QR sont **deux secrets indépendants** : compromettre l'un ne compromet pas l'autre.

## Conséquences fonctionnelles ou techniques

- Implémentation au LOT 05.2 (page, PDF) puis 05.3 (notifications).

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
