# DM-130 — Modèle de confiance du contrôle hors connexion

- **Identifiant** : DM-130
- **Thème** : LOT 09 — Contrôle hors connexion
- **Statut** : Validée (le couple de seuils du pilote reste à confirmer : voir « Point ouvert »)
- **Validation** : arbitrages du commanditaire du 17 novembre 2026 (« LOT 09 : arbitrages V2 et préparation du développement »)
- **Décisions remplacées** : Aucune.

## Contexte
[DM-129](DM-129-controle-hors-connexion-obligatoire-en-v1.md) ; CDC §9.2 (12 h / 24 h) ; SFD §7.3 ; DM-115, DM-128.

## Décision
1. **Aucun verdict hors ligne n'est une vérification en temps réel.** Tout verdict hors ligne est **qualifié** « selon la dernière synchronisation », **daté**, accompagné d'un **bandeau permanent et non masquable**, et n'emploie jamais « vérifié », « confirmé » ou « à jour ».
2. **Verdict positif hors ligne : orange**, jamais vert plein. Verdicts : « valide / non valide / révoqué selon la dernière synchronisation », **« indéterminé »** (jeton inconnu ou hors périmètre, données trop anciennes, appareil désactivé ou expiré, horloge incohérente, jeu incomplet ou absent). **Indéterminé n'est jamais un refus.**
3. **Seuils** : avertissement `warn` (12 h par défaut) puis seuil dur `hard`, **plafond technique absolu 24 h**. Entre `warn` et `hard` : verdict positif **dégradé** avec avertissement renforcé ; au-delà de `hard` : indéterminé.
4. **Pilote : `hard` = 12 h.** **Toute augmentation de la durée de confiance exige une décision explicite** ; le plafond de configuration est une variable d'environnement qui ne peut être relevée que par cette décision.
5. **Modes** : `STANDARD` (**défaut du pilote**, sous réserve de l'AIPD et de l'acceptation du risque) et `STRICT` (verdict positif jamais affiché hors ligne : « Indéterminé : confirmation en ligne requise »).
6. **Horloge** : toute incohérence détectée ou confiance insuffisante donne « indéterminé » ; **aucune promesse de résistance absolue** à la manipulation de l'horloge.
7. **Risque résiduel R-OFF-01** : une révocation, suspension, remplacement de QR ou clôture anticipée survenant après la dernière synchronisation est invisible hors ligne jusqu'à la synchronisation suivante, **au plus `hard`**. **Aucune donnée personnelle réelle** n'est utilisée avant : AIPD validée, conditions de sécurité satisfaites, **acceptation écrite du risque par les responsables concernés**.

## Conséquences fonctionnelles ou techniques
- Textes des bandeaux et consigne de terrain à valider par le métier ; la consigne distingue contrôle ordinaire et vérification impérativement à jour (cadrage §14, §24).
- Fiche de risque et modèle d'acceptation : [aipd-controle-hors-connexion-technique.md](../../aipd-controle-hors-connexion-technique.md).

## Point ouvert
`warn` (12 h) ne peut pas égaler `hard` (12 h) : **proposition `warn` = 6 h et `hard` = 12 h pour le pilote** (cadrage §23), à confirmer.

## Documents concernés
[lot-09-controle-hors-connexion-cadrage.md](../../lot-09-controle-hors-connexion-cadrage.md) §13, §14, §24, §25.

## Écarts documentaires signalés
- La fiche d'arbitrage 4 (« état indéterminé ») est complétée : l'état indéterminé hors ligne revient.
