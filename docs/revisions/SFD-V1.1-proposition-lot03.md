# SFD — proposition de révision V1.1 (règles du LOT 03 : demandes)

> **Statut : proposition non validée.** Complète le [SFD V1.0 détaillé](../references/SFD/SFD-V1.0-detaille.docx) (non modifié) et [SFD-V1.1-proposition-lot02.md](SFD-V1.1-proposition-lot02.md). Chaque règle cite la décision validée dont elle découle. Schéma : [lot-03-schema.md](../lot-03-schema.md).

## 1. Formulaire public (SCR-019 à SCR-026)

- Dépôt **pour soi-même** exclusivement (DM-099). Aucun compte, aucun brouillon sauvegardé, aucune date demandée au citoyen.
- **SCR-020 Identité** : nom, prénom obligatoires ; au moins un contact (e-mail ou téléphone) ; **adresse postale toujours facultative** (DM-100).
- **SCR-021 Situation** (remplace « motif texte obligatoire ») : **catégorie** du bénéficiaire (référentiel, DM-098) et **motif** (référentiel distinct, libellés neutres, DM-097) ; précision facultative, obligatoire pour « Autre ». Justificatifs facultatifs inchangés (5 × 10 Mo, PDF/JPG/JPEG/PNG/DOC).
- **SCR-022 Communes et zones** : zones **proposables** = confirmées, actives, non supprimées, à validité configurée, d'une participation opérationnelle (DM-104). Une commune sans zone proposable n'est pas affichée ; aucune : dépôt indisponible. La mention « commune invitée non active : demande enregistrable, instruction différée » est **supprimée** (une commune invitée n'a aucune zone proposable).
- **SCR-023 Déplacement** : mode **exclusif** « À pied / à vélo » ou « En véhicule » ; en véhicule, de 1 à 10 plaques françaises SIV ou FNI, sans doublon (DM-103, S3).
- **SCR-025 Récapitulatif** : case de confidentialité obligatoire (libellé juridique à valider).
- **SCR-026 Confirmation** : référence **`DEM-AAAA-NNNN`** (DM-105, remplace `LP-AAAA-…`) ; aucun QR, aucun suivi public.

## 2. Saisie par un agent (CDC §7.2, DM-099)

Un agent habilité saisit un dossier pour un tiers : mêmes champs, règles et référentiels que le dépôt public ; créateur et origine « agent » historisés ; circuit normal ; attestation que le bénéficiaire a été informé de la politique de confidentialité. Les demandes **multicommunales** sont autorisées, sans droit d'instruction sur les autres communes (S6). **La création directe d'un laissez-passer est reportée** (lot ultérieur).

## 3. Liste et fiche (SCR-027, SCR-028 ; DM-101, DM-102)

- Une seule liste : onglets À traiter (défaut) / Traitées / Toutes ; recherche nom, référence, téléphone, e-mail, plaque ; catégorie ; zones de la commune ; « Aussi demandée à … » ; badge doublon potentiel (limité aux dossiers visibles de la commune).
- Fiche : catégorie, motif et précision, origine (formulaire ou agent), identité, contacts, pièces, zones et véhicules **demandés à la commune**, doublons. **Décisions, droits et historiques des autres communes jamais affichés.**
- Accès selon la participation : invitée = aucun ; active = consultation (instruction au lot 04) ; suspendue = lecture seule ; clôturée = historique en lecture seule.

## 4. Statuts

Par commune : **en attente / approuvé / refusé** (SFD §2). Le LOT 03 ne crée que « en attente ». `inReview` et `additionalInfoRequested` (STD §5) ne sont pas retenus.

## 5. Corrections documentaires proposées

- SCR-021 : « motif texte obligatoire » → catégorie + motif (référentiels).
- SCR-022 et REC-02 : une commune invitée n'a pas de zone proposable ; elle n'est donc pas ciblable avant d'être opérationnelle.
- SCR-023 : « piéton/vélo ou véhicule(s) » → mode exclusif ; plaques françaises SIV/FNI.
- SCR-026 : `LP-AAAA-…` → `DEM-AAAA-NNNN` ; `LP-` réservé aux laissez-passer.
- CDC §6.1 : sept catégories et « périodes souhaitées » remplacées par DM-098 et par l'absence de dates côté citoyen (SFD).
- CDC §7.2 : « laissez-passer » créé à la saisie agent → lot ultérieur.
