# DM-126 — Page de vérification unique, sans paramètre de zone, zones valides d'abord (A3)

- **Identifiant** : DM-126
- **Thème** : LOT 07.3 — Contrôle
- **Statut** : Validée
- **Validation** : Validé par le commanditaire le 17 novembre 2026 (validation du cadrage V2 du LOT 07.3, [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md))
- **Décisions remplacées** : Aucune.

## Contexte

Cadrage du LOT 07.3 (contrôle QR web mobile existant, fiabilisation et recette terrain). Les décisions de fond de DM-115 sont inchangées.

## Décision

1. Une seule page `/v/{jeton}` : **aucun paramètre de zone** (`?zone=` ou autre) n'existe ni ne sera ajouté en V1.
2. Les **zones valides sont affichées en premier**, au sein de chaque autorisation, et les autorisations ayant au moins une zone valide passent avant les autres. Tri stable (l'ordre d'origine est conservé à égalité) ; aucune donnée ajoutée ni retirée, le compteur « N zones actives sur M » et le verdict sont inchangés (un laissez-passer n'est jamais présenté comme entièrement valide si une zone ne l'est pas, DM-115 §4).

## Conséquences fonctionnelles ou techniques

Ordre calculé côté serveur dans `PublicVerificationReader` ; aucune nouvelle entrée client. Une vérification « pour une zone donnée » relèverait d'un nouveau cadrage (référentiel de zones scannables).

## Documents concernés

- [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md), DM-115, DM-113.

## Écarts documentaires signalés

Aucun écart.
