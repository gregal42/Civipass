# DM-086 — D4 — Désignation des communes invitées par identifiant ; commune non abonnée invitable

- **Identifiant** : DM-086
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrages D1 à D8, U5 et U6 validés par le commanditaire (message « Sous-étape 5.2 — création des événements et invitations intercommunales ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-034, DM-070, DM-076, SFD §8 (participation gratuite d'une commune invitée).

## Décision

Une commune est désignée par son identifiant stable. Le service ne dépend d'aucune recherche textuelle (la future interface pourra chercher par nom ou code INSEE/COG). **Une commune non abonnée peut être invitée** : son absence d'abonnement n'empêche ni l'enregistrement de l'invitation, ni son acceptation par un administrateur habilité, ni sa participation, ni la gestion de ses zones et créneaux dans les limites de ses permissions. Cet accès est gratuit et limité à l'événement ; il ne donne pas le droit de créer ses propres événements (abonnement, TRIAL ou accès commercial CWS valide). L'invitation ne crée ni utilisateur, ni adhésion, ni abonnement. Une commune sans administrateur habilité conserve une invitation en attente tant qu'un mécanisme d'habilitation autorisé n'existe pas (non développé ici).

## Conséquences fonctionnelles ou techniques

- Seul le statut administratif de la commune invitée est contrôlé (`ACTIVE`) ; inexistante, invalide ou indisponible : même refus « Commune introuvable ou indisponible. ».
- Aucune expiration d'une invitation en attente.
- **Vigilance 5.2** : `resendInvitation` enregistre une **demande de réexpédition** (`lastInvitationSentAt`), sans garantie qu'une notification ait été envoyée : aucun système de notification n'existe ; le nom du champ est celui du modèle validé et n'est pas modifié.
- **V1** : une invitation peut rester en attente indéfiniment (accepté) ; elle ne peut **jamais** être acceptée après la clôture de l'événement (garanti sous verrou, testé en 5.3).

## Documents concernés

- [step-5-transactional-services-plan.md](../../lots/tech-02.1/step-5-transactional-services-plan.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§8)

## Écarts documentaires signalés

- Lève la question D4 de l'étape 5.1.
