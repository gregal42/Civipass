# Civipass — Roadmap de développement V1

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
- Tests de droits multi-communes, création événement conditionnée à abonnement actif (service testable même si Event vient ensuite).

## LOT 02 — Événements et zones
- Event, EventParticipation, Zone, EventZone et snapshots.
- Commune organisatrice abonnée, invitation de communes non abonnées, zones pendant événement actif.
- Tests d'isolation, transitions, invalidation par sortie/clôture.

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
