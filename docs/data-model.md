# Civipass — Modèle de données V1 (base de conception)

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
- `Application` : UUID, référence, événement, `isProfessional` booléen, `hasVehicle` booléen, motif, statut, dates. Plusieurs demandes par personne et événement possibles. Dossier non modifiable par citoyen après soumission.
- `ApplicantContact` : lien 1:1 vers Application, nom, prénom, adresse, email nullable, téléphone nullable ; au moins l'un des deux.
- `ApplicationMunicipality` : application + eventParticipation, unique par couple.
- `ApplicationZone` : applicationMunicipality + eventZone ; zone doit appartenir à la participation de la commune.
- `ApplicationVehicle` : application + immatriculation normalisée ; 0..N ; si hasVehicle vrai, au moins une plaque.
- `ApplicationAttachment` : application + StoredFile ; 0..5, max 10 Mo par fichier, facultatif.

## Instruction (2)
- `ApplicationInstruction` : instruction communale rattachée à ApplicationMunicipality ; état et notes internes **visibles uniquement par commune concernée**.
- `ApplicationDecision` : décision historisée, auteur, date, justification, périmètre communal ; acceptation/refus/demande d'information selon workflow final. Ne jamais écraser les décisions passées.

## Permis (5)
- `Permit` : au plus un par Application, identifiant QR opaque stable non prédictible, état.
- `PermitAuthorization` : lien Permit + EventParticipation, validité, statut ; une autorisation par commune et par demande (unicité à confirmer pour réexamens). Chaque commune est indépendante.
- `PermitAuthorizationZone` : lien autorisation + EventZone, unicité, vérification même commune/événement.
- `PermitAuthorizationRevision` : révisions immuables de durée, zones, véhicules, révocation ; auteur, date, ancien/nouvel état ou snapshot.
- `AuthorizationVehicle` : véhicule effectivement autorisé, plaque snapshot ; indépendant des véhicules initialement déclarés.

Validité : `DURATION_24H`, `DURATION_48H`, `DURATION_72H`, `CUSTOM_DATE`, `EVENT_END`. Début à délivrance pour durées relatives ; date/heure de fin pour CUSTOM_DATE ; EVENT_END lié à clôture. Prolongations, modifications de zones/véhicules et révocations ne changent pas le QR. Une autorisation sans zone active n'accorde aucun accès. Sortie d'une commune ou clôture de l'événement invalide les droits correspondants. Historique conservé.

## Contrôle (2)
- `ControllerActivation` : accès d'activation temporaire pour événement, jeton à usage limité, expiration/révocation, émetteur.
- `ControllerDevice` : événement, activation, identifiant, empreinte de secret d'appareil, statut, dernière synchronisation, expiration/révocation. Aucun User requis, aucun quota d'appareils.

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
