# DT-055 — Limitation de débit du contrôle public : échecs de jetons inconnus et garde-fou d'accès

- **Identifiant** : DT-055
- **Thème** : LOT 07.3 — Sécurité, exploitation
- **Statut** : Validée
- **Validation** : Validé par le commanditaire le 17 novembre 2026 (validation du cadrage V2 du LOT 07.3, [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md))
- **Décisions remplacées** : Aucune (révise le comportement de limitation du LOT 05.4.3, décrit dans [exploitation-verification-publique.md](../../exploitation-verification-publique.md) § 3 ; complète DT-048 §2).

## Contexte

Avant ce lot, le plafond d'échecs (20 jetons bien formés mais inconnus / 15 min / IP) était consulté **avant** la recherche : une fois atteint, tout jeton, **même valide**, était refusé (429) depuis cette adresse. Derrière une IP partagée (NAT, 4G partagée), vingt anciens QR scannés ou vingt jetons inventés par une personne malveillante bloquaient tous les contrôleurs jusqu'à 15 minutes.

## Décision

1. **Les jetons inconnus ne bloquent jamais un jeton valide.** Ordre : interrupteur, plafond d'accès (429), recherche du jeton, puis, **pour un jeton bien formé mais inconnu seulement**, consommation de l'allocation d'échecs (404 tant qu'elle tient, 429 ensuite). Un jeton valide, retiré (410) ou d'événement clos (statut neutre) est servi même si le plafond d'échecs de l'adresse est atteint. Un jeton mal formé n'est ni recherché ni compté.
2. **Le plafond d'accès devient un garde-fou contre l'inondation**, applicable à **tout** jeton, valide compris : **1 500 consultations / 10 minutes / IP** (IPv6 : /64), réglable par `VERIFICATION_ACCESS_LIMIT`. Valeur **provisoire** : elle ne dépend d'aucun nombre de contrôleurs et doit être confrontée à un **test de charge représentatif sur l'environnement de recette** avant toute production. Le plafond d'échecs (`VERIFICATION_FAILURE_LIMIT`, 20 / 15 min) est conservé : il freine l'énumération.
3. **Pourquoi l'énumération reste impossible** : le jeton fait 160 bits (DM-113) ; deviner un jeton valide a une probabilité de 2⁻¹⁶⁰. La différence de réponse (200 pour un jeton valide, 429 pour un inconnu au-delà de l'allocation) ne révèle donc rien d'exploitable ; le garde-fou d'accès borne de toute façon le nombre de recherches par adresse.
4. **Panne du stockage des compteurs** : 503 contrôlé (page sans donnée, `Retry-After: 60`, journal `error` existant). L'échec d'écriture du compteur d'échecs donne aussi un 503, jamais une limitation abandonnée en silence.
5. **Journal des incidents, sans donnée** : une ligne de niveau `error` (le journal par défaut de Symfony n'écrit que les erreurs) au message fixe, **sans adresse, clé, jeton ni donnée personnelle**, lorsqu'un plafond vient d'être atteint pour une adresse : « plafond de consultations atteint » ou « plafond de jetons inconnus atteint ». Une seule ligne par plafond, par adresse et par fenêtre.
6. L'adresse reste celle de `REMOTE_ADDR` ; les en-têtes de provenance d'un client sont ignorés (aucun proxy de confiance en production mono-instance, DT-014).

## Conséquences fonctionnelles ou techniques

- `VerificationThrottle::recordFailure()` retourne désormais l'attente éventuelle ; `check()` ne consulte plus le compteur d'échecs.
- Risque résiduel : une personne partageant l'adresse peut saturer le garde-fou d'accès (429 explicite, borné à 10 minutes ; le contrôleur applique la procédure de terrain, DM-128).
- Tests : jeton valide après de nombreux jetons inconnus, retiré et événement clos après le plafond d'échecs, plafond d'accès valable pour tout jeton, IP partagée, IPv6, en-têtes de provenance forgés, panne du stockage, journal sans donnée.

## Documents concernés

- [lot-07-3-cadrage.md](../../lot-07-3-cadrage.md) § 13, [exploitation-verification-publique.md](../../exploitation-verification-publique.md), DT-048, DM-113, DM-115.

## Écarts documentaires signalés

- `exploitation-verification-publique.md` (§ 3) décrivait l'ancien comportement : mis à jour dans le même incrément.
