# LOT 03 — Demandes de laissez-passer : schéma de données et règles

> **Révision 2 (incrément 1) — arbitrages S1 à S8 validés le 5 novembre 2026.** Entités, enums, génération de références, initialisation des référentiels et migration développés à l'incrément 1 ; **migration non appliquée avant validation du commanditaire**. Ce document reste la référence du schéma ; il ne couvre que le domaine (formulaires, contrôleurs, stockage, idempotence : incréments suivants).
> Décisions sources : [registre](decisions/README.md) — DM-097 à DM-105, DT-039 ; rappels DM-005, DM-019, DM-051, DM-059, DM-066, DM-084, DM-090, DM-091, DT-001, DT-004, DT-006, DT-008, DT-037. Références UX : `DesignImport/UI-03/LOT-03.md` (arbitrages A1–A7). Écarts du prototype : [revisions/ECARTS-UI-03.md](revisions/ECARTS-UI-03.md).

## 1. Périmètre

Module `src/Request` (couches `Domain`, `Application`, `Infrastructure`, `UI` selon besoin) : dépôt public sans compte, saisie par un agent habilité, référentiels des motifs et des catégories, consultation (liste et fiche) par les communes concernées.
**Hors périmètre** : instruction (approbation, refus, correction d'identité) = LOT 04 (les **notes privées** et le **verrou de 15 min** sont retirés de la V1) ; délivrance directe = LOT 04.3 (DM-108, remplace DM-099 §5) ; laissez-passer, PDF, QR = LOT 05 ; notifications (aucune confirmation par e-mail tant que le lot Notifications n'existe pas) = LOT 07 ; politique de conservation et purge des pièces = LOT 07 ; interface d'administration des référentiels (reportée, DT-039) ; suivi public du dossier (exclu en V1).

Conventions du dépôt : UUID v7 `BINARY(16)` ; dates UTC ; énums PHP en `VARCHAR` ; FK en `RESTRICT` ; aucune suppression physique d'une donnée métier ; tables préfixées par le module (`request_`) ; audit dans la transaction ; `TransactionRunner`.
Nom de classe : `PermitRequest` (et non `Request`) pour éviter la collision avec `HttpFoundation\Request` et le mot « application » du STD.

## 2. Vue d'ensemble

```
Event ─< PermitRequest >─ BeneficiaryCategory
              │       └──── RequestMotif
              ├─< PermitRequestMunicipality >─ EventParticipation
              │            └─< PermitRequestZone >─ EventZone
              ├─< PermitRequestVehicle
              └─< PermitRequestAttachment
(+ request_reference_counter : table technique)
```

Un dossier unique par dépôt (DM-101) ; une « part » par commune concernée porte l'état propre à cette commune.

## 3. Énumérations

| Enum | Valeurs | Source |
|---|---|---|
| `RequestOrigin` | `PUBLIC`, `AGENT` | DM-099 |
| `TravelMode` | `FOOT_BIKE`, `VEHICLE` | DM-103 |
| `PlateFormat` | `SIV`, `FNI` | DM-103 |
| `MunicipalRequestStatus` | `PENDING`, `APPROVED`, `REFUSED` | SFD §2, CDC §7.1 ; **S2 validé**. **LOT 03 : seul `PENDING` est écrit** ; les transitions et la décision sont du LOT 04 (**décision binaire par commune, sans approbation partielle : DM-106**, qui corrige la mention « totale ou partielle » d'origine ; voir [lot-04-schema.md](lot-04-schema.md)). `inReview` et `additionalInfoRequested` du STD ne sont **pas** retenus (pièces complémentaires hors V1 ; le verrou n'est pas un statut). |
| `AuditAction` (ajouts) | `REQUEST_SUBMITTED` (une ligne par commune concernée, `origin` dans `new_value`), `REQUEST_REFERENTIAL_CHANGED` | DT-037 |
| `AuditSubjectType` (ajouts) | `PERMIT_REQUEST`, `REQUEST_MOTIF`, `BENEFICIARY_CATEGORY` | DT-006 |
| `AuditOrigin` (ajout) | `PUBLIC` (dépôt sans compte) ; `actor_id = NULL` | DT-022 |

