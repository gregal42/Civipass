# Civipass — Roadmap de développement V1

> **Révision LOT 02 (TECH-02.1) — traçabilité** : les passages sur la bibliothèque de zones et les instantanés (`Zone`, `EventZone` avec source/instantané, « snapshots ») sont **remplacés** par [`lot-02-schema.md`](lot-02-schema.md) (décisions DM-008, DM-051, DM-052, DM-029). Le texte d'origine ci-dessous est conservé tel quel pour mémoire ; il n'est plus la référence pour les zones, événements et participations.

## Principes de livraison
Petits lots, schéma revu avant migrations, tests automatisés et `make quality` à chaque livraison. Claude Code ne doit pas construire toute l'application d'un seul coup. Les maquettes Claude UI sont intégrées progressivement en Twig/Stimulus/Turbo, sans changer la direction graphique retenue.

## LOT 00 — Socle documentaire (présent livrable)
- Installer ces fichiers dans le dépôt à la racine et sous `docs/`.
- Vérifier état Git, routes, versions, sécurité Sonata, commandes Makefile, qualité ; produire un état des lieux **sans modifier le schéma**.
- Critère de sortie : documentation partagée, risques identifiés, plan LOT 01 prêt.

## LOT 01 — Identités et communes
- Revue des attributs/cardinalités/index ; validation des migrations.
- User, Municipality, Membership à rôles cumulables, Invitation, Subscription.
- Authentification, contexte communal, Voters, protections Sonata.
- Tests de droits multi-communes, création d'événement conditionnée à un droit d'utilisation valide (abonnement, TRIAL ou accès commercial CWS ; service testable même si Event vient ensuite).

## LOT 02 — Événements et zones
- EventType, Event, EventParticipation, EventZone, EventZoneValidity et compteur de référence (sans bibliothèque de zones ni snapshots).
- Commune organisatrice disposant d'un droit d'utilisation valide, invitation de communes sans droit, zones et validités pendant l'événement actif.
- Tests d'isolation, transitions, invalidation par sortie/clôture.
- **UI-02 — incrément 1 (3 novembre 2026)** : espace municipal `/communes/{municipalityId}/evenements[/{eventId}]` (liste paginée et filtrée, fiche, actions décidées par la politique d'accès, non branchées donc indisponibles), design system UI-02 importé. Incréments suivants : création, coordination, zones, créneaux, cycle de vie ; opérations groupées **non exposées** tant que le volume cumulé n'est pas plafonné (plan TECH-02.1 §23).
- **UI-02 — incrément 2 (3 novembre 2026)** : création et modification d'un événement par l'interface (`/communes/{municipalityId}/evenements/nouveau` et `…/{eventId}/modifier`), via `EventCreationService::create` et `EventLifecycleService::update` ; authentification complète exigée, CSRF, 422 / 409 / 303, saisies conservées, fuseau déduit de la commune ou choisi, horaires locaux convertis par `LocalTimeResolver` (heure inexistante refusée, heure répétée à choisir). Les consignes opérationnelles sont réservées à la commune organisatrice. Incréments suivants : coordination, zones, créneaux, cycle de vie.
- **UI-02 — incrément 3 (3 novembre 2026)** : coordination intercommunale par l'interface — recherche d'une commune à inviter (nom ou code INSEE, communes actives seulement, aucune donnée commerciale), invitation (`…/{eventId}/inviter`), page des communes selon le niveau de visibilité (DM-084, DM-090, DM-091 ; `…/{eventId}/communes`), acceptation d'une invitation (administrateur seul, commune non abonnée incluse, DM-086), suspension, réactivation et clôture de SA participation (`…/{eventId}/participation/{action}`) ; l'identifiant de participation vient toujours du serveur. Hors périmètre : renvoi d'invitation (aucun envoi réel n'existe), zones, créneaux, cycle de vie.
- **UI-02 — incrément 4 (3 novembre 2026)** : gestion des zones par l'interface — consultation (`…/{eventId}/zones`, zones de la commune avec leurs actions, zones des autres communes visibles en lecture seule selon DM-084/090, aucune zone pour une commune simplement invitée), fiche, création (`…/zones/nouvelle`), modification (libellé et description ; type immuable, DM-080), confirmation, suspension, réactivation et suppression logique (`…/zones/{zoneId}/{action}`), via `EventZoneService` et `EventAccessPolicy` (écritures réservées à l'administrateur communal). « Non confirmée » est un libellé calculé depuis `confirmedAt` (adaptation A10 du design system, aucun nouveau statut). L'identifiant de participation et la zone ciblée viennent toujours du serveur. Hors périmètre : créneaux de validité (incrément 5, les zones restent « Validité à configurer »), opérations groupées, cycle de vie, demandes et laissez-passer.
- **UI-02 — incrément 5 (4 novembre 2026)** : validité des zones par l'interface — consultation des créneaux en heures locales du fuseau de l'événement (`…/zones/{zoneId}/validite`), choix du mode « Pendant toute la durée de l'événement » ou « Périodes spécifiques » (`…/validite/configuration`), ajout (`…/validite/creneaux`), modification et suppression d'un créneau sur pages dédiées (`…/validite/creneaux/{slotId}/modifier|supprimer`), via `EventZoneService::configureValidity` (le moteur remplace la configuration entière, DM-075 : chaque opération part de la configuration relue côté serveur ; l'empreinte de la configuration affichée est vérifiée par le moteur lui-même SOUS les verrous de la zone, dans la transaction de l'écriture : configuration modifiée entre-temps → 409, rien d'écrit, aucune fusion ni nouvelle tentative ; évolution ciblée de TECH-02.1 : `ZoneValidityCommand::expectedRevision`, `OperationConflict(StaleState)`). Types de créneau du moteur : plage continue, accès ponctuel, récurrence. Heure inexistante refusée avec la date en cause ; heure répétée : choix explicite de l'occurrence (jamais automatique, DM-055) ; plafond de 500 créneaux du moteur (DM-096). États affichés, sans nouveau statut : « Validité à configurer », « Pendant toute la durée de l'événement », « Périodes spécifiques ». Les chevauchements ne sont ni contrôlés ni signalés (DM-018, qui prime sur l'avertissement de la maquette). Adaptations A11 à A13 du design system. Hors périmètre : cycle de vie (incrément 6), opérations groupées (incrément 7), demandes et laissez-passer.
- **UI-02 — incrément 6 (4 novembre 2026)** : cycle de vie de l'événement par l'interface — activation, suspension (motif obligatoire), réactivation et clôture globale (`POST /communes/{municipalityId}/evenements/{eventId}/cycle/{activer|suspendre|reactiver|cloturer}`), via `EventLifecycleService` et `EventAccessPolicy` (commune organisatrice, administrateur ou gestionnaire ; l'instructeur et les communes participantes ou invitées n'ont aucune action). Prérequis d'activation relu par le moteur sous verrou (DM-059) : la fiche n'offre l'activation que lorsque la checklist est verte, et le moteur refuse (422) si l'état a changé entre-temps. La clôture est globale et irréversible (DM-092) : le panneau de confirmation annonce avant le bouton qu'elle clôture aussi toutes les participations ouvertes. Le motif de suspension n'est pas affiché. Les boutons « Disponible prochainement » de la fiche sont remplacés par de vrais formulaires (CSRF, authentification complète, 403 / 404 / 422 / 409 / 303 comme les autres écrans). Hors périmètre : opérations groupées (incrément 7), demandes et laissez-passer.
- **UI-02 — lot complémentaire « invitations intercommunales » (4 novembre 2026, en attente de validation)** : référentiel national des communes (API Géo, table locale `municipality_national_commune`, commande `app:communes:sync`), recherche dynamique dans le formulaire d'invitation (nom, code INSEE, préfixe ; commune non encore inscrite proposée), adresse e-mail de contact libre, e-mail HTML + texte via Symfony Mailer (MailCatcher en local), liens sécurisés (jeton 256 bits haché, expiration, usage unique pour l'administrateur), délégation de l'administrateur communal et inscription par lien personnel avec attestation (l'habilitation officielle n'est pas vérifiée : documenté). Détail, sécurité et points à valider : [`invitations-intercommunales.md`](invitations-intercommunales.md). Deux migrations à relire avant application sur la base de développement.
- **État (TECH-02.1)** : backend des événements, invitations, participations, cycle de vie, zones, validités et opérations groupées implémenté (étapes 1 à 5.5), **TECH-02.1 validée et terminée** ; aucun écran, contrôleur ni API. Avant l'exposition HTTP : plafond cumulé des opérations groupées et services de lecture des zones (plan 5.1 §23). Détail : `docs/lots/tech-02.1/plan-implementation.md`.

