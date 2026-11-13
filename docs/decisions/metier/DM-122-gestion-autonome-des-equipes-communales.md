# DM-122 — Gestion autonome des équipes communales

- **Identifiant** : DM-122
- **Thème** : Équipes communales
- **Statut** : Validée
- **Validation** : A2 et A3 validés par le commanditaire (validation du cadrage du LOT 07.2)
- **Décisions remplacées** : Aucune (précise DM-036, DM-057, DM-121).

## Décision

1. Un `MUNICIPALITY_ADMIN` invite, habilite et retire les collaborateurs de **sa** commune depuis l'écran « Équipe » (rôles `MUNICIPALITY_ADMIN`, `EVENT_MANAGER`, `INSTRUCTOR`, cumulables), sans CWS ni Sonata. Un autre rôle n'a aucun accès ; commune suspendue : lecture seule (DM-034).
2. **Administrateurs pairs (A2)** : un administrateur peut retirer ou rétrograder un autre administrateur, **jamais le dernier administrateur actif**, et **jamais lui-même** (ni son rôle, ni son rattachement) : il demande à un pair.
3. **Notifications (A3)** : aucun e-mail lors d'une modification ou d'un retrait d'habilitation en V1 (effet immédiat et audit seulement) ; les invitations, elles, sont envoyées.
4. Retirer un collaborateur ne désactive que son rattachement à **cette** commune ; son compte et ses autres communes sont indépendants.
5. Un refus d'invitation ne révèle jamais la nature d'un compte hors de la commune (compte CWS, désactivé : même message neutre) ; un compte CWS ne peut être rattaché (DM-121).
6. La fiche CWS ne montre que les invitations et rattachements **administrateurs**, jamais ceux des agents.

## Documents concernés

- [lot-07-2-cadrage.md](../../lot-07-2-cadrage.md)

## Écarts documentaires signalés

- Aucun.
