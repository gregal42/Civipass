# DM-124 — Cumul des rôles par défaut d'un administrateur communal

- **Identifiant** : DM-124
- **Thème** : Équipes communales, rôles
- **Statut** : Validée
- **Validation** : demande du commanditaire le 14 novembre 2026 (« l'administrateur communal doit pouvoir instruire les demandes ; par défaut il hérite de tous les autres rôles »), option « cumul par défaut » retenue.
- **Décisions remplacées** : Aucune (précise DM-057, DM-121 et DM-122 ; **les rôles restent explicites, cumulables, sans hiérarchie**).

## Décision

1. Un administrateur communal reçoit **par défaut les trois rôles** : `MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR`. Il peut donc instruire les demandes sans autre habilitation.
2. Le cumul s'applique quand l'**administration est accordée** : invitation d'un administrateur (par CWS ou par un administrateur de la commune), promotion d'un collaborateur au rôle d'administrateur depuis l'écran Équipe, administrateurs existants (migration de données `Version20261114204500`, rattachements et invitations non consommées).
3. Ce n'est **qu'un défaut** : l'administrateur peut ensuite retirer un rôle depuis l'écran Équipe, et ce choix est respecté (la modification des rôles d'un administrateur existant n'ajoute rien). Aucun droit implicite n'est calculé : les contrôles d'accès lisent les rôles réellement portés.
4. **Précise DM-121** : l'invitation d'un administrateur par CWS porte désormais les trois rôles ; CWS ne gère toujours que les invitations et rattachements qui accordent l'administration, jamais ceux des agents.
5. Un administrateur d'une commune n'a **aucun droit** sur une autre commune (inchangé).

## Migration

`Version20261114204500` : données seules (aucun changement de schéma). Retour arrière : la sauvegarde prise avant l'exécution (les rôles d'origine ne sont pas conservés).

## Écarts documentaires signalés

- DM-057 décrit des rôles « sans hiérarchie » : le cumul par défaut est conforme (c'est un jeu de rôles attribués, pas un héritage).