## 4. Entités

### 4.1 `RequestMotif` — `request_motif` (DM-097, DT-039)
| Champ | Type | Null | Contrainte |
|---|---|---|---|
| `id` | `uuid` | non | PK |
| `code` | `string(40)`, `utf8mb4_bin` | non | `UNIQUE`, `[a-z0-9_]+` ; immuable |
| `label` | `string(150)` | non | libellé neutre |
| `position` | `smallint` | non | ordre d'affichage |
| `active` | `boolean` | non | défaut vrai |
| `needsDetail` | `boolean` | non | défaut faux ; vrai pour « Autre » |

### 4.2 `BeneficiaryCategory` — `request_beneficiary_category` (DM-098)
Mêmes colonnes que `RequestMotif`, sans `needsDetail`. FK `RESTRICT` depuis les dossiers : aucune suppression d'une entrée utilisée (désactivation seule).

### 4.3 `PermitRequest` — `request_permit_request`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | PK |
| `publicReference` | `string(20)`, `utf8mb4_bin` | non | `DEM-AAAA-NNNN`, `UNIQUE`, immuable (DM-105, S1) |
| `event` | ManyToOne `Event` | non | événement du dépôt |
| `origin` | `RequestOrigin` | non | |
| `enteredByUser` | ManyToOne `User` | oui | agent créateur ; `NULL` si `PUBLIC` |
| `enteredByMunicipality` | ManyToOne `Municipality` | oui | commune de saisie ; `NULL` si `PUBLIC` ; non null ⇔ `enteredByUser` non null |
| `lastName`, `firstName` | `string(120)` | non | trim, non vides |
| `address` | `string(255)` | oui | facultative (DM-100) |
| `email` | `string(180)` | oui | normalisé (`EmailAddress`) |
| `phone` | `string(16)` | oui | E.164 (DT-008, `PhoneNumber`) |
| `category`, `categoryLabel` | ManyToOne `BeneficiaryCategory` + `string(150)` | non | libellé copié au dépôt |
| `motif`, `motifLabel` | ManyToOne `RequestMotif` + `string(150)` | non | libellé copié au dépôt |
| `motifDetail` | `string(500)` | oui | non vide si `motif.needsDetail` |
| `travelMode` | `TravelMode` | non | |
| `privacyAttestedAt` | datetime UTC | non | S5 : citoyen = prise de connaissance de la politique de confidentialité ; agent (`enteredByUser`) = attestation que le bénéficiaire a été informé. Ce n'est **pas** un consentement juridique |
| `privacyNoticeVersion` | `string(40)` | oui | version du texte attesté ; textes définitifs validés juridiquement avant production |
| `submittedAt` | datetime UTC | non | = création |

