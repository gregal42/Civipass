# DT-013 — Réinitialisation du mot de passe : jeton haché, usage unique atomique

- **Identifiant** : DT-013
- **Thème** : Authentification
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (échange du LOT 01.2) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Validation du plan technique du parcours.

## Décision

Jeton aléatoire de 32 octets stocké sous forme de hash SHA-256, expiration 30 minutes, consommation atomique, 3 demandes par heure et par adresse, 10 par heure et par IP ; e-mails envoyés après la réponse (`kernel.terminate`) via une abstraction de notification.

## Conséquences fonctionnelles ou techniques

- `PasswordResetService`, `DeferredMailQueue`.

## Documents concernés

- [authentication.md](../../authentication.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
