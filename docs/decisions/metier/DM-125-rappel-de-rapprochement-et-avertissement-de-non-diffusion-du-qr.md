# DM-125 — Rappel de rapprochement et avertissement de non-diffusion du QR (A2)

- **Identifiant** : DM-125
- **Thème** : LOT 07.3 — Contrôle
- **Statut** : Validée
- **Validation** : Validé par le commanditaire le 17 novembre 2026 (validation du cadrage V2 du LOT 07.3, [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md))
- **Décisions remplacées** : Aucune.

## Contexte

Cadrage du LOT 07.3 (contrôle QR web mobile existant, fiabilisation et recette terrain). Les décisions de fond de DM-115 sont inchangées.

## Décision

1. La page de vérification publique affiche en permanence, dans le bloc du bénéficiaire, juste sous les plaques : « **Rapprochez le nom et les plaques de la personne et du véhicule.** Ce QR code ne prouve pas l'identité. »
2. La page du bénéficiaire (bloc du QR) et le PDF avertissent : « Ne diffusez pas ce QR code : toute personne qui le possède peut afficher votre nom et vos plaques. »
3. **Aucune donnée n'est ajoutée** à celles de DM-115 §2 : nom, prénom et plaques restent affichés comme décidé. Les pages neutres (événement terminé, 404, 410, 429, 503) n'affichent pas ce rappel (elles n'affichent aucune donnée).

## Conséquences fonctionnelles ou techniques

Gabarits Twig et PDF uniquement ; tests de présence, d'ordre (verdict, nom, plaques, rappel, zones) et d'absence sur les pages neutres. Met en œuvre l'atténuation du risque résiduel de DM-115 §7.

## Documents concernés

- [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md), DM-115, DM-113.

## Écarts documentaires signalés

Aucun écart.
