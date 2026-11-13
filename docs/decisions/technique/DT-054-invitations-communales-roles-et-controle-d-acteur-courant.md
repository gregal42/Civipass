# DT-054 — Invitations communales avec rôles (M2) et contrôle d'acteur en lecture courante

- **Identifiant** : DT-054
- **Thème** : Données, sécurité
- **Statut** : Validée
- **Validation** : A1 (M2) validé par le commanditaire (validation du cadrage du LOT 07.2)
- **Décisions remplacées** : Aucune (complète DT-053).

## Décision

- **M2** (`Version20261113191749`) : colonne `roles` (JSON, non nulle) sur `municipality_admin_invitation` ; migration en trois temps (ajout nullable, renseignement des invitations existantes avec `MUNICIPALITY_ADMIN`, passage en `NOT NULL`). Le nom historique de la table est conservé ; les nouveaux composants parlent d'« invitation à rejoindre une commune » (`TeamInvitationService`, `InvitationDelivery`, `TeamReader`, `RoleLabels`).
- Les rôles d'une invitation (dédupliqués, non vides, ordre canonique) ne sont donnés **qu'à l'acceptation**, dans la transaction de consommation (`MembershipManager::enrollInvited`) ; un rattachement inactif est réactivé avec ces rôles.
- **Le droit de l'acteur est relu sous le verrou de la commune, en lecture courante** (`MembershipRepository::isEffectiveAdmin`, `FOR SHARE`) : un administrateur rétrogradé à l'instant par un pair perd immédiatement tout pouvoir, même avec un formulaire ou une session ouverts (sinon l'instantané REPEATABLE READ le laisserait agir). Même principe que `countEffectiveAdmins` (DT-053).
- Limitation de débit des invitations communales : 20 / heure et par commune, 10 / heure et par administrateur (paramètres techniques, `framework.yaml`).

## Documents concernés

- [lot-07-2-cadrage.md](../../lot-07-2-cadrage.md), DT-024, DT-036, DT-053.

## Écarts documentaires signalés

- Aucun.
