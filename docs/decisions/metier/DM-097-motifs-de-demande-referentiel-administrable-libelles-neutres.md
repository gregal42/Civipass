# DM-097 — Motifs de demande : référentiel administrable, libellés neutres (A1)

- **Identifiant** : DM-097
- **Thème** : LOT 03 — Demandes
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

Trois sources divergeaient sur le motif : SFD SCR-021 (texte obligatoire), UX §8 (liste de cinq motifs, texte facultatif sauf « Autre »), modèle de données (`reason_code` + description). Le prototype UI-03 utilisait des libellés à la première personne (« Accéder à mon domicile »).

## Décision

1. Le motif est choisi dans un **référentiel** : global à la plateforme en V1 (ni communal, ni par événement), **persisté en base**, administrable (libellé, ordre, actif) et évolutif.
2. Une **précision** en texte libre est facultative, et **obligatoire pour l'entrée « Autre »** (indicateur `needsDetail` du référentiel).
3. Les **libellés sont neutres** (pas de première personne) et **identiques** dans le parcours citoyen et la saisie par un agent.
4. Une entrée désactivée n'est plus proposée ; elle reste lisible dans les dossiers existants. Le dossier conserve le libellé utilisé au moment de la demande.
5. Le motif est distinct de la catégorie du bénéficiaire (DM-098).

## Conséquences fonctionnelles ou techniques

- Persistance et initialisation : DT-039. Administration ultérieure par Sonata.
- Liste initiale validée le 5 novembre 2026 : [lot-03-schema.md](../../lot-03-schema.md) §10.
- Prototype : libellés de `MOTIF_REF` à neutraliser ([ECARTS-UI-03](../../revisions/ECARTS-UI-03.md)).

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md) ; [SFD-V1.1-proposition-lot03.md](../../revisions/SFD-V1.1-proposition-lot03.md)

## Écarts documentaires signalés

- SFD SCR-021 (« motif texte obligatoire »), UX §8 (cinq motifs, texte libre), STD/modèle (`reason_code`) : remplacés sur ce point, sans modification des originaux.
