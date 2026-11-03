# DT-014 — Adresse IP du client : aucun proxy de confiance par défaut, compteurs sur un seul serveur

- **Identifiant** : DT-014
- **Thème** : Infrastructure
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (échange du LOT 01.2) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Précisions du commanditaire sur l'infrastructure (Nginx + PHP-FPM en production, sans Traefik ni Redis).

## Décision

Aucun `trusted_proxies` par défaut ; `private_ranges` uniquement en développement (Traefik local). Les compteurs de limitation restent dans le cache fichier (une seule instance) ; Redis n'est pas installé.

## Conséquences fonctionnelles ou techniques

- Adaptations documentées pour un reverse proxy, un CDN ou plusieurs instances.

## Documents concernés

- [authentication.md](../../authentication.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
