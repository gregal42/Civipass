# DM-120 — Cycle de vie des événements : clôture, archive intermédiaire, purge et suppression

- **Identifiant** : DM-120
- **Thème** : LOT 05.6 — Conservation et suppression des données (RGPD)
- **Statut** : **Proposée** — **orientation et dix arbitrages VALIDÉS par le commanditaire le 11 novembre 2026** ; **arbitrages bloquants D1 à D4 validés le 11 novembre 2026** (règle 8, option B de désignation, dossier communal, accord de principe sur les migrations) ; **durées, règles d'élimination, archives publiques et modalités de gel restent à valider par les responsables compétents avant la production** (la durée de 12 mois est une hypothèse, pas une obligation légale établie) ; **SQL de chaque migration à valider avant le sous-lot concerné**. Cadrage : [lot-05-6-cadrage.md](../../lot-05-6-cadrage.md).
- **Décisions remplacées** : les **hypothèses de conservation progressive** de DM-119 §9 (anonymisation de champs à `T`+30 j, `T`+3 mois, `T`+6 mois), **abandonnées** ; complète DM-117 §3 ; **amende** DM-114 (lien bénéficiaire) et DM-115 (comportement après la purge).

## 1. Contexte

Civipass est un outil **temporaire** de gestion d'événement pour les collectivités : demandes, bénéficiaires, laissez-passer et QR, plaques, justificatifs, agents, communes participantes, décisions de chaque commune. Il n'a pas vocation à devenir une base permanente de citoyens, de véhicules ou de demandes. DM-117 §3 interdit la mise en production du LOT 05 sans politique de conservation et mécanisme adaptés.

## 2. Décisions

1. **[Validé] Principe directeur** : les données opérationnelles d'un événement ne sont plus accessibles à sa clôture ; leur conservation ultérieure répond à une finalité précise (litige, obligation légale).
2. **[Validé] Trois états** : *actif* (fonctionnement actuel) ; *clôturé* (accès opérationnels fermés, données conservées **identifiables** dans une **archive intermédiaire** cloisonnée) ; *supprimé* (suppression définitive). Une donnée conservée dans une archive identifiable n'est **jamais** présentée comme anonymisée.
3. **[Validé] À la clôture, immédiatement** : statistiques anonymes calculées ; QR inutilisables pour autoriser un passage ; page publique « Événement terminé » seule ; plus d'accès opérationnel (tous rôles, dont `INSTRUCTOR`) ; **lien bénéficiaire fermé** ; liens d'invitation révoqués ; notifications en attente annulées ; fiche événement et statistiques anonymes consultables.
4. **[Validé] Demandes non décidées** : conservées dans l'archive avec leur **statut réel**, comme les refusées et les approuvées.
5. **[Validé] Archive** : accès **uniquement** à `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` **de la commune concernée** ; jamais `INSTRUCTOR` ; aucune donnée confidentielle d'une autre commune, **organisateur compris** ; écrans séparés, justification, journalisation, lecture seule, fermeture à terme. **[Validé, D2]** option B : `EVENT_MANAGER` reste un rôle communal ; l'accès aux archives exige en plus une **désignation explicite** de l'organisateur habilité **par événement et par commune** (par `MUNICIPALITY_ADMIN`) ; aucune extension automatique à d'autres événements ou communes ; permissions opérationnelles inchangées.
6. **[Validé] Gel contentieux** : motivé, limité au périmètre nécessaire (le dossier), réexaminé régulièrement ; **un gel n'ouvre jamais les archives des autres communes**.
7. **[Validé] Données communes et décisions propres** : identité, contact, adresse, plaques, justificatifs, laissez-passer et QR sont **communs** (une copie) ; ligne demande × commune, décision, autorisation, révisions sont **propres à chaque commune**.
8. **[Hypothèse, non validée] Durée d'archive** : **12 mois** après la clôture, à confirmer avec le **DPO** et le **service d'archives compétent** ; aucune durée n'est une obligation légale certaine sans fondement vérifié.
9. **[Validé] Purge et suppression** : purge **par dossier** (service explicite, **idempotent, reprenable**) quand plus aucune commune n'a d'accès ni de gel ; suppression définitive **explicite** de l'événement, seulement sur un événement purgé ou sans donnée personnelle ; **clés étrangères `RESTRICT` conservées, pas de `CASCADE` généralisé**. **[Validé, D1]** règle 8 : « Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. » **[Validé, D3]** l'unité de gel et de purge est le **dossier communal** (demande × commune) ; la clôture reste globale ; une donnée partagée n'est pas supprimée tant qu'un dossier la justifie ; un gel n'ouvre jamais les dossiers d'une autre commune. **[Validé, D4]** accord de principe sur les migrations : SQL, entités, contraintes, données existantes, risques et retour arrière présentés et validés avant chaque sous-lot ; aucune exécution sans accord explicite.
10. **[Validé] Audit, justificatifs, notifications, sauvegardes** : conservation limitée aux besoins de preuve et aux obligations applicables, puis suppression contrôlée ; fichiers physiques, notifications et sauvegardes inclus ; audit global (comptes, communes) inchangé.
11. **[Validé] Comptes** : jamais de suppression d'un utilisateur global à cause d'un événement ; **rapport de comptes candidats** ; **aucune suppression automatique en V1** ; nettoyage des accès événementiels ; comptes partagés et permanents préservés.
12. **[Validé] QR après la purge (option O1)** : avant la purge, comportements publics inchangés (**410** pour un QR remplacé, **404** inconnu, « Événement terminé » pour un événement clos) ; **après la purge définitive du dossier : 404 neutre** pour tout jeton ; aucune conservation résiduelle.
13. **[Validé] Événements sans fin** : détection et alerte, **sans clôture automatique** susceptible de modifier les droits.

## 3. Conséquences fonctionnelles ou techniques

Nouveau module d'archive ; clôture étendue ; service de purge et de suppression ; rapport de comptes candidats ; détection de dormance ; quelques colonnes (gel sur la ligne demande × commune, statistiques et état de l'événement) à présenter avant migration. Aucune implémentation ni migration à ce stade.

## 4. Documents concernés

[lot-05-6-cadrage.md](../../lot-05-6-cadrage.md), [DM-114](DM-114-lien-beneficiaire-distinct-du-qr.md), [DM-115](DM-115-scan-public-integral-sans-habilitation.md), [DM-117](DM-117-notifications-du-beneficiaire-et-conservation-des-donnees.md), [DM-119](DM-119-revisions-des-droits-d-un-laissez-passer.md) §9, [DM-118](DM-118-liens-d-acces-beneficiaire-cycle-de-vie-et-remise-par-l-agent.md), [CONTRADICTIONS.md](../../revisions/CONTRADICTIONS.md) L5-26 à L5-31.

## 5. Écarts documentaires signalés

Règle 8 de CLAUDE.md face à la suppression contrôlée (L5-28, ouvert) ; DM-114 (lien 30 jours) amendé ; DM-115 (contrat après la purge) amendé ; DM-119 §9 (hypothèses de durées) abandonné.
