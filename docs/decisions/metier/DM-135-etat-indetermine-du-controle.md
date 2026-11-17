# DM-135 — État « indéterminé » du contrôle : retenu selon les règles du contrôle hors connexion

- **Identifiant** : DM-135
- **Thème** : Contrôle — arbitrages fonctionnels
- **Statut** : Validée
- **Validation** : décisions du commanditaire du 17 novembre 2026 (fiche d'arbitrage 4, complétée par DM-130)
- **Décisions remplacées** : Aucune (précise CDC §9.1, SFD §7.3 ; articule DM-115, DM-128, DM-130).

## Décision
1. Le contrôle distingue des **verdicts** (droits actifs, partiels, suspendus, à venir, expirés, révoqués, événement terminé) et des cas **sans verdict**.
2. **« Indéterminé »** est retenu pour le **contrôle hors connexion**, **exactement** selon [DM-130](DM-130-modele-de-confiance-hors-connexion.md) : jeton inconnu ou hors périmètre, données au-delà du seuil dur, appareil désactivé, expiré ou en attente, horloge incohérente, jeu absent ou incomplet, mode STRICT sans confirmation en ligne. Il **n'est jamais un refus** ni un vert.
3. Le contrôle **en ligne** conserve ses pages sans verdict (404 uniforme, 410, 429, 503) et le ton orange pour « droits suspendus » et « zones partielles » (DM-128).

## Conséquences
Aucun développement supplémentaire (livré au LOT 09). SFD/CDC à réviser : « indéterminé/orange hors ligne » redevient valable pour la PWA ; la consigne de terrain (08.2) l'explique.

## Documents concernés
[arbitrages-pilote-fiches.md](../../arbitrages-pilote-fiches.md) fiche 4 ; DM-115, DM-128, DM-130.
