# Invitations intercommunales : référentiel national, e-mail et délégation

Lot complémentaire UI-02 (4 novembre 2026). Il rend le parcours intercommunal utilisable en production : rechercher n'importe quelle commune de France, l'inviter par e-mail, et permettre à une commune sans compte de rejoindre Civipass. Les règles métier ne changent pas : l'enregistrement de l'invitation reste `EventParticipationService::invite` (DM-084, DM-086, DM-089), l'acceptation reste `EventParticipationService::accept` après authentification.

## 1. Référentiel national des communes

- **Source officielle** : API Géo (`https://geo.api.gouv.fr/communes`), qui diffuse le code officiel géographique (COG) de l'INSEE. Un seul appel renvoie les ≈ 35 000 communes (métropole, DROM, COM, Corse). L'API cherche par nom (flou) et par code exact, **pas par préfixe de code INSEE** : la recherche est donc faite en base locale, ce qui évite aussi toute dépendance à un service externe pendant un événement.
- **Table** `municipality_national_commune`, séparée de `municipality` (communes inscrites) : une ligne du référentiel n'ouvre aucun droit, ne crée aucune commune, ne porte aucune donnée commerciale. Le lien est le code INSEE, sans clé étrangère. Jamais supprimée : une commune qui disparaît de la source est **désactivée** (plus proposée à l'invitation), réactivée si elle revient.
- **Synchronisation** : `php bin/console app:communes:sync` (options `--dry-run`, `--file=…`, `--allow-partial`). Import complet, idempotent (clé : code INSEE), en une transaction, audité (`NATIONAL_COMMUNES_SYNCHRONIZED`, origine système). Garde-fou : une source qui renvoie moins de `NATIONAL_COMMUNES_MIN_COUNT` communes (30 000 par défaut) est refusée en bloc. N'écrit **jamais** dans `municipality`, ni dans les droits commerciaux, ni dans les participations. À planifier (cron) environ une fois par mois : le COG change peu (fusions au 1er janvier). Variable `GEO_API_URL` (défaut : l'API officielle). Appel natif PHP : aucune dépendance ajoutée.
- **Recherche** (`InvitationCandidateReader`) : nom (insensible à la casse, aux accents, aux apostrophes et aux tirets), code INSEE exact ou préfixe, dès 2 caractères (sur le terme normalisé), 20 résultats, classés exact, préfixe, contient. Les communes déjà inscrites mais absentes du référentiel sont aussi trouvées (jeux de test, communes historiques) ; aucun doublon. Situation affichée : disponible, déjà invitée, participe déjà, participation clôturée. Une commune inscrite non `ACTIVE` n'est pas proposée (« introuvable ou indisponible », DM-086). L'éligibilité n'est pas modifiée.
- **Interface** : la page d'invitation cherche au fil de la frappe (contrôleur Stimulus `commune-search`, fragment HTML renvoyé par le serveur) ; sans JavaScript, le formulaire GET de recherche fonctionne.

## 2. Invitation par e-mail

1. L'organisatrice sélectionne la commune et saisit l'**adresse e-mail de contact** (libre : pas nécessairement celle du futur administrateur, aucun domaine imposé).
2. `InvitationDispatcher` : droit d'inviter vérifié **avant toute écriture** ; commune du référentiel inscrite si elle ne l'est pas (`MunicipalityRegistrar` : nom et code INSEE issus de la source, jamais saisis ; sans droit commercial, sans membre, sans abonnement ; audit `MUNICIPALITY_REGISTERED_FROM_REFERENTIAL`) ; invitation enregistrée par le moteur ; lien sécurisé émis ; e-mail envoyé.
3. **Échec d'envoi** : l'invitation est enregistrée avant l'envoi, jamais perdue ni dupliquée. L'échec est noté (statut `FAILED`, audit, journal sans jeton ni adresse), la page affiche un avertissement et « Renvoyer l'invitation » (page des communes, section « Invitations en attente ») émet un nouveau lien qui remplace l'ancien ; l'adresse peut être corrigée.
4. **E-mail** HTML + texte : organisatrice, événement, message éventuel, explication de Civipass, lien sécurisé, durée de validité. Le lien est construit à partir de `DEFAULT_URI`, jamais de l'en-tête `Host`.

**Environnement local** : Symfony Mailer + MailCatcher (conteneur `saas_civipass_mailcatcher`, SMTP `smtp://saas_civipass_mailcatcher:1025` dans `.env.local`, aucun envoi réel). Les e-mails se consultent à **http://mail-civipass.cws.gregory.localhost**. Les tests utilisent le transport de capture de Symfony (`null://null`).