## LOT 03 — Dépôt citoyen
- Formulaire unique particulier/professionnel (simple indicateur), sans compte.
- Coordonnées email ou téléphone, véhicules facultatifs mais plaque obligatoire si véhicule coché, pièces facultatives 5×10 Mo, demandes multi-communes.
- Dossier figé après dépôt, plusieurs demandes par personne/événement.

## LOT 04 — Instruction
- Instruction par commune, décisions et notes privées ; historique immuable.
- Tests d'accès croisés et de concurrence.

## LOT 05 — Laissez-passer
- QR stable, PDF A4, autorisations communales, zones et véhicules.
- 24/48/72 h, date personnalisée, fin événement ; prolongation, modification, révocation, historique.
- Tests des échéances et des transitions intercommunales.

## LOT 06 — Contrôle
- Scan public minimal **avec plaques**.
- Activation PWA événementielle sans compte, appareil individuel, synchro et hors ligne.
- Tests expiration/révocation, sécurité de l'accès public, avertissement 12 h, blocage >24 h.

## LOT 07 — Notifications, audit, stockage
- Email/SMS asynchrones, audit, stockage privé, résilience des messages.
- Politique de conservation, suppression et accès aux pièces.

## LOT 08 — Administration SaaS et durcissement
- Supervision CWS via Sonata, abonnements, exploitation, sauvegardes, observabilité, tests de charge, revue sécurité et RGPD.

## Prompt initial à donner à Claude Code
> Lis `CLAUDE.md` et tous les fichiers `docs/`. Réalise uniquement l'audit préparatoire du LOT 00 : inspecte le dépôt, vérifie la stack, l'exposition de Sonata, les commandes de qualité, l'organisation existante et les écarts par rapport à la documentation. Ne crée ni entité, ni migration, ni fonctionnalité. Fournis un rapport avec les risques classés par priorité et une proposition précise pour le LOT 01, sans écrire de code tant que je n'ai pas validé.
