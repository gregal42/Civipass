# DT-010 — Authentification : formulaire, CSRF de session, limitation des tentatives

- **Identifiant** : DT-010
- **Thème** : Authentification
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (échange du LOT 01.2) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Arbitrages 1 à 6 du LOT 01.2.

## Décision

Authentification Symfony par formulaire (email normalisé), jetons CSRF de session pour la connexion et la déconnexion, refus des comptes inactifs après vérification du mot de passe, limitation à 5 tentatives par 15 minutes par identifiant+IP et 50 par IP.

## Conséquences fonctionnelles ou techniques

- `UserChecker`, `LoginRateLimiter`, `LoginSuccessListener`.

## Documents concernés

- [authentication.md](../../authentication.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
