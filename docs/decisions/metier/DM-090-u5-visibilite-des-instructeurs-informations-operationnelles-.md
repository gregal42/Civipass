# DM-090 — U5 — Visibilité des instructeurs : informations opérationnelles seulement

- **Identifiant** : DM-090
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-084 (U3, U4) ; DM-057.

## Décision

Les agents instructeurs accèdent uniquement aux informations opérationnelles nécessaires à leur mission : informations générales des événements, dates et horaires, statuts opérationnels, zones et créneaux, liste des communes participantes autorisée par les règles de visibilité. Ils n'accèdent pas aux motifs administratifs internes de suspension, aux invitations en attente d'autres communes, aux informations internes de gestion des invitations, aux audits administratifs, aux données commerciales ni aux données personnelles non nécessaires. Aucune extension de leurs droits de modification.

## Conséquences fonctionnelles ou techniques

- Nouvelle permission de lecture `ViewParticipationDetails` (administrateur et gestionnaire de la commune organisatrice) ; l'instructeur de l'organisatrice reçoit le niveau `Coordination` (organisatrice et communes ACTIVE ou SUSPENDED), jamais le niveau `Full`.
- Un utilisateur cumulant instructeur et un rôle de gestion dans la commune organisatrice obtient le niveau de gestion.

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Évolue DM-084 : la visibilité de l'organisatrice dépend désormais du rôle.
