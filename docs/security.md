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
Lien distinct du QR : secret de 256 bits, **empreinte SHA-256 seule** stockée, expiration 30 jours après la fin effective de l'événement (calculée à l'usage), plusieurs liens actifs, révocation **individuelle** auditée, jamais de compte requis ; un secret n'est affiché qu'une fois à l'agent. Protections (DT-051) : réponse unique pour tout lien invalide, plafonds de requêtes et d'échecs par IP, `no-store`, `no-referrer`, `noindex`, politique de contenu stricte sans script ni ressource tierce, aucun cookie, journal applicatif purgé des secrets. Agents : INSTRUCTOR et MUNICIPALITY_ADMIN d'une commune titulaire d'une autorisation ; EVENT_MANAGER pour ce qu'il a délivré directement (DM-118).

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