## 3. Liens sécurisés et délégation de l'administrateur communal

Deux types de lien (table `onboarding_invitation_link`) :

| Type | Destinataire | Durée | Usage |
|---|---|---|---|
| `CONTACT` | adresse de contact saisie par l'organisatrice | 14 jours | page d'accueil, délégation (réutilisable jusqu'à expiration) |
| `ADMINISTRATOR` | adresse de l'administrateur désigné par le contact | 3 jours | inscription : **usage unique** |

**Parcours** : (1) le contact ouvre le lien ; (2) si la commune a déjà un administrateur Civipass, il se connecte et le **parcours d'acceptation existant** s'applique ; (3) sinon il indique l'adresse e-mail de la personne qui sera administrateur communal, qui reçoit un lien personnel ; (4) cette personne crée son compte (l'adresse est celle du lien) ou se connecte si un compte existe, **atteste** être habilitée à représenter la commune, et devient administrateur communal ; (5) l'acceptation de l'invitation reste une action explicite et distincte, avec ses propres contrôles. Participation gratuite, sans abonnement (DM-086).

**Ce que la vérification prouve, et ce qu'elle ne prouve pas** : la possession du lien et de la boîte e-mail est prouvée (le lien n'a été envoyé qu'à cette adresse). **L'habilitation officielle à représenter la mairie n'est pas vérifiée** : aucune règle validée ne le permet ; la personne l'atteste (case à cocher) et l'audit conserve `attestedRepresentation = true`, `emailControlProven = true`, `officialHabilitationVerified = false`. Ce point est dit clairement dans l'e-mail et sur les pages.

**Sécurité**
- Jeton : 256 bits aléatoires, base64url ; seule l'empreinte SHA-256 est stockée ; jamais en clair en base, dans l'audit ni les journaux.
- Le jeton quitte l'URL dès son contrôle (session) ; pages `no-store`, `Referrer-Policy: no-referrer`, `noindex` ; aucune exception pour un lien invalide (réponse rendue directement : rien dans les journaux).
- États distingués sans rien révéler de l'événement : introuvable (404), expiré, utilisé, remplacé, invitation plus en attente (410). La page d'accueil ne montre que le contenu de l'e-mail : jamais zones, autres communes ni consignes.
- Un nouvel envoi remplace le précédent (un seul lien ouvert par participation et par type). Consommation **atomique** (mise à jour SQL conditionnelle) dans la même transaction que l'appartenance.
- Limitation de débit : 30 ouvertures de lien / 15 min / IP ; 5 désignations d'administrateur / heure / lien ; 10 inscriptions / 15 min / IP.
- **Aucun droit sur une commune déjà administrée** : `MembershipManager::enrollFirstAdministrator` relit sous verrou de la commune qu'aucun administrateur effectif n'existe (deux inscriptions simultanées : une seule aboutit, testé sur deux processus) ; rôle administrateur **uniquement** (jamais gestionnaire ni instructeur) ; commune `ACTIVE` et compte actif exigés ; un compte déjà rattaché n'est pas modifié ; un compte existant doit se connecter avec l'adresse invitée (jamais rattaché sur la seule foi du lien).
- Aucun abonnement ni droit commercial créé ; une commune sans droit ne peut pas créer ses propres événements.
- Audit : `INVITATION_LINK_ISSUED`, `INVITATION_EMAIL_SENT|FAILED`, `INVITATION_ADMINISTRATOR_DELEGATED`, `INVITATION_ADMINISTRATOR_ENROLLED`, `MEMBERSHIP_CREATED` (origine `INVITATION_LINK`). Adresses masquées (`m***@domaine`), jamais de jeton.

## 4. Points à valider (propositions, non décisions)

1. **Inscription d'une commune par l'organisatrice** : la création « manuelle » d'une commune reste réservée au superadmin et à la console. Ici, seule une commune **officielle du référentiel** peut être inscrite à l'occasion d'une invitation (sans droit ni membre). À confirmer comme règle.
2. **Durées des liens** (14 jours, 3 jours) : paramètres techniques, non décisions métier ; l'invitation elle-même n'expire pas (DM-086), un lien expiré se réémet.
3. **Habilitation officielle** : non vérifiable aujourd'hui (voir §3). Un risque résiduel subsiste : toute personne contrôlant la boîte désignée par le contact peut devenir administrateur d'une commune encore sans administrateur. À arbitrer si une procédure de vérification est définie (par exemple rattachement du contact à une adresse institutionnelle ou validation CWS).
4. **Migrations** : deux tables (`municipality_national_commune`, `onboarding_invitation_link`), revues avant application sur la base de développement.
