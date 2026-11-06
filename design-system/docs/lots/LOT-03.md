# LOT 03 — Demandes de laissez-passer : maquettes et code de référence

Prototype HTML/CSS léger : `npm run build && node scripts/prototype.mjs` → `dist/prototype/index.html` (rangée « LOT 03 »).
Aucun backend, aucune persistance : `prototype/proto.js` simule seulement le serveur (validation des étapes, filtres de la liste, fichiers d’exemple).

## Arbitrages appliqués (5 novembre 2026)

| # | Règle | Où |
|---|---|---|
| A1 | Motif choisi dans un référentiel administrable, global en V1 (libellé, ordre, actif) ; précision facultative, obligatoire pour « Autre ». | `MOTIF_REF` (`prototype/data.mjs`), section `_rq_situation`, `cp--reveal` |
| A2 | Cinq catégories de bénéficiaires, référentiel administrable, distinct du motif. | `CATEGORY_REF`, section `_rq_situation`, liste et fiche |
| A3 | Dépôt public pour soi-même uniquement ; saisie pour un tiers par un agent habilité, créateur du dossier, création historisée, circuit normal. | Formulaire public, `le-temple/demandes/nouvelle.html`, fiche `DEM-2026-0157` |
| A4 | Adresse postale toujours facultative. | `_rq_identity` |
| A5 | « Nouvelle demande » ouvre la saisie agent (mêmes champs, mêmes règles). Délivrance directe : lot ultérieur. | Liste, `request_new` |
| A6 | Dossier intercommunal unique. Liste : zones de la commune + « Aussi demandée à … ». Fiche : décisions, droits et historique des autres communes masqués. Participation invitée : aucun accès ; suspendue : lecture seule ; clôturée : historique en lecture seule. | `requests`, `request`, états |
| A7 | Une seule liste : la liste UI-001 évolue (fonctions existantes conservées : recherche, filtres, tri, pagination, états chargement / vide / erreur). | `prototype/templates/requests.html.twig` |

## Écrans

| Écran | Fichier du prototype | États |
|---|---|---|
| 1 Identité | `public/identite.html` | Erreurs (une erreur : message dans le bloc, focus sur le bloc, sans résumé ; plusieurs : résumé focalisé) — `identite-erreurs.html` |
| 2 Situation | `public/situation.html` | Catégorie, motif, précision obligatoire pour « Autre » |
| 3 Zones | `public/zones.html` | Plusieurs communes et zones ; zones non confirmées ou suspendues absentes ; commune sans zone ouverte absente (Pinsac-de-Lande) |
| 4 Déplacement | `public/deplacement.html` | Mode exclusif ; plaques SIV / FNI, plusieurs, format contrôlé |
| 5 Justificatifs | `public/justificatifs.html` | Bouton « fichiers d’exemple » : 2 acceptés, 1 format refusé, 1 trop lourd |
| 6 Récapitulatif | `public/recapitulatif.html` | « Modifier » par section ; case de confidentialité obligatoire ; anti double envoi |
| Confirmation | `public/confirmation.html` | Référence, sans QR, sans suivi en ligne, sans modification |
| Événement fermé | `public/evenement-ferme.html` | Dépôt impossible |
| Liste | `le-temple/demandes.html`, `-traitees`, `-toutes` | Ligne / carte cliquable ; 10 par page sur mobile ; `?etat=vide|erreur|chargement` |
| Saisie agent | `le-temple/demandes/nouvelle.html` | Mêmes validations, sur une page |
| Fiche | `le-temple/demandes/DEM-2026-0157.html` (agent, intercommunale), `…0156` (doublon), `…0154` (approuvée) | Lecture seule : `saumos/demandes-ro/*.html` |
| États | `saumos/demandes-suspendue.html`, `le-temple/demandes-invitation.html` | Participation suspendue, commune invitée |

## Intégration Symfony (repères pour Claude Code)

- Sections de formulaire réutilisables : `prototype/templates/_rq_identity|_rq_situation|_rq_zones|_rq_travel|_rq_docs.html.twig` → à transformer en thèmes de formulaire Symfony ; le parcours public est un flow à 6 étapes (une route par étape, données en session, retour arrière sans perte), la saisie agent un seul formulaire.
- Référentiels : entités `RequestMotif` et `BeneficiaryCategory` (label, position, active, needsDetail pour « Autre ») ; les formulaires listent les entrées actives triées ; l’historique garde le libellé utilisé.
- Validation serveur : nom, prénom, au moins un contact ; catégorie, motif (+ précision si `needsDetail`) ; au moins une zone ouverte d’une participation active ; mode ; si véhicule, ≥ 1 plaque normalisée (majuscules, sans espaces ni tirets) conforme SIV `^[A-Z]{2}[0-9]{3}[A-Z]{2}$` ou FNI `^[0-9]{1,4}[A-Z]{1,3}([0-9]{2}|2A|2B)$` ; ≤ 5 fichiers de 10 Mo (MIME réel) ; case de confidentialité.
- Voters : liste et fiche filtrées par commune courante et état de participation (invitée : refus ; suspendue / clôturée : lecture seule). Ne jamais sérialiser les décisions d’une autre commune dans la fiche.
- Contrôleurs Stimulus utilisés : `cp--reveal`, `cp--repeat`, `cp--zone-select`, `cp--file-upload`, `cp--submit-state`, `cp--unsaved-guard`.

## Questions restantes

1. Libellés du référentiel en première personne (« Accéder à mon domicile ») réutilisés tels quels dans la saisie agent : faut-il un libellé neutre pour le back-office ?
2. Saisie agent : l’agent peut-il demander des zones d’autres communes participantes (prévu ainsi, comme le formulaire public) ?
3. Saisie agent : case « Le bénéficiaire a été informé de la politique de confidentialité » — hypothèse à valider.
4. Format de référence à la confirmation : `DEM-AAAA-NNNN` (UI-001) alors que le SFD (SCR-026) indique `LP-AAAA-…` pour le dossier.
5. « Zone ouverte aux demandes » interprétée comme : zone confirmée et active. Une zone « Validité à configurer » reste proposée : à confirmer.
