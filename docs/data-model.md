# Civipass — Modèle de données V1 (base de conception)

> **Révision LOT 02 (TECH-02.1) — traçabilité** : les passages sur la bibliothèque de zones et les instantanés (`Zone`, `EventZone` avec source/instantané, « snapshots ») sont **remplacés** par [`lot-02-schema.md`](lot-02-schema.md) (décisions DM-008, DM-051, DM-052, DM-029). Le texte d'origine ci-dessous est conservé tel quel pour mémoire ; il n'est plus la référence pour les zones, événements et participations.

**Statut : modèle logique consolidé, pas encore schéma Doctrine final.** Les cardinalités détaillées, index, suppressions et types exacts seront figés lot par lot avant migration.

## Identité / communes (5)
- `User` : UUID, email normalisé unique, hash du mot de passe, prénom, nom, actif, superadmin, timestamps.
- `Municipality` : UUID, nom, code INSEE unique, contacts, statut.
- `MunicipalityMembership` : UUID, `user`, `municipality`, **roles cumulables** (JSON validé côté domaine ou table dédiée si requêtes l'exigent), actif ; unicité (user, municipality). Rôles : `MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR`. Pas de rôle de contrôleur dans les memberships.
- `UserInvitation` : email, commune, rôles proposés, hash de jeton à usage unique, expiration, acceptation/révocation, auteur.
- `MunicipalitySubscription` : commune unique, statut `TRIAL/ACTIVE/EXPIRED/CANCELLED`, dates. Création d'événement uniquement si `ACTIVE`.

## Événements (4)
- `Event` : UUID, nom, description, type, `organizerMunicipality`, `createdBy`, statut `DRAFT/ACTIVE/SUSPENDED/CLOSED`, dates.
- `EventParticipation` : event + municipality uniques, statut `INVITED/ACTIVE/SUSPENDED/CLOSED`, invitation/acceptation/activation.
- `Zone` : référentiel communal, nom, description, actif.
- `EventZone` : rattachement à participation, source Zone facultative, **instantané nom/description**, actif. Création pendant événement actif possible, même par commune invitée. Désactivation d'une zone ≠ révocation automatique d'autorisations déjà délivrées.

## Demandes (6)

> **Révision LOT 03 (5 novembre 2026)** : `Application` (booléens `isProfessional`, `hasVehicle`, motif et statut globaux) est remplacée par le modèle proposé dans [`lot-03-schema.md`](lot-03-schema.md) (DM-097 à DM-105). Le texte d'origine ci-dessous est conservé pour mémoire.
- `Application` : UUID, référence, événement, `isProfessional` booléen, `hasVehicle` booléen, motif, statut, dates. Plusieurs demandes par personne et événement possibles. Dossier non modifiable par citoyen après soumission.
- `ApplicantContact` : lien 1:1 vers Application, nom, prénom, adresse, email nullable, téléphone nullable ; au moins l'un des deux.
- `ApplicationMunicipality` : application + eventParticipation, unique par couple.
- `ApplicationZone` : applicationMunicipality + eventZone ; zone doit appartenir à la participation de la commune.
- `ApplicationVehicle` : application + immatriculation normalisée ; 0..N ; si hasVehicle vrai, au moins une plaque.
- `ApplicationAttachment` : application + StoredFile ; 0..5, max 10 Mo par fichier, facultatif.

## Instruction (2)
- `ApplicationInstruction` : instruction communale rattachée à ApplicationMunicipality ; état et notes internes **visibles uniquement par commune concernée**.
- `ApplicationDecision` : décision historisée, auteur, date, justification, périmètre communal ; acceptation/refus/demande d'information selon workflow final. Ne jamais écraser les décisions passées.

## Permis (révisé LOT 05.0 ; mise en œuvre LOT 05.1, [lot-05-schema.md](lot-05-schema.md))
- `Permit` : au plus un par demande (`UNIQUE(request_id)`), référence `LP-AAAA-NNNN` (jamais un secret), première décision d'approbation. **Aucun état de validité n'est stocké** : il est calculé (DM-112).
- `VerificationToken` : jeton opaque (≥ 160 bits) du QR, stocké en clair, un seul actif par laissez-passer, retrait pour le remplacement exceptionnel (LOT 05.5).
- `PermitAccessToken` (LOT 05.2) : lien bénéficiaire, empreinte SHA-256 seule.
- `PermitAuthorization` : une par participation communale approuvée (`UNIQUE(permit, participation)`, `UNIQUE(decision)`), fenêtre copiée de la décision, révocation.
- `PermitAuthorizationZone` : zones copiées (libellé copié), retrait logique.
- `PermitVehicle` : plaques copiées de la demande à la première émission, **partagées au niveau du laissez-passer**, retrait logique.
- `PermitAuthorizationRevision` (LOT 05.5) : révisions immuables (prolongation, zones, plaques, révocation, remplacement de jeton).

Validité : fenêtre globale de la décision (DM-109 : H24, H48, H72, CUSTOM, UNTIL_CLOSURE), copiée dans l'autorisation ; droits par zone **calculés** (fenêtre ∩ événement ∩ périodes de zone, état de la commune, de la participation et de la zone). Prolongations, modifications de zones/plaques et révocations ne changent pas le QR. Historique conservé. *(Version précédente : états `DURATION_24H`… et état stocké sur `Permit` : remplacés.)*

## Contrôle
Aucune entité en V1 : le scan public n'exige ni activation ni appareil (DM-115). `ControllerActivation` et `ControllerDevice` sont **retirés du périmètre** du LOT 05 ; leur reprise éventuelle relève du LOT 06 (PWA).

## Transverse (3)
- `AuditLog` : actions sensibles, acteur/contexte, timestamp, données minimales.
- `Notification` : email/SMS, destinataire, statut, tentatives, horodatages.
- `StoredFile` : chemin privé, type MIME validé, taille, empreinte, métadonnées et politique de conservation.

**Total : 27 entités** (5 + 4 + 6 + 2 + 5 + 2 + 3). Le précédent total de 26 était une erreur de décompte.

## Points à figer au moment des lots, sans bloquer le LOT 00
- État précis des instructions et demandes, traitement d'une demande d'information sans modification citoyenne.
- Gestion des modifications administratives des dossiers déposés et journal de ces corrections.
- Représentation des rôles JSON vs table de jonction (JSON acceptable en V1 si validation stricte).
- Sémantique d'une participation `SUSPENDED` et des zones désactivées pour les droits déjà accordés.
- Définition exacte des données publiques de contrôle en cas d'autorisation partiellement expirée.
- Limites de conservation RGPD et procédure de purge.
