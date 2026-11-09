# DM-112 — Laissez-passer unique par demande ; validité calculée, jamais persistée

- **Identifiant** : DM-112
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-106 (décision binaire par commune), DM-109 (durées), DM-035 (commune suspendue ou inactive), DM-105 (références), DT-042, DT-044. Le LOT 04 produit des décisions ; le LOT 05 en tire des droits utilisables.

## Décision

1. **Un laissez-passer logique par demande**, créé à la première approbation d'une commune (instruction ou délivrance directe), jamais recréé : les approbations suivantes ajoutent une **autorisation communale** au même laissez-passer, qui garde son identité, sa référence `LP-AAAA-NNNN` et son jeton QR.
2. **Une autorisation par participation communale approuvée** ; elle copie la fenêtre de validité de la décision d'origine et les zones de la part approuvée. Les **plaques** sont copiées de la demande **une seule fois**, à la première émission, et appartiennent au laissez-passer.
3. **Modèle hybride.** Les **faits administratifs** sont persistés : création, autorisations, fenêtres, zones, plaques, révisions, révocations. Les **états opérationnels** (valide, expiré, pas encore valide, hors période, zone suspendue…) sont **calculés à la demande** à partir de l'état réel ; aucun statut de validité n'est stocké.
4. **Le calcul est unique** (`PermitRightsEvaluator`) et réutilise les calculateurs du LOT 04 ; il prend en compte l'autorisation et sa fenêtre courante, l'événement, la participation, la **commune (statut administratif, DM-035)**, la zone et la révocation. Le résultat est **structuré par autorisation et par zone** : un laissez-passer n'est jamais présenté comme globalement valide si seules certaines zones le sont.
5. **L'identité n'est jamais copiée** : elle reste celle de la demande (DM-111), lue à l'affichage ; une correction d'identité ne crée ni autorisation ni QR.
6. **`LP-` n'est jamais un secret d'accès** : référence d'affichage, ni recherchable ni suffisante pour obtenir un document (DM-105).

## Conséquences fonctionnelles ou techniques

- Un statut de validité en base deviendrait faux dès qu'une zone, une participation, une commune ou l'événement change : écarté.
- La décision (LOT 04) reste immuable ; les droits courants vivent dans l'autorisation.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

- `data-model.md` prévoyait un « état » stocké sur `Permit` : remplacé par le calcul (voir CONTRADICTIONS, section I).
