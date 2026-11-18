# Runbook — clôture exceptionnelle d'un événement par CWS (LOT 08.6)

**Statut** : prêt pour la recette locale ; opération **exceptionnelle**, jamais un raccourci. Décisions : DM-065, DM-139, DM-005 (aucun droit communal pour CWS).

## 1. Quand ?
Uniquement si l'organisatrice **ne peut pas** clore elle-même l'événement (administrateur injoignable ou parti) et qu'une demande **écrite** de l'autorité compétente existe. Conserver sa référence : elle figure dans le motif.

## 2. Habiliter un opérateur (exploitation, console)
```
bin/console app:cws:permission grant  marie@cws.example CWS_FORCE_CLOSE_EVENT --by=paul@cws.example --reason="Astreinte de crise — référence écrite 2026-123"
bin/console app:cws:permission list
bin/console app:cws:permission revoke marie@cws.example CWS_FORCE_CLOSE_EVENT --by=paul@cws.example
```
- Aucune permission n'existe par défaut ; **nul ne peut se l'attribuer à soi-même** ; bénéficiaire et attributeur sont des super-administrateurs actifs ; motif de 10 à 200 caractères, sans donnée personnelle.
- La révocation est **immédiate** (la permission est relue en base à chaque opération, sous verrou). Un compte désactivé ou rétrogradé perd la fonction à la requête suivante.

## 3. Clore (opérateur habilité, authentification complète)
1. Ouvrir `/admin/cws/evenements/cloture`, saisir la **référence** de l'événement.
2. Lire l'aperçu : participations ouvertes, volumes (compteurs, **aucune donnée personnelle**), conséquences.
3. Saisir le **motif** (15 à 300 caractères, interne CWS), la **référence exacte** de l'événement et le **mot de passe**.
4. Confirmer. Cinq tentatives par quart d'heure et par opérateur ; en cas d'erreur **rien n'est modifié**.

## 4. Conséquences
Même moteur que la clôture normale, dans **une seule transaction** : événement et **toutes** les participations clôturés, statistiques de clôture, invitations révoquées, liens bénéficiaires fermés, notifications de laissez-passer neutralisées. Les laissez-passer ne donnent plus de droit ; le contrôle en ligne affiche « Événement terminé » ; un appareil de contrôle **hors connexion** reçoit un jeu vide marqué clos à sa **prochaine synchronisation** (jusque-là : risque R-OFF-01, ≤ 12 h au pilote). Les administrateurs communaux concernés reçoivent un e-mail **sans le motif**.

## 5. Traces
Audit : `EVENT_CLOSED` (origine SUPPORT, opérateur réel, cause `CWS_FORCED`), `ParticipationClosed` par participation, `EVENT_FORCED_CLOSURE` (motif, participations avec leur statut précédent, compteurs), `CWS_PERMISSION_GRANTED` / `CWS_PERMISSION_REVOKED`. Jamais de mot de passe, de donnée personnelle ni de donnée de dossier.
