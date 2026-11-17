# DM-128 — Messages nationaux fixes des erreurs du contrôle public (A6)

- **Identifiant** : DM-128
- **Thème** : LOT 07.3 — Contrôle
- **Statut** : Validée
- **Validation** : Validé par le commanditaire le 17 novembre 2026 (validation du cadrage V2 du LOT 07.3, [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md))
- **Décisions remplacées** : Aucune.

## Contexte

Cadrage du LOT 07.3 (contrôle QR web mobile existant, fiabilisation et recette terrain). Les décisions de fond de DM-115 sont inchangées.

## Décision

Les réponses d'erreur du contrôle public portent des **messages nationaux fixes**, identiques pour toutes les communes et tous les événements, sans configuration ni stockage :
- **404** : « Ce QR code n'est pas reconnu. Ne concluez pas à une fraude : demandez une autre pièce justificative et signalez le cas à votre responsable. Ce n'est pas une validation. »
- **410** : « Ce QR code a été remplacé. Il n'est plus valable. Demandez le nouveau QR code à la personne ou contactez la commune qui a délivré le laissez-passer. »
- **429** : « La vérification est temporairement indisponible : trop de demandes ont été faites depuis ce réseau. Ce n'est pas un refus d'autorisation. Réessayez dans N minute(s) ou appliquez la procédure de terrain de votre commune. »
- **503** : « La vérification est temporairement indisponible. Ce n'est pas un refus d'autorisation. Réessayez dans quelques minutes ou appliquez la procédure de terrain de votre commune. »
La « procédure de terrain » est celle de la commune, **hors de l'application** (consigne de poste, briefing) ; Civipass n'en invente pas le contenu.

## Conséquences fonctionnelles ou techniques

Gabarits Twig ; aucun texte par événement (il exigerait une colonne en base). Les corps du 404 pour jeton inconnu, mal formé et route coupée restent identiques octet pour octet.

## Documents concernés

- [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md), DM-115, DM-113.

## Écarts documentaires signalés

Aucun écart.
