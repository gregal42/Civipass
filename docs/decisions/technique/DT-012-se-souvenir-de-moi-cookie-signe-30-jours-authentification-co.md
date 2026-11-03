# DT-012 — « Se souvenir de moi » : cookie signé, 30 jours, authentification complète pour /admin

- **Identifiant** : DT-012
- **Thème** : Authentification
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (échange du LOT 01.2) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Parcours « mot de passe oublié / se souvenir de moi ».

## Décision

Cookie remember-me signé (mot de passe, état actif, privilège superadmin), 30 jours, case décochée par défaut ; `/admin` exige une authentification complète.

## Conséquences fonctionnelles ou techniques

- Révocation par changement de mot de passe ou désactivation ; pas de table de jetons persistants en V1.

## Documents concernés

- [authentication.md](../../authentication.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
