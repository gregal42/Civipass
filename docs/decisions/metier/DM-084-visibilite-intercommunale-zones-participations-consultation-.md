# DM-084 — Visibilité intercommunale : zones, participations, consultation par les instructeurs (U2, U3, U4)

- **Identifiant** : DM-084
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages U1 à U4 validés par le commanditaire (message « Finalisation de l'étape 4 : permissions et isolation intercommunale ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-007 (zones des autres communes en lecture seule), SFD §2.1 et SCR-014 ; DM-057.

## Décision

**U2** : une commune ayant accepté sa participation (ACTIVE, SUSPENDED ou CLOSED) consulte en lecture seule les zones des autres communes participantes ; une commune simplement invitée ou étrangère ne les consulte pas ; la modification reste limitée à la commune propriétaire, sous réserve du rôle. **U3** : la commune organisatrice consulte toutes les participations et leurs informations de gestion ; une commune participante (acceptée) consulte une **liste de coordination limitée** (identifiant, nom, statut de participation de l'organisatrice et des communes ACTIVE ou SUSPENDED) sans motif de suspension, audit, informations d'invitation, données personnelles des agents ni information commerciale ; une commune invitée voit sa propre invitation et les informations minimales nécessaires à sa décision, jamais la liste ; une commune étrangère ne voit rien. Les invitations en attente et les participations clôturées ne sont pas exposées aux autres participantes. Le filtrage et la sélection des champs sont appliqués dans une couche applicative dédiée (projections de lecture, requêtes bornées), jamais dans un contrôleur. **U4** : un agent instructeur consulte les événements accessibles à sa commune, sans aucune opération de gestion ; pas de droit global. **U1** : DM-057 est conservé (zones et créneaux : administrateur communal seul).

## Conséquences fonctionnelles ou techniques

- Projections `EventParticipationSummary` (coordination), `EventParticipationDetails` (organisatrice), `InvitationView` (invitée), `EventListItem` ; `EventReadModel` (SQL borné) ; `EventListReader` et `EventParticipantReader`.
- Contexte municipal vérifié par `MunicipalityContext` (membership effectif, statut administratif) ; aucun cumul de privilèges entre communes.
- Les consultations restent ouvertes à tout rôle d'une commune concernée.
- Évolué par DM-090 (U5 : lecture différenciée par rôle pour l'organisatrice) et DM-091 (U6 : historique après clôture).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [README.md](../../decisions/README.md)

## Écarts documentaires signalés

- Aucune contradiction avec une décision validée. Points U5 (voir `docs/revisions/CONTRADICTIONS.md`) : visibilité des informations de gestion pour les instructeurs de l'organisatrice ; liste réduite après clôture.
