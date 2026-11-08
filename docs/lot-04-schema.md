# LOT 04 — Instruction municipale : modèle de décision (incrément 04.1)

> Décisions sources : DM-106 à DM-110, DT-040 à DT-044 ([registre](decisions/README.md)) ; rappels DM-099 (partiellement remplacée par DM-108), DM-101, DM-104, DM-057, DT-024, DT-037, DT-038. Migration : `Version20261108100000`.

## 1. Périmètre

**Livré (04.1)** : décision binaire par commune (`PENDING → APPROVED | REFUSED`, terminale), durée d'autorisation et fenêtre globale, référentiel des motifs de refus, services d'instruction, contrôles d'accès et d'isolation, audit, migration, tests.
**Non livré** : interface d'instruction (04.2), délivrance directe (04.3), correction d'identité (04.4), laissez-passer, QR, PDF, notifications (LOT 05/07), prolongation, révocation, réexamen, approbation partielle.
**Retirés de la V1** : les **notes privées** et le **verrou fonctionnel de 15 minutes** (cités par la roadmap, `lot-03-schema.md` et STD §2.2) ne sont plus prévus ; aucun statut `inReview` n'existe. Ces documents ne sont pas réécrits : leurs mentions sont annotées.

## 2. Modèle

| Élément | Rôle |
|---|---|
| `PermitRequestMunicipality.status` | état courant ; `approve()` / `refuse()` sont les seules transitions (enum `MunicipalRequestStatus`) |
| `PermitRequestDecision` (`request_permit_request_decision`) | décision immuable, **unique par part** |
| `RefusalReason` (`request_refusal_reason`) | référentiel des motifs de refus (7 valeurs initiales par migration) |
| `DurationMode`, `ApprovalDuration`, `GrantWindow` | durée choisie et fenêtre globale |
| `GrantWindowCalculator` | fenêtre à l'approbation ; droits effectifs d'une zone (intersection) |
| `MunicipalDecisionApplier` | moteur commun (règles, décision, audit, port LOT 05) |
| `MunicipalInstructionService` | instruction classique : transaction, verrous, droits |
| `ApprovedPartListener` | point d'intégration du LOT 05 (`NullApprovedPartListener` au LOT 04) |

