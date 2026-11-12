# Civipass — Sécurité et isolation V1

## Principes
Base MySQL partagée ; isolation **applicative obligatoire** par commune et événement. Les UUID ne sont pas des contrôles d'accès. Toute requête administrative filtre par périmètre autorisé et tout cas d'usage d'écriture revalide le droit et l'appartenance réelle des ressources.

## Comptes administratifs
`User` unique ; `MunicipalityMembership` unique par (user, commune) avec rôles cumulables. Permissions explicites et non hiérarchiques. Superadmin CWS global, réservé à Sonata ; **Sonata doit être protégé par firewall et `access_control` avant tout déploiement**. Un utilisateur désactivé ou membership inactif ne peut plus agir.

## Matrice indicative
| Action | Superadmin | Admin commune | Gestionnaire événement | Instructeur | Contrôleur sans compte |
|---|---|---|---|---|---|
| Paramétrer une commune | Oui | Oui, sa commune | Non | Non | Non |
| Créer un événement | Non (aucun droit communal implicite) | Oui (rôle administrateur) | Oui, si commune active avec droit d'utilisation valide | Non | Non |
| Inviter une commune | Non | Oui | Oui, si organisatrice | Non | Non |
| Instruire et autoriser | Supervision exceptionnelle et auditée | Selon permission explicite | Non par défaut | Oui, sa commune | Non |
| Scanner un QR public (nom, plaques, zones, validité) | Oui | Oui | Oui | Oui | Oui (aucune habilitation requise) |
| Corriger l'identité (participation ACTIVE) | Non | Oui, sa commune | Non | Oui, sa commune | Non |
| Prolonger, modifier zones ou plaques, révoquer (LOT 05.5, participation ACTIVE) | Non | Selon DM-116 | Non | Oui, sa commune | Non |
| Remplacer le QR (LOT 05.5) | Non | Oui, autorisation concernée | Non | Non | Non |
| Activer appareils PWA / synchroniser hors ligne | Hors V1 (LOT 06, à redéfinir) | — | — | — | — |

> **Révision LOT 02 (N5)** : les mentions « Supervision » de CWS sur la création d'événement, l'invitation et l'activation d'appareils sont **retirées** (décision DM-005 : aucun droit communal implicite). Matrice d'origine : commit `0a8b5fb`. Les rôles « Admin commune » et « Gestionnaire événement » suivent DM-057 ; l'accès CWS passe par trois mécanismes distincts (administration technique, impersonation auditée DM-061, clôture forcée DM-065). Voir [`revisions/CONTRADICTIONS.md`](revisions/CONTRADICTIONS.md).

Cette matrice est un point de départ technique ; toute délégation fine devra être explicitée dans les tickets.

## Autorisation serveur
Voters pour les décisions contextuelles, services d'accès pour la politique métier, repositories scindés par contexte communal. Interdire les lectures par identifiant seul lorsque la ressource est sensible. Vérifier les chaînes : Application → ApplicationMunicipality → EventParticipation → Municipality ; PermitAuthorization → EventParticipation ; EventZone → EventParticipation. Tests négatifs inter-communes obligatoires.

