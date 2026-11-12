# LOT 05.6.5 — Comptes candidats et dormance des événements : cadrage (12 novembre 2026)

**Statut : CADRAGE soumis à validation (une seule fois, protocole de `CLAUDE.md`). Aucune implémentation, aucune migration exécutée.** Références : [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §5.2 et fiche 05.6.5, [lot-05-6-4-cadrage.md](lot-05-6-4-cadrage.md), DM-120. Les seuils ci-dessous sont des **hypothèses paramétrables**, jamais des validations juridiques.

## 1. Objectif et périmètre

1. **Rapport de comptes candidats** à suppression ou anonymisation, en **lecture seule** (aucune suppression, aucune anonymisation, aucune désactivation).
2. **Détection des événements sans date de fin** (`ends_at` nul) **actifs ou suspendus** et **inactifs** depuis longtemps ; **alerte** des responsables, **jamais de clôture automatique**, aucun changement de statut ni de droit.
3. Traçabilité et protection contre les alertes répétitives.

Exclusions : suppression/anonymisation effective d'un compte (décision CWS ultérieure, avec le DPO), droits des personnes (05.6.6), runbooks et planification (05.6.7), événements `DRAFT` ou clos.

## 2. Comptes candidats

**Éligibilité** (toutes les conditions, évaluées en SQL, déterministes, idempotentes) :
1. Compte **sans appartenance active** (ou désactivé), **non super-administrateur**, sans permission CWS.
2. **Aucune connexion** depuis `ACCOUNT_INACTIVITY_MONTHS` (hypothèse 24) ; un compte jamais connecté est jugé sur sa date de création.
3. N'est **le dernier `MUNICIPALITY_ADMIN` d'aucune commune** (`LastMunicipalityAdminGuard`).

**Classement** (sans effet) : **`DELETABLE`** si aucune clé `RESTRICT` conservée ne référence le compte ; **`ANONYMISE_ONLY`** s'il est référencé (décisions, saisies, consultations d'archive, gels, désignations, habilitations de coordination, révisions de laissez-passer, invitations, événements, zones). Le **catalogue des références est lu dans le schéma Doctrine** (toute nouvelle clé vers `identity_user` est couverte ; un test échoue si une clé n'est pas classée). Motifs de non-candidature codés (`ACTIVE_MEMBERSHIP`, `RECENT_LOGIN`, `LAST_ADMIN`, `CWS`).

**Sortie** : identifiant (UUID), catégorie, motifs codés, mois de dernière connexion, nombre de références par table ; e-mail **masqué** (`a***@d***.fr`), jamais de nom ni de journal complet ; **rien n'est persisté** (sortie console, `--format=json|table`) ; une ligne d'audit par exécution (opérateur, effectifs), sans liste.

**Habilitations** : **super-administrateur CWS** uniquement (compte global ; un administrateur communal ne voit jamais les comptes d'autres communes). Pas de migration.

## 3. Dormance des événements

**Règle R-b** : événement `ACTIVE` ou `SUSPENDED`, `ends_at` nul, aucune **activité** depuis `EVENT_DORMANCY_DAYS` (hypothèse 90). **Activité** = la plus récente de : modification de l'événement, de ses zones ou participations (audit), dépôt de demande, décision, révision de laissez-passer, émission ou changement d'autorisation, vérification enregistrée. La date de passage à `ACTIVE` sert de plancher.

**Destinataires** : `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` actifs de la **commune organisatrice** seulement (jamais les communes partenaires, instructeurs, citoyens). E-mail **sans donnée personnelle** (nom et référence de l'événement, nombre de jours, lien vers l'écran de l'événement), via le mécanisme d'envoi de `RetentionNoticeService`. **Bandeau** sur l'écran de l'événement pour ces mêmes rôles, relu côté serveur.

**Déclencheur et fréquence** : commande `app:event:dormancy-alert` (**simulation par défaut**, `--execute`, `--limit`) et rapport `app:event:dormancy-report` (sans donnée personnelle). **Aucune planification créée** (05.6.7) ; cadence conseillée : quotidienne. **Anti-répétition** : une alerte par événement au plus tous les `EVENT_DORMANCY_REMINDER_DAYS` (30) ; **report motivé** par l'administrateur ou l'organisateur de l'organisatrice (motif codé + précision ≤ 200 caractères sans donnée personnelle, durée 30/60/90 jours, renouvelable, tracé) ; toute activité nouvelle remet l'événement hors alerte.

**Concurrence** : prise de l'alerte par `UPDATE … WHERE dormancy_alerted_at IS NULL OR dormancy_alerted_at < :limite` (un seul gagnant, deux exécutions simultanées n'envoient qu'un courriel) ; échec d'envoi : état restauré, rejouable.

## 4. Migration envisagée (à approuver)

**M8**, seulement pour l'anti-répétition atomique et la lecture du report :

```sql
ALTER TABLE event_event ADD dormancy_alerted_at DATETIME DEFAULT NULL, ADD dormancy_snoozed_until DATETIME DEFAULT NULL;
```

Colonnes nullables, aucune donnée existante modifiée (NULL), pas d'index (parcours limité aux événements actifs ou suspendus sans fin, déjà indexés par statut). `down()` : suppression des deux colonnes (seul l'état d'alerte est perdu, l'audit subsiste). Sauvegarde vérifiée avant exécution sur `civipass_test` puis `civipass`. **Alternative sans migration** : lire l'audit pour savoir si une alerte a été envoyée ; rejetée par défaut (pas de garde atomique, relecture d'audit à chaque passage).

## 5. Tests majeurs

Comptes : candidats exacts ; **non candidats** (appartenance active, super-admin, dernier administrateur, connexion récente, compte partagé ou permanent) ; classement `DELETABLE` / `ANONYMISE_ONLY` ; couverture du catalogue de clés ; **aucune écriture** (comptes, appartenances, rôles inchangés) ; e-mail masqué ; accès refusé à tout non-super-administrateur. Dormance : R-b détectée, événement actif récent, événement avec fin, clos et brouillon non signalés ; activité de chaque type écarte l'alerte ; destinataires exacts (commune partenaire, instructeur, utilisateur désactivé exclus) ; e-mail sans donnée personnelle ; **anti-répétition**, report puis expiration, concurrence réelle (un seul envoi) ; **aucun changement de statut ni de droit** ; bandeau réservé aux rôles autorisés ; simulation sans écriture ; échec d'envoi puis reprise ; audit.

## 6. Dépendances

- **05.6.6** : l'**anonymisation** d'un compte n'est pas implémentée ; la catégorie `ANONYMISE_ONLY` prépare le besoin et sera réutilisée si les droits des personnes l'exigent.
- **05.6.7** : runbooks (rapport, alerte), planification, durées de journaux, restauration (après restauration de la base, l'état d'alerte peut régresser : une alerte supplémentaire possible, sans effet sur les droits).

## 7. Arbitrages nécessaires (défaut si aucune réponse : recommandation)

1. **Audience du rapport de comptes** : CWS seul (**recommandé**) ou aussi, par commune, les administrateurs communaux (exposition de comptes d'autres communes à écarter).
2. **Migration M8** (deux colonnes) : **recommandée** ; sinon lecture de l'audit.
3. **Règles de dormance** : **R-b seule** (conforme à la demande, **recommandé**) ou aussi « fin planifiée dépassée de 30 jours » et « autorisations toutes expirées » de la fiche initiale.

Confirmations implicites, sans arbitrage : seuils paramétrables (24 mois, 90 jours, 30 jours, report 90 jours maximum) ; aucune suppression, anonymisation, désactivation ni clôture ; aucun push ni déploiement ; `PUBLIC_VERIFICATION_ENABLED` reste à `0`.
