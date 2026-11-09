# DM-111 — Correction d'identité du bénéficiaire

- **Identifiant** : DM-111
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « LOT 04 — Incrément 04.4 » et arbitrages validés, 9 novembre 2026 : option A multicommunale, `identity_version`, aucun historique des valeurs, rôles INSTRUCTOR et MUNICIPALITY_ADMIN)
- **Décisions remplacées** : Aucune.

## Contexte

DM-107 (consultation et instruction réservées à INSTRUCTOR), DM-108 (délivrance directe), DM-106 (décision immuable par part), DT-037 (aucune donnée personnelle dans l'audit). L'identité du bénéficiaire (nom, prénom, adresse, e-mail, téléphone) est portée **une seule fois** par la demande (`PermitRequest`) et lue par toutes les communes ayant une part.

## Décision

1. **Objet.** Corriger une **erreur** sur le nom, le prénom, l'adresse postale, l'adresse e-mail ou le téléphone. Aucun autre champ n'est modifiable (catégorie, motif, déplacement, plaques, zones, commune, événement, justificatifs, durée, dates, statut, décision, agent décisionnaire, référence). Mêmes règles de validation et de normalisation que le dépôt, dont « au moins un moyen de contact ».
2. **Jamais un effet sur les droits.** La correction ne repasse aucune part en attente, ne recalcule ni ne crée aucune décision, ne modifie ni la fenêtre ni la référence, ne réémet rien et ne déclenche pas le point d'intégration LOT 05 (DT-044). Le futur QR code reste attaché à la même autorisation.
3. **Pas de transfert d'autorisation.** L'écran le dit ; l'agent **atteste** qu'il s'agit de la même personne (case obligatoire, jamais pré-cochée). Aucun blocage automatique fondé sur la distance entre deux noms.
4. **Permissions.** INSTRUCTOR **ou** MUNICIPALITY_ADMIN d'une commune qui possède une part de la demande, adhésion active. EVENT_MANAGER seul : refus (403). Commune sans part, étrangère ou demande inexistante : refus uniforme (404). Un MUNICIPALITY_ADMIN n'obtient **aucun** droit d'instruction : accès ciblé à la route de correction, qui montre l'identité, la référence et le statut de **sa** part, jamais la liste ni la fiche.
5. **Multicommunal (option A).** L'identité reste unique : la correction est visible de **toutes** les communes concernées, immédiatement, que leurs parts soient en attente, approuvées ou refusées. L'écran **avertit** l'agent et indique le nombre d'**autres** communes concernées. Chaque commune concernée reçoit **une ligne d'audit** (champs modifiés, commune auteure, agent, date).
6. **Concurrence.** Compteur `identity_version` : le formulaire embarque la version lue ; une correction fondée sur une version périmée est refusée (conflit « état périmé ») sans rien écrire. La saisie de l'agent est conservée, les valeurs enregistrées sont affichées, et l'agent doit confirmer de nouveau.
7. **Audit.** Événement `REQUEST_IDENTITY_CORRECTED` : **noms** des champs modifiés, commune auteure, référence, version, nombre de communes, jamais une ancienne ni une nouvelle valeur. Aucune table d'historique des valeurs. Une soumission sans changement effectif n'écrit ni version ni audit.
8. **Point d'entrée (incrément 04.5).** « Corrections d'identité » dans la navigation des INSTRUCTOR et MUNICIPALITY_ADMIN : recherche **par référence** dans la commune, résultat limité (référence, événement, bénéficiaire, statut de sa part), puis route de correction. Aucune liste de demandes, aucun accès à la fiche ni à l'instruction ; une référence inconnue ou d'une autre commune donne la même réponse.
9. **Hors périmètre** : transfert, modification des zones ou des véhicules, prolongation, révocation, nouvelle instruction, notification de l'intéressé.

## Conséquences fonctionnelles ou techniques

- Migration `Version20261109120000` : colonne `request_permit_request.identity_version`.
- Action « Corriger les informations du bénéficiaire » sur la fiche (INSTRUCTOR) et sur la confirmation de délivrance directe (INSTRUCTOR, MUNICIPALITY_ADMIN).
- Conséquence assumée : une commune peut modifier ce que les autres voient ; la traçabilité par commune la rend contrôlable.

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)
- [DT-045](../technique/DT-045-correction-d-identite-verrou-version-et-audit.md)

## Écarts documentaires signalés

Aucun.
