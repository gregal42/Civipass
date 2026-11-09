# LOT 04 — Instruction municipale : modèle de décision (incrément 04.1)

> Décisions sources : DM-106 à DM-111, DT-040 à DT-045 ([registre](decisions/README.md)) ; rappels DM-099 (partiellement remplacée par DM-108), DM-101, DM-104, DM-057, DT-024, DT-037, DT-038. Migration : `Version20261108100000`.

## 1. Périmètre

**Livré (04.1)** : décision binaire par commune (`PENDING → APPROVED | REFUSED`, terminale), durée d'autorisation et fenêtre globale, référentiel des motifs de refus, services d'instruction, contrôles d'accès et d'isolation, audit, migration, tests.
**04.5** : point d'entrée « Corrections d'identité » pour MUNICIPALITY_ADMIN, recette transversale, matrice des permissions, préparation du LOT 05.
**Non livré** : interface d'instruction (04.2), délivrance directe (04.3), laissez-passer, QR, PDF, notifications (LOT 05/07), prolongation, révocation, réexamen, approbation partielle.
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

## 8. Délivrance directe (incrément 04.3, DM-108 / DT-043)

**Parcours agent** (`/communes/{m}/evenements/{e}/demandes/nouvelle/{étape}`) : identité → situation → zones (de la commune de l'agent seulement) → déplacement → justificatifs → **durée** → récapitulatif → « Délivrer l'autorisation » → confirmation `/communes/{m}/delivrances/{demande}`. L'étape `duree` n'existe pas dans le dépôt public (404).

- **Transaction unique** : `RequestSubmissionService::issueDirectly` crée le dossier et sa part, puis appelle `MunicipalDecisionApplier::approve` (chemin `DIRECT_ISSUANCE`) avant le premier flush, sous les verrous DT-024 ; référence, empreinte d'idempotence, décision et audits (`REQUEST_SUBMITTED` + `REQUEST_APPROVED`) sont écrits ensemble. Tout échec annule tout (référence non consommée). Aucun second moteur.
- **Idempotence** : clé de tentative du brouillon, empreinte distincte (`AGENT+DIRECT`) ; double clic, rejeu du POST et soumissions concurrentes ne créent ni deux demandes ni deux décisions ; le rejeu relit la décision d'origine (cf. DT-043).
- **Permissions de délivrance** : trois rôles, commune de l'URL revérifiée sous verrou, zones étrangères refusées, participation `ACTIVE`, événement opérationnel. Aucun élargissement de la consultation (liste et fiche restent `INSTRUCTOR`).
- **Confirmation** : règle explicite de DM-108 (émetteur seul, relue en base, `no-store`).
- **Durées et zones** : les cinq modes et la règle « chaque zone dans la fenêtre » de DM-109, vérifiées à l'étape durée, au récapitulatif et à la validation.
- **Audit** : réutilise l'audit existant ; acteur, commune, événement, référence, durée, bornes, chemin ; jamais de donnée personnelle ni de commentaire.
- **Limites du 04.3** : aucun e-mail, QR code ni document ; pas de délivrance pour une autre commune ; pas de début futur ; la correction d'identité (04.4) et la révocation / prolongation (LOT 05) restent à faire. La vérification des zones est évaluée à la décision ; les droits effectifs restent recalculés à l'évaluation.
- Tests : `DirectIssuanceServiceTest`, `DirectIssuanceConcurrencyTest`, `DirectIssuanceFlowTest`, `AgentRequestSubmissionTest`.

## 9. Correction d'identité (incrément 04.4, DM-111 / DT-045)

- **Champs** : nom, prénom, adresse, e-mail, téléphone, rien d'autre ; mêmes validations que le dépôt (`PublicRequestStepValidator`, `PermitRequest::correctIdentity`). Migration `Version20261109120000` : `request_permit_request.identity_version`.
- **Droits** : INSTRUCTOR ou MUNICIPALITY_ADMIN d'une commune ayant une part ; EVENT_MANAGER seul 403 ; commune sans part, étrangère ou demande inconnue 404 uniforme. Aucun droit d'instruction n'en découle : l'administrateur voit identité, référence et statut de sa part, pas la fiche.
- **Multicommunal (option A)** : identité unique, correction visible de toutes les communes ; avertissement avec le nombre d'**autres** communes ; **une ligne d'audit par commune** (`REQUEST_IDENTITY_CORRECTED` : noms de champs, commune auteure, agent, date ; jamais de valeur).
- **Concurrence** : verrous événement (partagé) → participation → ligne de la demande (ordre DT-024, pas de cycle) + comparaison de `identity_version` ; périmée : 409, saisie conservée, valeurs enregistrées affichées.
- **Aucun effet sur les droits** : parts, décisions, fenêtres, référence et point d'intégration LOT 05 inchangés (testé). Sans changement effectif : ni version ni audit.
- **Route** : `app_municipal_request_identity`, `GET|POST /communes/{m}/demandes/{id}/identite` ; actions sur la fiche et sur la confirmation de délivrance directe.
- Tests : `RequestIdentityCorrectionTest`, `RequestIdentityConcurrencyTest`, `RequestIdentityFlowTest`, `Lot04TransversalTest` (04.5).
- **Limites** : pas de récapitulatif avant/après (une seule étape avec attestation) ; pas d'historique des valeurs ni de notification de l'intéressé.

## 10. Point d'entrée « Corrections d'identité » (incrément 04.5, DM-111 / DT-045)

- **Problème corrigé** : un MUNICIPALITY_ADMIN n'avait aucun lien vers la correction d'une demande qu'il n'avait pas délivrée (et n'a, par règle, aucun accès à la fiche d'instruction).
- **Solution** : entrée de navigation **« Corrections d'identité »** (INSTRUCTOR et MUNICIPALITY_ADMIN, jamais EVENT_MANAGER seul) vers `app_municipal_identity_corrections`, `GET /communes/{m}/corrections-identite?reference=…` : **recherche par référence publique**, sans second tableau de demandes. Le résultat se limite à la référence, l'événement, le bénéficiaire, le statut de **sa** part et l'action « Corriger l'identité » (route de 04.4). Référence inconnue, mal formée ou d'une autre commune : même réponse « Aucune demande trouvée ». La page n'est jamais mise en cache.
- **Aucun élargissement** : `MunicipalRequestReader` (réservé à INSTRUCTOR) n'est pas utilisé ; la requête dédiée est `PermitRequestRepository::findPartByReference()`. L'administrateur reste en 403 sur la liste, la fiche, l'approbation et le refus, et en 404 sur la confirmation d'une délivrance d'un autre agent (testé).
- **Pas de migration** pour ce point d'entrée.

## 11. Matrice des permissions du LOT 04

| Action | INSTRUCTOR | MUNICIPALITY_ADMIN | EVENT_MANAGER seul | Autre commune |
|---|---|---|---|---|
| Liste et fiche d'instruction | oui | non (403) | non (403) | 404 |
| Approuver / refuser (sa part, participation active) | oui | non | non | 404 |
| Délivrer directement (sa commune) | oui | oui | oui | 404 |
| Confirmation d'une délivrance directe | si émetteur | si émetteur | si émetteur | 404 |
| Corriger l'identité (demande ayant une part dans sa commune, **participation ACTIVE**) | oui | oui | non (403) | 404 |
| Point d'entrée « Corrections d'identité » | oui | oui | non (403) | 404 |

Toute écriture exige une adhésion active à la commune de l'URL (relue sous verrou) ; commune, demande, zones et événements de l'URL ne sont jamais crus.

## 12. Audit et concurrence (synthèse)

- **Audit** : `REQUEST_APPROVED` / `REQUEST_REFUSED` (une ligne par décision, sans commentaire libre) ; `REQUEST_IDENTITY_CORRECTED` (une ligne par commune ayant une part : noms des champs, commune auteure ; jamais d'ancienne ni de nouvelle valeur). Même transaction que l'opération ; un échec d'audit annule tout.
- **Décision** : ordre DT-024 / DT-041 ; décision immuable, unique par part. **Délivrance directe** : création et approbation dans une seule transaction, jamais de part `PENDING` écrite ; idempotente (clé + marqueur `+DIRECT`, relecture verrouillante). **Correction** : verrou de la seule ligne de la demande + `identity_version`.
- **Verrous partagés avec le LOT 05** : la correction et le futur find-or-create du laissez-passer verrouillent la ligne du dossier ; la correction prend l'événement (partagé) et la participation **avant** la ligne du dossier (même ordre que le LOT 05) : aucun cycle tant que cet ordre est tenu.
- **Participations non actives** (testé) : la correction exige une participation **ACTIVE** de la commune qui corrige (INVITED, SUSPENDED, CLOSED : lecture seule), relue **sous verrou** (événement partagé → participation → demande, DT-024) ; une commune ACTIVE corrige toujours l'identité globale quand l'autre est SUSPENDED ou CLOSED, et l'audit est écrit dans chacune ; l'instruction d'une participation non active reste refusée (DM-107). Correction et décision d'une même commune se sérialisent par le verrou de participation.

## 13. Limites connues du LOT 04

- Aucun laissez-passer, QR, PDF, e-mail ni SMS (LOT 05 / 07) ; pas de prolongation, de révocation, de réexamen ni d'approbation partielle ; pas de début futur ni de délivrance pour une autre commune.
- Pas de récapitulatif avant/après, pas d'historique des valeurs, pas de notification de l'intéressé.
- Les droits effectifs par zone sont recalculés à l'évaluation : la décision ne stocke que la fenêtre globale (DT-042).
- Heure inexistante ou ambiguë (changement d'heure Europe/Paris) : couverte par des tests automatisés (unitaires et fonctionnels), pas par une recette navigateur, la date du jour n'étant pas à proximité d'un changement d'heure.

## 14. Préparation du LOT 05 (synthèse, rien d'implémenté)

- **Point d'intégration** : `ApprovedPartListener::partApproved(PermitRequestDecision)`, appelé dans la transaction de décision (instruction **et** délivrance directe) après la décision et l'audit. Find-or-create idempotent du laissez-passer unique du dossier (`UNIQUE(request_id)`) sous verrou de la ligne du dossier (après les participations, avant le compteur) ; autorisation de la commune unique par (laissez-passer, participation). Effets externes (QR, PDF, e-mail) **après commit**, jamais dans la transaction.
- **Identifiants stables à réutiliser** : `PermitRequest.id` (UUID v7), référence publique `DEM-…`, `PermitRequestMunicipality.id` (part), `PermitRequestDecision.id`. L'identité du bénéficiaire peut être corrigée (version `identity_version`) : le laissez-passer ne doit **pas** copier le nom, il le relit à l'affichage.
- **Droits** : la décision stocke `valid_from` / `valid_until` (fenêtre globale) ; les droits effectifs par zone sont `GrantWindowCalculator::effectivePeriods` (fenêtre ∩ événement ∩ périodes de la zone), recalculés à chaque contrôle : une modification ultérieure des périodes de zone, la suspension d'une zone ou la clôture de l'événement agissent sans réécrire la décision.
- **QR code** : identifiant **opaque, stable, non prédictible**, sans donnée personnelle ; droits évalués **côté serveur au moment du contrôle** (jamais portés par le QR).
- **Accès** : la confirmation d'une délivrance reste réservée à l'agent émetteur ; l'accès au futur document doit avoir sa propre règle explicite (pas seulement l'identifiant dans l'URL). Ne pas élargir l'instruction ni le point d'entrée d'identité.
- **Hors LOT 04.5** : ne pas installer Endroid, ne pas générer de QR ni de PDF.
