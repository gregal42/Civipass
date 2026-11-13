# DM-121 — Administration CWS des communes : séparation, accès commerciaux, invitations

- **Identifiant** : DM-121
- **Thème** : Administration CWS
- **Statut** : Validée
- **Validation** : A3 et A4 validés par le commanditaire le 13 novembre 2026
- **Décisions remplacées** : Aucune (précise DM-005, DM-036, DM-070, DM-073, DM-076, DM-078).

## Décision

1. **Séparation (A3)** : CWS attribue uniquement le rôle `MUNICIPALITY_ADMIN`, dans le cadre de l'onboarding communal. Aucune auto-attribution ; un compte super-administrateur ne peut être ni invité, ni rattaché à une commune ; CWS ne gère ni les autres rôles, ni les comptes CWS, et ne lit aucun dossier opérationnel.
2. **Accès commerciaux (A4)** : manuels, sans paiement ni facturation. Un accès est **révoqué** puis éventuellement **remplacé** par une nouvelle attribution ; pas d'état « en pause ». Le **renouvellement** crée un accès successeur qui commence à l'échéance de l'accès en cours. Un accès ne conditionne que la **création de nouveaux événements** : sa révocation ou son expiration n'interrompt jamais un événement ouvert (DM-073), prouvé par test.
3. **Dernier administrateur** : jamais retiré ni désactivé par une opération ordinaire (CWS comme commune) ; l'invitation en attente ne compte pas ; le remplacement se fait dans l'ordre inviter → accepté → retirer l'ancien. Commune ouverte sans administrateur : état « En attente d'administrateur » visible.
4. **Invitations** : jeton à usage unique, 7 jours, aucun droit avant acceptation, renvoi invalidant l'ancien lien, compte existant sans doublon (connexion avec l'adresse invitée), jamais de jeton ni d'adresse dans l'audit, les journaux ou les écrans.
5. Le motif de **révocation** d'un accès n'est pas conservé (aucune colonne ; `revoked_by` et la date le sont, DM-079).

## Documents concernés

- [lot-07-1-cadrage.md](../../lot-07-1-cadrage.md), [backoffice.md](../../backoffice.md)

## Écarts documentaires signalés

- Le cadrage prévoyait un motif obligatoire à la révocation : non retenu faute de colonne (pas de migration supplémentaire).
