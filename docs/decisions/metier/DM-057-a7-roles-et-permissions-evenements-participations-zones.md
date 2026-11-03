# DM-057 — A7 — Rôles et permissions (événements, participations, zones)

- **Identifiant** : DM-057
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §3 et Annexe B ; UI-02 §3 ; ancien SF-07 : divergences S7 et S10.

## Décision

Option A modifiée, précisée par R1 et R2. L'administrateur communal possède tous les droits du gestionnaire d'événement pour sa commune et peut gérer seul un événement. **Administrateur communal** : créer des événements, modifier ceux de sa commune, les activer, suspendre et clôturer, inviter des communes, **accepter les invitations reçues**, gérer sa participation, créer/modifier/confirmer/activer/suspendre/supprimer logiquement ses zones, configurer les validités, faire des opérations groupées. **Gestionnaire d'événement** (R1) : créer, modifier, **activer, suspendre et clôturer** les événements organisés par sa commune, inviter des communes, suspendre/réactiver/clôturer la participation de sa commune ; **ne peut pas accepter une invitation (R2)** ni administrer les zones sans être aussi administrateur communal. **Agent instructeur** : aucune permission de gestion des événements ou des zones du seul fait de ce rôle. Toutes les permissions sont contextualisées par commune ; les rôles sont cumulables.

## Options examinées

- (a) matrice recommandée, modifiée : l'administrateur cumule les droits du gestionnaire — **retenue**

## Conséquences fonctionnelles ou techniques

- Politiques d'accès : tests négatifs avec identifiants forgés et rôles identiques dans une autre commune.
- Pas de rôle « gestionnaire communal ».
- `ROLE_SUPER_ADMIN` n'accorde aucun droit communal automatique (DM-005) ; l'impersonation relève de DM-061.
- U1 confirmé : les zones et créneaux restent réservés à l'administrateur communal (pas au gestionnaire d'événement). U4 : la consultation (événements, participations, zones) est ouverte à tout rôle d'une commune concernée, instructeur compris ; aucune opération de gestion pour l'instructeur (DM-084).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§3, Annexe B)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§3)

## Écarts documentaires signalés

- Écarts S7 et S10 levés ; points R1 et R2 levés.
- `docs/security.md` doit encore être aligné (N5, voir `docs/revisions/CONTRADICTIONS.md`).
