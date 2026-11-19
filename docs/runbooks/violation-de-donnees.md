# Violation de données personnelles

> **PROJET TECHNIQUE — pas une validation juridique.** Procédure de travail préparée au LOT 11.3 pour validation par le responsable de traitement et le DPO ([décision 13 du dossier RGPD](../lot-11-3-dossier-rgpd.md)). Les délais légaux (art. 33 et 34 du RGPD) et la répartition des rôles sont rappelés à titre indicatif et doivent être confirmés. Elle **complète** [incident-securite.md](incident-securite.md) (contenir, préserver les preuves, qualifier techniquement) sans le répéter : ce runbook-ci couvre la **qualification juridique, la notification et le registre**.

**Cas** : toute atteinte accidentelle ou illicite à la confidentialité, l'intégrité ou la disponibilité de données personnelles (fuite, accès non autorisé, perte, altération, indisponibilité durable).

## 1. Rôles (hypothèses, à confirmer par contrat)

| Acteur | Rôle |
|---|---|
| **Commune** (responsable de traitement pour son dossier) | **Décide** et **notifie la CNIL** dans les 72 h après en avoir pris connaissance ; informe les personnes si le risque est élevé ; tient son registre des violations |
| **CWS** (sous-traitant, art. 28) | Détecte ou reçoit le signalement, **contient**, **alerte sans délai indu chaque commune concernée** (délai contractuel à fixer), fournit les éléments techniques, **ne notifie pas la CNIL à la place de la commune** sans mandat écrit |
| **DPO** | Qualifie le risque, valide les notifications |
| **Communes d'un dossier partagé** | En cas de responsabilité conjointe, un point de contact unique est désigné par la convention (décision 1 du dossier) |

Le **point de départ du délai de 72 h** est la prise de connaissance **par le responsable de traitement** ; pour CWS, c'est le moment où l'atteinte est établie avec un degré de certitude raisonnable. Journaliser cette heure dès le début.

## 2. Détection : où regarder

