# DT-053 — Invitation d'administrateur communal émise par CWS : table dédiée (M1)

- **Identifiant** : DT-053
- **Thème** : Données, onboarding
- **Statut** : Validée
- **Validation** : A2 et M1 validés par le commanditaire le 13 novembre 2026
- **Décisions remplacées** : Aucune.

## Décision

Table `municipality_admin_invitation` (migration `Version20261113160211`, ajout seul), distincte de `onboarding_invitation_link` (qui exige une participation d'événement). Seule l'**empreinte SHA-256** du jeton (256 bits, base64url) est stockée ; `consumed_by`, `revoked_by` et `parent_id` tracent l'historique ; aucune suppression physique (FK RESTRICT). Validité **7 jours**, configurable (`ADMIN_INVITATION_DAYS`, 1 à 30).

- Consommation et révocation sont des **mises à jour SQL conditionnelles atomiques et mutuellement exclusives** : une invitation n'est jamais acceptée ET révoquée, ni acceptée deux fois.
- L'invitation ne donne **aucun droit** : le rattachement `MUNICIPALITY_ADMIN` n'est créé qu'à l'acceptation, dans la même transaction que la consommation (`MembershipManager::enrollInvitedAdministrator`, sous le verrou de la commune). Une invitation en attente n'est jamais un administrateur effectif.
- Un seul lien utilisable par (commune, adresse) : renvoyer ou réinviter révoque les précédents sous le verrou de la commune.
- Les pages d'acceptation réutilisent les routes existantes `/invitation/{jeton}`, `/invitation`, `/invitation/rejoindre` (masquage nginx, liste blanche et tests inchangés).
- **Correction de concurrence** : `countEffectiveAdmins` lit désormais en lecture courante (`FOR SHARE`) ; sous REPEATABLE READ, deux retraits ou désactivations simultanés pouvaient sinon laisser une commune sans administrateur (mis en évidence par `CwsConcurrencyTest`).

## Documents concernés

- [lot-07-1-cadrage.md](../../lot-07-1-cadrage.md), DT-024, DT-036, DT-037.

## Écarts documentaires signalés

- Aucun.