## Scan public (révisé LOT 05.0, DM-115)
QR = URL HTTPS avec jeton opaque fort (≥ 160 bits), sans donnée en clair. **Le scan est entièrement public, sans compte ni habilitation** : la page affiche **uniquement** nom et prénom du bénéficiaire, plaque(s) (si des véhicules sont associés), zones autorisées, dates et périodes de validité, **état par zone calculé en temps réel**, référence `LP-` et événement. **Jamais** : adresse postale, e-mail, téléphone, justificatifs, autres informations de la demande. Une zone inactive ou une autorisation révoquée est identifiable ; un laissez-passer n'est pas présenté comme entièrement valide si seules certaines zones le sont. Mesures obligatoires : `noindex, noarchive`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, limitation de débit, réponse uniforme pour un jeton inconnu, aucun accès par référence `DEM-` ou `LP-`, aucun jeton dans les journaux applicatifs, erreurs ni audits, **masquage des jetons dans les journaux d'accès HTTP** ([lot-05-schema.md](lot-05-schema.md) §10), pas de journal systématique des scans (compteurs agrégés possibles).
> **Risque résiduel RGPD assumé (DM-115)** : nom, prénom et plaques sont exposés volontairement à toute personne qui dispose du QR (photo, partage, transmission) ; le QR n'est pas une preuve d'identité, le contrôleur rapproche les informations affichées de la personne et du véhicule. La base légale, l'information des personnes (texte de confidentialité) et la conservation doivent être validées avant la production (DM-117).
> *Version précédente (jusqu'au LOT 04)* : page sans nom, plaques seules ; remplacée par arbitrage du commanditaire le 9 novembre 2026.

## Lien bénéficiaire (LOT 05.2, DM-114)
Lien distinct du QR : secret de 256 bits, **empreinte SHA-256 seule** stockée, **fermé à la clôture de l'événement** (LOT 05.6.2, DM-120 ; plus aucun délai de grâce) : lecture fraîche de l'état de l'événement par `BeneficiaryPassReader`, réponse identique à un lien inconnu, ligne de jeton conservée (preuve, archive) ; tant que l'événement n'est pas clos, expiration 30 jours après la fin planifiée (calculée à l'usage), plusieurs liens actifs, révocation **individuelle** auditée, jamais de compte requis ; un secret n'est affiché qu'une fois à l'agent. Protections (DT-051) : réponse unique pour tout lien invalide, plafonds de requêtes et d'échecs par IP, `no-store`, `no-referrer`, `noindex`, politique de contenu stricte sans script ni ressource tierce, aucun cookie, journal applicatif purgé des secrets. Agents : INSTRUCTOR et MUNICIPALITY_ADMIN d'une commune titulaire d'une autorisation ; EVENT_MANAGER pour ce qu'il a délivré directement (DM-118).

## Clôture d'un événement : barrière des accès opérationnels (LOT 05.6.2, DM-120)

