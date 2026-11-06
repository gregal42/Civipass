# DM-103 — Mode de déplacement exclusif ; plaques françaises SIV/FNI

- **Identifiant** : DM-103
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

STD/modèle : trois booléens cumulables ; UX : modes cumulables ; SFD SCR-023 : « piéton/vélo ou véhicule(s) » ; « plaque invalide selon règles retenues » non définies.

## Décision

1. Le mode de déplacement est **exclusif** : « À pied / à vélo » **OU** « En véhicule ».
2. **En véhicule** : **une ou plusieurs plaques françaises SIV ou FNI obligatoires**. **À pied / à vélo** : aucune plaque.
3. Normalisation : majuscules, sans espaces ni tirets. SIV : `^[A-Z]{2}[0-9]{3}[A-Z]{2}$` ; FNI : `^[0-9]{1,4}[A-Z]{1,3}([0-9]{2}|2A|2B)$`.
4. **De 1 à 10 plaques** par dossier en mode véhicule (S3, 5 novembre 2026) ; plaques sans doublon dans le dossier. Ce plafond remplace l'absence de plafond du SFD SCR-023.

## Conséquences fonctionnelles ou techniques

- Une plaque étrangère ne peut pas être saisie en V1 (conséquence acceptée de la décision).
- Les plaques du dossier sont des données déclarées ; elles ne valident aucune autorisation passée (modèle de données §4).

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md)

## Écarts documentaires signalés

- STD/modèle (`requested_pedestrian/bicycle/car`) et UX §8 (modes cumulables) : remplacés sur ce point.
