# DM-123 — Activation automatique des événements à l'heure de début

- **Identifiant** : DM-123
- **Thème** : Cycle de vie des événements
- **Statut** : Validée
- **Validation** : demande du commanditaire le 14 novembre 2026 (« un événement prévu à 20h30 ne s'active pas quand l'heure arrive »), option « activation automatique à l'heure de début » retenue.
- **Décisions remplacées** : Aucune (complète DM-059 ; l'activation manuelle reste possible).

## Décision

1. Un événement en **brouillon** dont l'**heure de début est atteinte** est **activé automatiquement par le système**, au plus une minute après (tâche planifiée `app:event:activate-due`).
2. Mêmes conditions que l'activation manuelle (DM-059) : au moins une zone prête (confirmée, active, validité configurée, participation active) **et** commune organisatrice **active** (DM-034). Sinon l'événement **reste en brouillon**, sans erreur : la fiche affiche un bandeau « L'heure de début est passée : l'événement n'est pas activé », et l'activation a lieu automatiquement dès que les conditions sont réunies.
3. Un événement dont la **fin est déjà passée** n'est jamais activé. Un événement **suspendu ou clôturé** n'est jamais touché : seule la transition brouillon → actif est automatique.
4. Audit : `EVENT_ACTIVATED`, acteur « système » (origine `SYSTEM`), sans compte, `trigger: schedule` ; même transaction et même verrou que l'activation manuelle ; rejeu sans effet.
5. La tâche planifiée est livrée dans `ops/systemd/` (`civipass-event-activation.service|timer`, chaque minute), supervisée (`event-activation`, retard au-delà de 10 minutes). **Elle n'est jamais installée par le dépôt** : l'installation sur le serveur reste un acte d'exploitation explicite. En local, lancer `app:event:activate-due` (ou `--dry-run` pour simuler).

## Écarts documentaires signalés

- Aucun.