Colonnes de décision : `id`, `request_municipality_id` (UNIQUE), `municipality_id`, `outcome`, `path`, `decided_at`, `decided_by_user_id`, `refusal_reason_id` + `refusal_reason_code` + `refusal_reason_label` (copiés), `refusal_comment` (500), `duration_mode`, `valid_from`, `valid_until` (nul : jusqu'à clôture). CHECK : voir DT-040. Index `(municipality_id, decided_at)`.

## 3. Règles

1. Instruire : membership effectif (écriture, commune `ACTIVE`) + rôle `INSTRUCTOR` + événement `ACTIVE` + participation `ACTIVE` + part de **cette** commune (DM-107). Hors périmètre : refus uniforme.
2. Approuver : toutes les zones de la part encore ouvrables (confirmées, actives, non supprimées, validité configurée) **et chacune avec au moins une période d'accès effective non vide dans la fenêtre d'autorisation choisie** (pour les cinq modes) ; sinon échec intégral, sans décision ni audit (DM-109 §6). Refuser : aucune condition sur les zones.
3. Durée : H24/H48/H72 en heures absolues ; `CUSTOM` en heure locale de l'événement, sans dépasser sa fin ; `UNTIL_CLOSURE` sans date (DM-109).
4. Refus : motif actif obligatoire ; commentaire ≤ 500, obligatoire pour « Autre » ; code et libellé copiés (DM-110).
5. Audit `REQUEST_APPROVED` / `REQUEST_REFUSED` : acteur, commune, dossier, décision, chemin, nombre de zones, mode et bornes, code du motif ; jamais de commentaire ni de donnée personnelle.

## 4. Transactions

Ordre (DT-041) : événement (partagé) → participation (exclusif) → zones (approbation) → part (exclusif). Horodatage pris après les verrous (DT-038) ; droits relus sous verrou. Concurrence : `OperationConflict(StaleState)` ; violation de `uniq_request_decision_part` traduite en conflit ; EntityManager fermé après conflit (convention `TransactionRunner`).

## 5. Intégration LOT 05 (DT-044)

`MunicipalDecisionApplier` appelle `ApprovedPartListener::partApproved()` dans la transaction. Le LOT 05 y fera un find-or-create idempotent du laissez-passer (verrou du dossier après les participations, avant le compteur), puis posera ses effets externes après commit.

## 6. Tests

`tests/Unit/Request/GrantWindowCalculatorTest.php` (durées, changements d'heure, intersections) ; `tests/Integration/Request/MunicipalInstructionServiceTest.php` (décisions, durées, zones, motifs, sécurité, atomicité, contraintes SQL) ; `MunicipalDecisionConcurrencyTest.php` (processus MySQL réels) ; `RefusalReasonInitializerTest.php`.

## 7. Interface d'instruction (incrément 04.2)

Routes (toutes sous `/communes/{municipalityId}/demandes`, GET sauf mention) : `app_municipal_requests` (liste, filtre `statut=en-attente|approuvees|refusees`), `app_municipal_request` (fiche, enrichie : décision de **sa** commune, validité des zones), `app_municipal_request_approve` (`/{requestId}/approuver`, GET + POST), `app_municipal_request_refuse` (`/{requestId}/refuser`, GET + POST).

- **Approbation en deux temps** : durée → récapitulatif (période calculée par `GrantWindowCalculator` via `MunicipalRequestReader::previewWindow`, jamais en JavaScript) → confirmation (`step=confirm`) qui appelle `MunicipalInstructionService::approve()`. Le récapitulatif (`MunicipalInstructionService::previewApproval`, lecture seule : ni transaction, ni verrou, ni écriture) calcule la fenêtre et vérifie CHAQUE zone avec les mêmes règles que la décision (`MunicipalDecisionApplier::preview`) ; une zone incompatible est nommée avec son explication et la confirmation est désactivée. Il n'est qu'informatif : la confirmation repasse par toutes les validations transactionnelles.
- **Refus** : une étape, motifs actifs du référentiel, rappel de la portée (toutes les zones de la commune).
- **Contrôleurs sans règle métier** : `MunicipalRequestDecisionController` lit, valide la forme, délègue. CSRF propre à la demande (`municipal_request_{approve|refuse}_{requestId}`), POST uniquement, bouton à envoi unique (`cp--submit-state`).
- **Erreurs** : 403 (`InstructorRoleRequired`), 404 uniforme (`OperationNotAllowed`), 422 saisie ou règle refusée avec saisies conservées, 303 vers la fiche avec message quand la part est déjà décidée ou que la décision n'est plus possible (participation non active, événement non opérationnel, commune suspendue). `OperationConflict(StaleState)` (décision concurrente) : message « actualisez la fiche » ; autre conflit technique : le formulaire est réaffiché (409) avec les valeurs conservées. Aucune saisie ne transite par l'URL (le retour du récapitulatif au formulaire se fait en POST ; les paramètres d'URL du formulaire sont ignorés).
- **Turbo** : les trois formulaires d'approbation dont le POST renvoie une page (récapitulatif, retour au formulaire) sont chargés normalement (`data-turbo="false"`) : Turbo rejette une réponse 200 à un POST. Anomalie révélée par la recette navigateur.
- **Fiche** : actions masquées avec explication quand l'instruction est impossible (`DecisionPage::unavailableReason`, informatif : le service revérifie sous verrou).
- Tests : `tests/Functional/Request/MunicipalRequestDecisionTest.php`.

## 8. Reste pour 04.3 et suivants

Délivrance directe (04.3, DM-108 / DT-043), correction d'identité (04.4), écran de confirmation pour un délivreur sans rôle `INSTRUCTOR` (qui ne voit ni liste ni fiche).