Un événement clos n'ouvre **plus aucun accès opérationnel** (dossiers, justificatifs, autorisations, plaques, recherches d'identité, liens bénéficiaires, PDF), **quel que soit le rôle** :
- **Barrière HTTP** (`ClosedEventBarrier`) : toute route `/communes/{municipalityId}/…` qui porte `{requestId}` répond **410 neutre** (sans donnée, `no-store`) sur un événement clos, pour toute méthode (GET, HEAD, POST forgé). Ordre : **habilitation d'abord** (appartenance effective à la commune de l'URL), puis dossier cherché par sa **portion communale**, puis état de l'événement : un utilisateur non habilité, une commune étrangère ou un dossier inexistant reçoivent le **même 404 uniforme** qu'avant, jamais « clos ». Reste connu : un membre de la commune sans le rôle requis par la route apprend qu'un dossier **de sa commune** est clos (identifiant UUID v7 non devinable).
- **Classement exhaustif des routes** (`ClosedEventRouteClassifier`) : le test de matrice échoue pour toute route non classée (gardée, exemptée justifiée, réservée, publique justifiée). Préfixes **réservés** `/communes/{m}/archives/…` (05.6.3) et `/communes/{m}/retention/…` (05.6.4) : jamais derrière la barrière.
- **Gardes métier** (`EventOperationalGuard`) dans les lecteurs et services (détail, justificatif, liste, identité, droits, liens, rejeu d'idempotence, lien bénéficiaire), y compris hors HTTP ; **jamais** appelée par la clôture, les purges, les commandes console ni les futurs modules d'archive et de purge (test d'architecture).
- **Rejeu d'idempotence (N1)** : après la clôture, aucun rejeu ne renvoie la référence, la décision ou une information (dépôt public avec ou sans pièces jointes, délivrance directe).
- Les liens d'invitation non consommés sont révoqués ; les notifications en attente sont supprimées (voir `exploitation-notifications.md`, §19).

## Bilan statistique de clôture : habilitations (LOT 05.6.2, DM-120)

Le bilan de clôture (compteurs agrégés, seuillés) est **distinct de l'accès aux archives** : il ne donne **aucun accès aux dossiers**, ne crée **aucune habilitation d'archive** (la désignation explicite de l'organisateur relève du 05.6.3) et n'expose aucun identifiant de dossier. Compteurs exacts stockés (`event_event.closure_statistics`) : **jamais affichés** ; publication **calculée à la lecture** avec le seuil `CLOSURE_STATISTICS_MIN_COUNT` (5, provisoire).

| Lecteur (commune de l'écran = membership effectif, lecture) | Bilan global | Ventilation par commune | Sa propre répartition |
|---|---|---|---|
| `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER` de la commune **organisatrice** | oui (total ; répartition par statut seulement pour un événement à une commune) | `MUNICIPALITY_ADMIN` de plein droit ; `EVENT_MANAGER` **avec habilitation de coordination explicite** ; dans les deux cas si **tous** les effectifs communaux sont publiables | oui |
| `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER` d'une commune **participante** (réellement activée) | non | non | oui, **sa seule ligne** |
| `INSTRUCTOR` (toute commune) | non | non | non |
| Commune sans lien avec l'événement, identifiants forgés, CWS sans appartenance | rien (même réponse qu'un événement inexistant) | rien | rien |

Depuis le 05.6.3 (L5-36 résolue), la ventilation par commune est lue **de plein droit par le `MUNICIPALITY_ADMIN` de l'organisatrice** et, pour un `EVENT_MANAGER`, **seulement avec une habilitation de coordination statistique explicite** (voir « Archives sécurisées »).

## Archives sécurisées (LOT 05.6.3, DM-120)

Espace **distinct** des écrans opérationnels (`/communes/{m}/archives/…`, préfixe réservé, hors barrière de clôture), **lecture seule**, **une seule porte** : `ArchiveAccessPolicy`, vérifiée **côté serveur à chaque requête** (rien n'est mémorisé) : utilisateur actif ; appartenance effective à la commune de l'URL (lecture : une commune suspendue garde la lecture) ; rôle `MUNICIPALITY_ADMIN` **ou** rôle `EVENT_MANAGER` **et désignation active** pour (événement, commune) ; dossier cherché par sa **portion communale** ; événement **clos** (lecture fraîche). **Aucune fermeture automatique à échéance** : l'accès dépend des seules habilitations et de l'existence des données conservées ; `ARCHIVE_RETENTION_MONTHS` (12 mois, hypothèse) n'est qu'un repère d'éligibilité à la purge. `INSTRUCTOR` seul, CWS sans appartenance, autre commune, identifiant forgé : **404 uniforme**, jamais « clos » ni « archive » (le droit est contrôlé **avant** le jeton CSRF).

| Acte | Qui | Journal |
|---|---|---|
| Lister les événements clos et leurs dossiers (référence, catégorie, statut : **aucune donnée personnelle**) | administrateur de la commune ; gestionnaire **désigné** | non |
| **Ouvrir un dossier** (motif obligatoire : code ; `AUTRE` exige un commentaire ≤ 200) | idem | `archive_consultation` (consultant, date UTC, dossier, action, motif), **écrit avant l'envoi** ; échec = accès refusé |
| Télécharger une pièce (appartenant à la demande du dossier) | idem, pendant une consultation valide (30 min, `ARCHIVE_CONSULTATION_MINUTES`) | une ligne par téléchargement |
| Désigner / révoquer un `EVENT_MANAGER` pour (événement, commune) | `MUNICIPALITY_ADMIN` de la commune | audit `ARCHIVE_MANAGER_DESIGNATED` / `REVOKED` (identifiants et cause) |
| Geler / confirmer le maintien / lever le gel d'un dossier communal | `MUNICIPALITY_ADMIN` de la commune du dossier | audit `LEGAL_HOLD_*` (identifiants et codes, jamais le commentaire) |
| Lire le journal des consultations | `MUNICIPALITY_ADMIN`, **sa** commune | — |
| Coordination statistique : accorder / révoquer la lecture de la ventilation par commune (L5-36, arbitrage A1) | `MUNICIPALITY_ADMIN` de la commune **organisatrice** → `EVENT_MANAGER` de cette commune | audit `STATISTICS_COORDINATION_*` |

**Désignation** (option B) : révoquée **explicitement** (cause tracée) quand l'utilisateur perd le rôle `EVENT_MANAGER`, son appartenance ou son compte (`AccessLossObserver`, dans la transaction de `MembershipManager` et `UserManager`), **définitive** (jamais rétablie par le retour du rôle ou du compte : nouvel acte de l'administrateur) ; **recontrôlée à chaque accès** même si un crochet était contourné. **Gel** : unité = dossier communal, un seul gel actif (il protège contre la purge, **sans** ouvrir ni prolonger aucun droit de consultation), épisodes conservés, revue explicite (6 mois, hypothèse), **jamais de prolongation ni de levée automatique** ; un gel en retard de revue **continue de protéger** et est signalé (`app:archive:holds-report`, sans donnée personnelle) ; un gel **n'ouvre rien à une autre commune**. **Coordination statistique et désignation d'archive sont deux permissions indépendantes** : aucune n'ouvre l'autre ; la coordination ne donne accès à aucun dossier. CWS : aucun droit communal implicite. Durées (12 mois, 6 mois, 30 minutes) et listes de motifs : **hypothèses à valider avec le DPO et les archives** avant production.

## Purge de conservation et suppression d'événement (LOT 05.6.4, DM-120)

- **Éligibilité démontrée, relue sous verrou** : événement clos, échéance de conservation atteinte (paramètre provisoire), aucun gel actif, avis préalable de plus de `RETENTION_NOTICE_DAYS` jours. À défaut, rien n'est supprimé. L'accès aux archives n'est jamais fermé par la date.
- **Deux niveaux** : dossier communal (propre à chaque commune, indépendant), puis données partagées quand plus aucun dossier ne subsiste. Une commune ne provoque jamais la suppression prématurée de données dont une autre a besoin.
- **Transactionnel, idempotent, reprenable** : une transaction par dossier, fichiers supprimés avant la ligne, reprise des demandes orphelines. `RETENTION_PURGE_ENABLED=0` par défaut ; simulation par défaut.
- **Registre minimal** (`retention_purge_record` + fichier hors base) sans donnée personnelle ; écriture du fichier non atomique, divergences détectées par `app:retention:register-check`. Restauration et rejeu : LOT 05.6.7.
- **Suppression de l'événement** : seulement par le `MUNICIPALITY_ADMIN` de l'organisatrice, après purge de tous les dossiers, référence saisie ; 404 uniforme sinon. Comptes, communes et référentiels intacts.
- **À valider avec le DPO** : suppression des journaux de consultation (identité des consultants) avec le dossier ; durées provisoires.

## Comptes candidats et dormance des événements (LOT 05.6.5)

- **Rapport de comptes** : lecture seule, super-administrateur CWS actif uniquement (aucune exposition aux administrateurs communaux) ; adresses masquées ; la liste n'est pas persistée, l'exécution est journalisée (opérateur, effectifs). `DELETABLE` et `ANONYMISE_ONLY` sont des indicateurs techniques : l'absence de référence ne vaut jamais autorisation juridique de supprimer ou d'anonymiser.
- **Dormance** : seuls l'administrateur et l'organisateur de la commune organisatrice reçoivent l'alerte, voient le bandeau et reportent le rappel (droit relu côté serveur avant le jeton CSRF, 404 uniforme). L'alerte ne contient aucune donnée personnelle, ne clôture jamais l'événement et ne modifie aucun droit.

## Droits des personnes (LOT 05.6.6)

- **Qui** : `MUNICIPALITY_ADMIN` de la commune concernée, seulement ; 404 uniforme avant le jeton CSRF ; CWS sans accès ; aucune donnée du demandeur enregistrée (dossiers désignés par leur référence).
- **Export** : après attestation d'identité, à la volée, jamais conservé, 3 téléchargements et 30 jours au plus, habilitations recontrôlées et téléchargement journalisé à chaque fois ; jamais le contenu propre d'une autre commune.
- **Rectification d'archive** : mention ajoutée, jamais de remplacement ; supprimée avec le dossier.
- **Effacement anticipé** : exécution **désactivée** (`RIGHTS_ERASURE_EXECUTION_ENABLED=0`) tant que la politique juridique n'est pas validée ; ne contourne jamais un gel, la clôture, une autre demande ouverte ni le partage des données, **même lorsque les deux interrupteurs sont activés**. Valeurs par défaut `0` dans `config/services.yaml`, jamais activées par un fichier du dépôt : seule une variable d'environnement explicite du serveur le peut (test `ErasureSwitchesDefaultTest`).

## Exploitation, restauration et rejeu (LOT 05.6.7)

- **Registre v2 chaîné et ancré** : une ligne supprimée, modifiée ou réordonnée rompt la chaîne ; les points d'ancrage (fichier séparé, journal, courriel d'exploitation) détectent une réécriture complète ou une troncature. **Non qualifié d'inviolable** tant que l'indépendance de l'ancrage n'est pas confirmée en exploitation.
- **Rejeu après restauration** : jamais automatique ni planifié ; opérateur CWS, référence d'approbation écrite tracée, empreinte du rapport présenté, preuve externe saine, interrupteur de purge ; recontrôle de la clôture, des gels et des demandes de droits sous verrou ; aucune suppression d'événement ; aucun droit réactivé.
- **Remise en service** : `var/ops/MAINTENANCE` bloque toute tâche planifiée ; levée uniquement par `app:ops:release` (aucune anomalie bloquante, pertes reconnues par écrit).
- **Planification** : unités systemd livrées et non installées ; tâches destructives ou émettrices dans `ops/systemd/optional/`, non activées.

## Secrets des notifications (LOT 05.3.2, DT-049)

Le secret du lien automatique n'est conservé que chiffré (XChaCha20-Poly1305, données associées liant message, type et laissez-passer), dans une table séparée, 48 heures au plus, supprimé dès le traitement définitif. Les clés viennent de l'environnement (`NOTIFICATION_KEYRING`), jamais du dépôt ; contrôle : `app:notifications:check-crypto`. Clé absente ou invalide : fail-closed (aucun lien, aucun clair, message en attente, approbation jamais bloquée, remise manuelle possible, échec définitif après 7 jours). Aucun secret ni valeur de clé dans les journaux, audits, exceptions ou sorties de commandes (testé). Détails : `docs/lot-05-schema.md` §19.

## PWA (LOT 06, hors V1 du contrôle)
Principe retenu pour le hors ligne (non développé) : en ligne, contrôle public sans compte ni habilitation ; hors ligne, PWA après synchronisation préalable des données de l'événement (accès temporaire, sans compte permanent obligatoire), résultat calculé localement avec affichage de la dernière synchronisation ; le même QR sert aux deux modes (`lot-05-schema.md` § 15.2).
Le contrôle V1 ne requiert ni activation d'appareil, ni compte, ni session. Une éventuelle PWA hors ligne reste à redéfinir au LOT 06 : si elle est retenue, elle devra respecter les principes suivants (inchangés) — secret propre à l'appareil stocké sous forme d'empreinte, révocation individuelle, expiration, avertissement après 12 h et blocage après 24 h sans synchronisation, pas de géolocalisation. `ControllerActivation` et `ControllerDevice` ne sont pas développés au LOT 05.

## Fichiers et données
Stockage privé hors racine web, validation MIME/taille/extension, contrôle d'accès à chaque téléchargement, protection antivirus à envisager selon risque. Minimisation, chiffrement en transit, gestion des secrets hors Git, sauvegardes sécurisées. Fixer une durée de conservation et une procédure de purge documentées avant production. Audit des décisions, prolongations, modifications de véhicules/zones, invitations et révocations ; aucune géolocalisation.

## Tests bloquants
1. Agent commune A ne lit ni ne modifie notes/justificatifs locaux de B.
2. Agent A ne décide pas pour B, même en forgeant des IDs.
3. Organisatrice ne modifie pas les autorisations des invitées.
4. Commune sans droit d'utilisation valide (abonnement, TRIAL ou accès commercial CWS) ne crée pas d'événement.
5. Sortie d'une commune invalide seulement ses droits.
6. Clôture événement invalide tous les droits.
7. QR ne change pas après prolongation, changement de zones/véhicules.
8. *(LOT 06, si une PWA est retenue)* PWA révoquée ne synchronise plus ; fraîcheur hors ligne appliquée.
9. Scan public : n'expose que nom, prénom, plaques, zones, validité, référence `LP-` et événement ; jamais adresse, e-mail, téléphone, justificatifs ; en-têtes de non-indexation, de non-cache et de non-référent ; aucun jeton dans les journaux.
