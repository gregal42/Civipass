# DT-034 — Politique d'accès du module Event : matrice unique, commune déduite de la ressource

- **Identifiant** : DT-034
- **Thème** : Sécurité
- **Statut** : Proposée
- **Validation** : Choix d'implémentation de l'étape 4 (autorisée par le commanditaire) ; en attente de validation explicite
- **Décisions remplacées** : Aucune.

## Contexte

Étape 4 du plan TECH-02.1 ; DM-057, DM-076, DM-073.

## Décision

`EventPermission` est la source unique de la matrice DM-057 (rôles autorisés par permission). `EventAccessPolicy` produit des `AccessDecision` testables, sans Symfony Security : la commune agissante n'est jamais fournie par le client, elle se déduit de la chaîne Event → organisatrice, EventParticipation → commune, EventZone → participation → commune, créneau → zone → participation → commune. Lecture : membership effectif (commune ACTIVE ou SUSPENDED) ; écriture : commune ACTIVE et rôle autorisé. `EventResourceResolver` charge une ressource depuis un identifiant non fiable et refuse avec le même message (« Ressource inaccessible. ») pour inexistant, invalide et non autorisé. Le droit d'utilisation n'est exigé que pour créer un événement (DM-076). **Étape 4 finalisée (U1–U4, DM-084)** : niveaux de visibilité sur les participations (`ParticipantListVisibility` : Full, Coordination, OwnInvitationOnly, None) décidés par `EventAccessPolicy::participantListVisibility` pour la commune du contexte ; lectures par `EventReadModel` (SQL borné, colonnes nommées, aucune entité) et projections dédiées ; `EventListReader` et `EventParticipantReader` appliquent le contexte municipal vérifié, la visibilité, puis seulement la requête correspondant au niveau.

## Conséquences fonctionnelles ou techniques

- Aucun contrôleur ni voter : les services de l'étape 5 appellent directement la politique (`orThrow()`).
- `superAdmin` ne donne aucun droit communal ; `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT` restent refusées par défaut.
- Dépendances : le module Event ne consomme que `MembershipAccessPolicy` et `EventCreationPolicy` du module Municipality (test d'architecture).
- `MembershipRepository::findActiveByUser()` ajouté (aucun changement de schéma).
- Les listes ne chargent jamais « tout » : la restriction est dans la requête (organisatrice ou participation de la commune).
- `ParticipantList` ne porte que le contenu de son niveau : aucune donnée interne chargée pour une projection moins privilégiée.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
