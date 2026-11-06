# DM-101 — Dossier intercommunal unique et visibilité des demandes entre communes (A6)

- **Identifiant** : DM-101
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

SFD §2.1, §6.1, SCR-027/028 ; CDC §7.1 (« vue coordonnée limitée » non définie) ; DM-005, DM-084, DM-090, DM-091.

## Décision

1. Une demande déposée pour plusieurs communes est **un dossier unique** comportant **une part par commune concernée**, avec des décisions indépendantes.
2. **Liste** d'une commune : les zones demandées **à cette commune** et la mention « Aussi demandée à … » (noms de communes seulement).
3. **Fiche** : les **décisions, droits et historiques des autres communes sont masqués** et **jamais sérialisés** dans la réponse.
4. Accès selon la **participation de la commune** : **invitée** = aucun accès ; **active** = consultation et instruction (lot 04) ; **suspendue** = lecture seule ; **clôturée** = historique en lecture seule.
5. L'organisatrice ne voit que les dossiers concernant son propre territoire (SFD §6.1) ; aucun droit CWS implicite (DM-005).

## Conséquences fonctionnelles ou techniques

- Politique d'accès dédiée (commune déduite de la ressource, jamais du client), filtrage au niveau des requêtes, tests avec identifiants forgés.
- La « vue coordonnée limitée » du CDC est limitée à la mention des autres communes (point 2).
- Les doublons potentiels ne sont recherchés que parmi les dossiers visibles de la commune.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md) §7

## Écarts documentaires signalés

- Précise DM-084/DM-090/DM-091 pour les demandes. SFD SCR-022 et REC-02 : voir DM-104.
