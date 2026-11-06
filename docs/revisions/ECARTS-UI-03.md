# Écarts du prototype UI-03 avec les décisions validées (DM-097 à DM-105)

Source : `DesignImport/UI-03` (non modifié). Changements **à demander à la conception** avant l'intégration ; aucun n'est appliqué ici.

## Zones (DM-104)

| # | Constat | Changement attendu |
|---|---|---|
| Z1 | `scripts/prototype.mjs` (bloc LOT 03, `zoneCommunes`) ne filtre que `status === 'active'` : les zones « Validité à configurer » (Le Temple : Lotissement des Cigales, Chemin du lac, Les Agaux) sont proposées | Exclure toute zone sans validité configurée ; critères complets : confirmée, active, non supprimée, validité configurée, participation active d'un événement actif |
| Z2 | Conséquence : Lugaret (seule zone « Hameau des Pins », validité à configurer) n'a plus de zone et doit disparaître de `zones.html` ; Le Temple n'offre plus que Commune entière, Secteur nord, Accès forestier est ; Saumos inchangée | Mettre à jour le jeu de démonstration et `public/zones.html`, la saisie agent et le récapitulatif |
| Z3 | Pas d'état « aucune zone proposable » (toutes les communes sans zone) | Ajouter un état « Dépôt indisponible » avec message clair |
| Z4 | Pas d'état « zone devenue indisponible entre l'affichage et l'envoi » | Ajouter un message de refus explicite, saisies conservées |
| Z5 | `_rq_zones` : « Seules les zones actuellement ouvertes aux demandes sont proposées » | Texte à conserver, devient exact |

## Libellés et référentiels (DM-097, DM-098)

| # | Constat | Changement attendu |
|---|---|---|
| L1 | `MOTIF_REF` à la première personne (« Accéder à mon domicile », « Accéder à mon lieu de travail », « Assister un proche », etc.) | Libellés neutres identiques en parcours citoyen et saisie agent : liste validée dans [lot-03-schema.md](../lot-03-schema.md) §10 |
| L2 | `MOTIF_REF` contient « Accéder à une résidence secondaire » (inactif) | Ne pas l'initialiser ; conserver le cas « entrée inactive non proposée » dans la démonstration |
| L3 | `CATEGORY_REF` au pluriel (« Particuliers », « Professionnels », …) | Conforme à la liste validée (S1–S8) ; seul l'intitulé « Vous êtes… » peut être reformulé si la lecture est gênante |
| L4 | `MOTIFS` de `data.mjs` (jeu fictif des dossiers, par ex. « Accès à la résidence principale ») non alignés sur le référentiel | Utiliser uniquement les entrées du référentiel |
| L5 | Légendes différentes selon l'origine (« Pourquoi devez-vous vous déplacer ? » / « Motif du déplacement ») | Conformes : seules les **options** doivent être identiques |

## Références (DM-105)

| # | Constat | Changement attendu |
|---|---|---|
| R1 | `pass_ref: 'LP-2026-0157'` existe pour toute demande, y compris en attente, et `LP-` est recherché dans la liste (`search`) | `LP-` seulement après la première approbation, jamais recherché avant ; numéro non dérivé de celui de la demande |
| R2 | Confirmation publique : vérifier qu'aucune référence `LP-` n'apparaît | Référence `DEM-AAAA-NNNN` seule |

## Autres écarts

| # | Constat | Décision |
|---|---|---|
| A1 | Mode exclusif « À pied / à vélo » ou « En véhicule » | Conforme à DM-103 |
| A2 | Plaques étrangères non gérées | Conforme (hors V1) ; message d'aide « plaque française » à conserver |
| A3 | Zones d'autres communes dans la saisie agent | Autorisées (S6 : demandes multicommunales, sans droit d'instruction sur les autres communes) |
| A4 | Libellés juridiques (case de confidentialité, attestation) provisoires | À remplacer par le texte validé |
| A5 | Fiche : décisions et historiques des autres communes masqués | Conforme à DM-101 |