Index : `UNIQUE(public_reference)`, `(event_id, submitted_at)`. Contrainte portée par le domaine : e-mail ou téléphone non null. Les index de rapprochement des doublons (S8 : détection dynamique, aucun champ permanent) seront ajoutés avec le moteur de détection. **Aucune colonne `updated_at`** : le dossier est figé après dépôt (la correction d'identité par un agent, historisée, est du LOT 04).
**Référence (S1)** : `DEM-AAAA-NNNN`, compteur **global à Civipass, distinct par année civile, repartant à 1 chaque année**, quatre chiffres minimum extensibles. **Année = année civile de l'instant du dépôt dans le fuseau IANA de l'événement** (convention cohérente avec DM-062 ; contrairement à `EVT-`, qui utilise l'UTC). Table technique `request_reference_counter` (`year` PK, `last_number`) ; incrément atomique en une instruction SQL (`INSERT … ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)`) dans la transaction du dépôt : le verrou de ligne sérialise les dépôts concurrents, un rollback annule l'incrément (aucun trou, aucun doublon). `LP-` reste réservé aux laissez-passer (DM-105).

### 4.4 `PermitRequestMunicipality` — `request_permit_request_municipality`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `request` | ManyToOne `PermitRequest` | non | |
| `participation` | ManyToOne `EventParticipation` | non | même événement que le dossier |
| `municipality` | ManyToOne `Municipality` | non | commune de la participation (copie dérivée à la construction, pour filtrer sans jointure) |
| `status` | `MunicipalRequestStatus` | non | `PENDING` au dépôt |
| `createdAt` | datetime UTC | non | |

`UNIQUE(request_id, participation_id)`. Index `(municipality_id, status)` (onglets de la liste de la commune), `(participation_id)` (FK).

### 4.5 `PermitRequestZone` — `request_permit_request_zone`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `requestMunicipality` | ManyToOne `PermitRequestMunicipality` | non | |
| `zone` | ManyToOne `EventZone` | non | appartient à la participation de la part (invariant 5) |
| `zoneLabel` | `string(150)` | non | libellé copié (la zone peut être renommée) |

`UNIQUE(request_municipality_id, zone_id)`. Au moins une zone par dossier (au moins une part, au moins une zone par part).

### 4.6 `PermitRequestVehicle` — `request_permit_request_vehicle`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `request` | ManyToOne `PermitRequest` | non | |
| `registration` | `string(12)`, `utf8mb4_bin` | non | normalisée (majuscules, sans espaces ni tirets) |
| `format` | `PlateFormat` | non | `SIV` ou `FNI` |
| `position` | `smallint` | non | ordre de saisie |

`UNIQUE(request_id, registration)` ; index `(registration)` (recherche et doublons). Valeur déclarée : ne valide jamais une autorisation passée (les plaques autorisées seront copiées au LOT 04/05). **1 à 10 plaques** en mode `VEHICLE`, aucune en `FOOT_BIKE` (S3, constante du domaine).

### 4.7 `PermitRequestAttachment` — `request_permit_request_attachment`
| Champ | Type | Null | Remarque |
|---|---|---|---|
| `id` | `uuid` | non | |
| `request` | ManyToOne `PermitRequest` | non | |
| `storageKey` | `string(100)`, `utf8mb4_bin` | non | `UNIQUE`, clé opaque ; aucune URL publique |
| `originalName` | `string(255)` | non | nettoyé |
| `mimeType` | `string(120)` | non | MIME vérifié (la vérification réelle est du code de l'incrément 2) ; l'entité n'accepte que PDF, JPEG, PNG, DOC |
| `sizeBytes` | `integer` | non | ≤ 10 Mo |
| `uploadedAt` | datetime UTC | non | |

S4 : au plus 5 par dossier, 10 Mo chacune, PDF, JPG/JPEG, PNG, DOC (**DOCX exclu**). Aucun ajout après dépôt. Seul le modèle de données est créé à l'incrément 1 : stockage et vérification MIME réelle à l'incrément 2. Écart avec la STD (`stored_file` + `application_attachment`) : une seule table propre au module (S4). Pas de somme de contrôle à ce stade. Accès uniquement par un contrôleur autorisé, jamais par URL directe.

### 4.8 Idempotence du dépôt
Incrément 2A (migration `Version20261105122117`, **non appliquée avant validation**) : table `request_idempotency_key` (`id`, `event_id`, `key_hash` SHA-256, `request_id`, `created_at`), `UNIQUE(event_id, key_hash)`, FK RESTRICT. L'empreinte porte sur origine + agent + clé du client : une même clé ne se rejoue qu'avec la même origine et le même acteur. Une répétition renvoie le dossier d'origine (`replayed`) ; deux tentatives identiques simultanées : la seconde échoue sur l'unicité (conflit à réessayer, qui renverra alors le dossier d'origine).

## 5. Relations, intégrité, suppressions

- Toutes les FK `RESTRICT` ; **aucune suppression physique** d'un dossier, d'une part, d'une zone demandée, d'un véhicule ni d'une pièce au LOT 03 (la purge est du LOT 07).
- Un dossier est **figé** : aucune modification après dépôt dans le périmètre du LOT 03.
- Les libellés copiés (`categoryLabel`, `motifLabel`, `zoneLabel`) gardent l'historique lisible quand un référentiel ou une zone évolue.
- Contraintes SQL : `UNIQUE(public_reference)`, `UNIQUE(request_id, participation_id)`, `UNIQUE(request_municipality_id, zone_id)`, `UNIQUE(request_id, registration)`, `UNIQUE(storage_key)`, `UNIQUE(event_id, key_hash)`, codes des référentiels uniques. Les autres invariants (§6) sont portés par le domaine et les tests.

## 6. Règles de dépôt (invariants)

1. Dépôt possible seulement si l'événement est `ACTIVE` ; événement brouillon, suspendu ou clos : refus explicite (y compris si l'état change entre l'affichage et la soumission).
2. Nom et prénom non vides ; au moins un contact ; adresse facultative (DM-100).
3. Catégorie et motif **actifs** au moment du dépôt ; précision non vide si le motif l'exige.
4. **Zones proposées et acceptées** : `EventZone::mayOpenRights()` (DM-104) — confirmée, `ACTIVE`, non supprimée, validité configurée, participation `ACTIVE` d'un événement `ACTIVE`. Le domaine `PermitRequest` applique `mayOpenRights()` ; **le contrôle « au moins un créneau non supprimé » pour `SPECIFIC_PERIODS` exige les créneaux** (`EventZoneRepository::existsActivationReadyZone`, requête) : il appartient au service de dépôt (incrément 2). Vérifié à nouveau au dépôt, côté serveur.
5. Chaque zone demandée appartient à la participation de sa part ; chaque participation appartient à l'événement du dossier. Aucun `eventId`, `municipalityId`, `participationId` ou `zoneId` du client n'est cru sans cette vérification.
6. Au moins une zone ; une part n'existe que si elle a au moins une zone ; une commune dont la participation n'est pas opérationnelle ne peut pas recevoir de part.
7. `travelMode = VEHICLE` ⇔ 1 à 10 véhicules ; chaque plaque normalisée correspond à SIV ou FNI (DM-103) ; pas de doublon dans un dossier.
8. Pièces : ≤ 5, ≤ 10 Mo, extension, MIME réel et signature contrôlés ; une pièce refusée n'empêche pas le dépôt des autres, qui reste possible sans pièce.
9. Case de confidentialité obligatoire (citoyen) ; attestation obligatoire (agent).
10. Dépôt public : `origin = PUBLIC`, aucun utilisateur ; saisie agent : `origin = AGENT`, agent habilité **actuel** de `enteredByMunicipality`, commune `ACTIVE`.
11. Un seul dossier par clé d'idempotence ; une soumission = une référence (SFD SCR-025).
12. Création du dossier, des parts, des zones, des véhicules, des pièces, de l'idempotence et de l'audit **dans une seule transaction** (un échec n'en laisse aucune trace, pièces comprises).
13. Plusieurs dossiers pour une même personne et un même événement sont autorisés ; le **doublon potentiel** est un indicateur, jamais un blocage, une fusion ni une suppression (SFD §6.3).
14. Doublon : rapprochement sur le même événement (e-mail, téléphone E.164, nom + prénom normalisés, plaque), **calculé à la lecture**, limité aux dossiers **visibles pour la commune qui consulte** (DM-101).

## 7. Permissions et visibilité (DM-101, DM-084, DM-090)

Politique dédiée `RequestAccessPolicy` (à l'image de `EventAccessPolicy`) : la commune se déduit de la ressource (part → participation → commune), jamais du client ; `superAdmin` ne donne aucun droit communal.

| Action | Condition |
|---|---|
| Liste, fiche | membership effectif dans la commune de la part ; participation `ACTIVE` (consultation), `SUSPENDED` ou `CLOSED` (lecture seule) ; `INVITED` : refus |
| Saisie pour un tiers | agent habilité de la commune, commune et participation `ACTIVE` (rôles : S6) |
| Dépôt public | aucun compte ; événement `ACTIVE` ; zones selon §6.4 |

- Une commune ne lit que **ses** parts : requêtes filtrées par participation, projections de lecture dédiées (jamais d'entité exposée au contrôleur) ; la fiche ne contient ni les décisions, ni les droits, ni l'historique d'une autre commune ; la liste affiche « Aussi demandée à … » (noms seulement).
- L'organisatrice voit les seuls dossiers comportant une part pour sa commune.
- Les zones des autres communes : règles de DM-084 (aucun changement).
- Les pièces ne sont lisibles que par les communes ayant une part ; téléchargement par contrôleur autorisé, audit de l'accès (à préciser au LOT 07).

## 8. Audit (DT-006, DT-037)

Un `REQUEST_SUBMITTED` **par part** (donc par commune concernée, `municipality_id` renseigné), dans la transaction du dépôt, sans donnée personnelle ni texte libre : référence, origine, nombre de zones, nombre de véhicules, nombre de pièces. Les modifications de référentiels par commande produisent `REQUEST_REFERENTIAL_CHANGED` (origine `CONSOLE`) ; l'initialisation idempotente n'est pas auditée (comme DT-023).

## 9. Parcours et transactions

- **Public** : six étapes (identité, situation, zones, déplacement, justificatifs, récapitulatif) ; données en session ; **les pièces téléversées avant le dépôt sont placées dans une zone de transit privée, non rattachée à un dossier, purgée après 24 heures (S7 ; mécanisme à l'incrément 2)** ; à la soumission, une transaction crée le dossier et déplace les pièces. Anti double envoi : bouton bloqué + clé d'idempotence.
- **Agent** : formulaire unique, mêmes règles, dans le contexte d'une commune ; mêmes services de dépôt.
- Verrous : verrouillage de l'événement en lecture partagée puis des participations visées selon l'ordre de DT-024, afin qu'un dépôt ne s'écrive pas pendant une suspension ou une clôture concurrente (le détail sera proposé avec l'incrément 2).

## 10. Référentiels : initialisation et commandes (DT-039)

Initialisation idempotente par `code` (aucune entrée existante modifiée) : la migration insère les entrées absentes, et `RequestReferentialInitializer` rejoue la même initialisation (tests, environnements purgés). **Aucune commande Symfony d'administration, aucune interface Sonata au LOT 03** ; les référentiels seront administrables ultérieurement dans Sonata (DT-039). Listes initiales **validées** (S1–S8, 5 novembre 2026), ordre 10, 20, … :

| Motifs (`code` → libellé neutre) | Ordre | Précision obligatoire |
|---|---|---|
| `home` → Accès au domicile | 10 | non |
| `workplace` → Accès au lieu de travail | 20 | non |
| `professional` → Intervention professionnelle | 30 | non |
| `relative_assistance` → Assistance à un proche | 40 | non |
| `priority` → Intervention prioritaire | 50 | non |
| `land_or_holding` → Accès à une exploitation ou un terrain | 60 | non |
| `other` → Autre | 70 | **oui** |

| Catégories (`code` → libellé) | Ordre |
|---|---|
| `private_person` → Particuliers | 10 |
| `professional` → Professionnels | 20 |
| `priority_responder` → Intervenants prioritaires | 30 |
| `public_agent` → Agents publics | 40 |
| `volunteer` → Bénévoles | 50 |

Aucune suppression ; une entrée utilisée reste lisible même désactivée (les dossiers gardent aussi le libellé copié). L'entrée « Accès à une résidence secondaire » du prototype (inactive) n'est **pas** initialisée.

## 11. Sécurité du dépôt public

CSRF ; limitation de débit par IP et par événement (l'IP suit DT-014) ; clé d'idempotence ; validation serveur complète ; aucune donnée personnelle dans les URL ; messages d'erreur sans fuite ; référence non prédictible par énumération utile (aucune page de suivi, donc aucune lecture par référence) ; événement résolu par sa référence publique `EVT-…` (DM-072), pas par identifiant interne.

## 12. Tests exigés

Invariants §6 (chaque règle) ; isolation (identifiants forgés d'événement, commune, participation, zone, dossier ; fiche d'un dossier sans part pour la commune → 404 ; participation invitée → refus ; suspendue → aucune écriture) ; zone « Validité à configurer » refusée même forgée ; zone suspendue/supprimée entre affichage et dépôt ; plaques SIV/FNI valides et invalides ; pièces (taille, nombre, MIME falsifié) ; idempotence (double POST) ; concurrence (suspension de participation pendant un dépôt) ; absence de fuite des décisions d'une autre commune dans la réponse ; doublons limités aux dossiers visibles ; audit par part ; référentiels (initialisation idempotente, entrée inactive non proposée mais lisible, libellé copié).

## 13. Traçabilité des décisions

| Sujet | Décision |
|---|---|
| Motifs, libellés neutres | DM-097, DT-039 |
| Catégories | DM-098, DT-039 |
| Dépôt pour soi ; saisie agent ; attestation | DM-099 |
| Adresse facultative | DM-100 |
| Dossier unique, visibilité | DM-101 |
| Liste unique | DM-102 |
| Déplacement et plaques | DM-103 |
| Zones proposables | DM-104 |
| Références | DM-105 |

## 14. Réutilisation du code existant

`EventZone::mayOpenRights()`, `EventParticipation::isOperational()`, `EventAccessPolicy` (modèle), `MunicipalityContext`, `EventResourceResolver` (modèle), `PhoneNumberNormalizer`, `DoctrinePublicReferenceGenerator` (modèle du compteur), `TransactionRunner`, `AuditTrail`, `DomainRuleViolation`, `OperationConflict`, composants Twig `field`, `choice`, `zone_select`, `plates_field`, `file_upload`, `recap`, `stepper`, `request_list`, `request_card`. **À créer** : `FileStorageInterface` (stockage privé local), limiteur de débit public, lecteur de MIME réel. Les évolutions du design system d'UI-03 sont importées par `design-system/tools/import.mjs` (non fait à cette étape).

## 15. Arbitrages S1 à S8 (validés le 5 novembre 2026)

| # | Arbitrage | Retenu |
|---|---|---|
| S1 | Références | `DEM-AAAA-NNNN`, compteur global par année civile (repart à 1), 4 chiffres minimum extensibles, année dans le fuseau de l'événement (§4.3) |
| S2 | Statuts communaux | `PENDING`, `APPROVED`, `REFUSED` par part ; seul `PENDING` utilisé ; aucun statut global ; ni `inReview` ni `additionalInfoRequested` |
| S3 | Déplacement | `FOOT_BIKE` (aucune plaque) ou `VEHICLE` (1 à 10 plaques françaises SIV/FNI, normalisées, sans doublon) |
| S4 | Justificatifs | 5 × 10 Mo ; PDF, JPG/JPEG, PNG, DOC ; DOCX exclu ; clé de stockage opaque ; modèle de données seul |
| S5 | Confidentialité | Attestation obligatoire (citoyen : prise de connaissance ; agent : bénéficiaire informé) ; pas un consentement juridique ; textes validés avant production |
| S6 | Saisie agent | Agent instructeur habilité ; origine, créateur et commune de saisie historisés ; **demandes multicommunales autorisées** (question 2 de LOT-03.md tranchée) ; **aucun droit d'instruction sur les autres communes** ; habilitations vérifiées côté serveur avec le parcours agent (aucun nouveau système de permissions) |
| S7 | Fichiers temporaires | Purge après 24 h (incrément 2) ; pièces déposées : politique de conservation des dossiers |
| S8 | Doublons | Détection informative, non bloquante, dynamique ; aucun champ permanent ; moteur à un incrément ultérieur |