| Source | Signal |
|---|---|
| `app:ops:status`, `OPS_ALERT_EMAIL` | Tâche en retard ou en échec, alerte `OnFailure` |
| `app:ops:integrity-check`, `app:retention:register-check`, `app:retention:restore-check` | Chaîne du registre rompue, ancrage incohérent, données purgées réintroduites |
| Journaux Nginx et applicatifs | Pics de 404/429 sur `/v/…`, connexions répétées, accès hors plage horaire (les jetons y sont masqués) |
| Audit applicatif | Actions inhabituelles (révocations massives, changements de rôle, consultations d'archive) |
| Signalements | Agent, commune, bénéficiaire, contrôleur (téléphone perdu), hébergeur, prestataire SMTP, chercheur en sécurité |

## 3. Étapes

### Étape 0 — Contenir (immédiat, sans attendre la qualification)
Appliquer [incident-securite.md](incident-securite.md) étapes 1 et 2 : mode maintenance (`app:ops:maintenance --reason=…`), arrêt des minuteurs concernés, vérification des trois interrupteurs à `0`, **copie datée et en lecture seule** des preuves. Ne rien supprimer, ne pas rejouer le registre, ne pas « nettoyer ». Selon le cas :
- **Compte agent compromis** : désactiver le compte (écran Équipe ou Sonata via action nommée), faire terminer les sessions, réinitialiser le mot de passe par la procédure de [recuperation-acces.md](recuperation-acces.md).
- **QR divulgué ou copié** : remplacer le QR du laissez-passer concerné (LOT 05.5.5) ; l'ancien renvoie 410.
- **Téléphone de contrôle perdu** : désactiver l'appareil ; voir [controle-hors-connexion.md](controle-hors-connexion.md). Rappel : les données de l'appareil restent jusqu'à sa prochaine ouverture (R-OFF-01, AIPD hors connexion annexe B).
- **Fuite de sauvegarde ou de disque** : considérer **toutes** les données non purgées des communes comme exposées ; faire tourner les secrets (clé de chiffrement des notifications, [exploitation-notifications.md](../exploitation-notifications.md), identifiants de base, `APP_SECRET`).
- **Message envoyé à tort** : arrêter le distributeur (`civipass-notifications-dispatch.timer`) ; voir [incident-notification.md](incident-notification.md).

### Étape 1 — Qualifier (viser moins de 24 h)
Remplir la fiche (annexe) :
1. **Nature** : confidentialité, intégrité, disponibilité.
2. **Périmètre** : communes touchées (isolation par commune : une atteinte à une commune n'implique pas les autres sans preuve), événements, nombre approximatif de personnes et d'enregistrements ; l'audit et le registre de purge donnent des compteurs **sans donnée personnelle**.
3. **Catégories de données** (voir [inventaire](../lot-11-3-dossier-rgpd.md) §1) : identité, contact, plaques, justificatifs (potentiellement sensibles), textes libres, comptes agents.
4. **Cause et durée** : début, fin, vecteur, preuves conservées.
5. **Conséquences probables** pour les personnes : usurpation, atteinte à la vie privée, localisation d'un véhicule, divulgation de données sensibles contenues dans des justificatifs.
6. **Mesures prises**.

Le **DPO** apprécie le risque : *aucun risque* (consigner seulement), *risque* (notifier la CNIL), *risque élevé* (notifier la CNIL **et** informer les personnes).

### Étape 2 — Notifier la CNIL (responsable : la commune, dans les 72 h)
Contenu minimal (art. 33.3) : nature de la violation, catégories et nombre approximatif de personnes et d'enregistrements, coordonnées du DPO, conséquences probables, mesures prises ou proposées. **Une notification en plusieurs temps est admise** si tout n'est pas connu ; au-delà de 72 h, motiver le retard. CWS fournit la partie technique par écrit à la commune, avec l'heure de prise de connaissance.

### Étape 3 — Informer les personnes (si risque élevé)
En termes clairs : nature, contact du DPO, conséquences, mesures prises, conseils. **Canal** : décidé par la commune (courrier, e-mail aux coordonnées de la demande) ; **ne pas utiliser l'outbox de notification** de Civipass sans validation, car elle est conçue pour les laissez-passer (messages sans donnée sensible) et son envoi est limité par `REFUSAL_NOTICE_ENABLED`. Les personnes sans coordonnées reçoivent une information publique de la commune.

### Étape 4 — Registre des violations (art. 33.5)
Chaque violation, **notifiée ou non**, est inscrite au registre **de la commune** (et au registre de CWS pour ses traitements) : faits, effets, mesures. CWS conserve la fiche technique et les preuves jusqu'à décision du responsable.

### Étape 5 — Remédier et clore
Corrections **séparées** de l'incident (sous-lot cadré), restauration contrôlée ([restauration.md](restauration.md) avec rejeu du registre), levée de la maintenance par `app:ops:release` (refusée si le contrôle de restauration laisse des écarts), retour d'expérience sous 30 jours : cause, détection, délais, mise à jour du [registre de sécurité](../registre-securite.md) (anomalie, criticité, preuves), des durées et des consignes.

## 4. Scénarios types Civipass

| Scénario | Gravité de départ | Premiers gestes | Particularité |
|---|---|---|---|
| Accès d'une commune à un dossier d'une autre | Élevée | Contenir, relever les consultations dans l'audit | Faille d'isolation : **interrompre la recette** et enregistrer au registre de sécurité |
| Compte agent compromis | Élevée | Désactiver, sessions, mot de passe | Consulter l'audit pour les dossiers vus et exportés |
| Sauvegarde ou disque exposé | Élevée | Rotation des secrets, évaluer le contenu de la copie | Les données purgées depuis la copie y subsistent jusqu'à rotation (35 j) |
| QR copié ou capture d'écran | Moyenne | Remplacer le QR | Risque accepté en partie (DM-115, DM-125) |
| Téléphone de contrôle perdu | Moyenne | Désactiver l'appareil | Données limitées au jeu synchronisé, durée de confiance ≤ 12 h |
| E-mail envoyé au mauvais destinataire | Moyenne | Arrêter le distributeur, qualifier le contenu | Les messages prioritaires ne portent pas de donnée sensible |
| Journaux contenant un jeton | Basse à moyenne | Vérifier le masquage, purger la rotation | `SecretScrubbingLogger`, masquage Nginx |

## Annexe — Fiche de violation (modèle)

| Champ | Valeur |
|---|---|
| Référence interne, date et heure de détection, par qui | |
| Date et heure de prise de connaissance **par le responsable** (début des 72 h) | |
| Nature (C / I / D), vecteur, cause | |
| Communes et événements concernés | |
| Catégories de données et nombre approximatif (personnes, enregistrements) | |
| Données sensibles possibles (justificatifs, textes libres) | |
| Conséquences probables | |
| Mesures prises (heure) | |
| Qualification du DPO (aucun risque / risque / risque élevé) et date | |
| Notification CNIL (date, référence, étapes complémentaires) | |
| Information des personnes (décision, canal, date) | |
| Preuves conservées (emplacement, empreintes) | |
| Corrections décidées (sous-lot) et date de clôture | |

## Limites et décisions

À valider : délai contractuel CWS → commune, mandat éventuel de notification, désignation du point de contact par commune, canal d'information des personnes, durée de conservation du registre des violations. Procédure **jamais éprouvée** sur un exercice : prévoir un exercice sur table sur le serveur de recette avant le pilote.
