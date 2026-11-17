# LOT 08.6 — Clôture exceptionnelle par CWS : schéma proposé (non approuvé)

**Date** : 17 novembre 2026 · **Statut** : **proposition de schéma à approuver ; aucune migration créée ni exécutée ; aucun développement.** Décisions : [DM-065](decisions/metier/DM-065-r5-cloture-forcee-exceptionnelle-par-cws-depuis-sonata.md), [DM-074](decisions/metier/DM-074-p4-habilitations-cws-superadmin-conserve-deux-permissions-de.md), [DM-092](decisions/metier/DM-092-cloture-globale-automatique-toutes-les-participations-ouvert.md), [DM-005](decisions/metier/DM-005-les-droits-cws-ne-donnent-pas-acces-aux-operations-communale.md), [DM-139](decisions/metier/DM-139-cloture-exceptionnelle-cws-permission-persistee.md) (option A : permission persistée).

## 1. Besoin
Un super-administrateur CWS **explicitement habilité** peut clore un événement dont l'organisatrice ne le fait pas (injoignable, administrateur parti), avec : **ressaisie du mot de passe**, **motif obligatoire**, **confirmation** de l'opération, **audit complet**, et des **conséquences cohérentes** sur les autorisations et le contrôle terrain. La permission `CWS_FORCE_CLOSE_EVENT` existe déjà (`CwsPermission`, DM-074) mais **n'est stockée nulle part** : le code la refuse par défaut.

## 2. Schéma proposé : `identity_cws_permission`

| Colonne | Type | Null | Rôle |
|---|---|---|---|
| `id` | `BINARY(16)` | non | PK, UUID v7 |
| `user_id` | `BINARY(16)` | non | FK `identity_user(id)` `RESTRICT` : le super-administrateur habilité |
| `permission` | `VARCHAR(30)` | non | valeur de `CwsPermission` (`CWS_FORCE_CLOSE_EVENT`, `CWS_IMPERSONATE`) |
| `granted_by` | `BINARY(16)` | non | FK `identity_user(id)` `RESTRICT` : **autre** super-administrateur |
| `granted_at` | `DATETIME` | non | |
| `reason` | `VARCHAR(200)` | non | motif de l'attribution (texte court, sans donnée personnelle) |
| `revoked_at` | `DATETIME` | oui | |
| `revoked_by` | `BINARY(16)` | oui | FK `identity_user(id)` `RESTRICT` |
| `active_key` | `BINARY(16)` | oui | égal à `user_id` tant que la permission est active, **nul** une fois révoquée (MySQL n'a pas d'index unique partiel ; même technique que `active_permit_id` des jetons) |

**Index** : `UNIQUE uniq_cws_permission_active (active_key, permission)` (une seule attribution active par compte et permission ; plusieurs lignes révoquées conservées comme historique) ; `idx_cws_permission_user (user_id)` ; index automatiques des clés étrangères.

**Invariants (domaine, testés)** : le bénéficiaire est un **super-administrateur actif** ; `granted_by` ≠ `user_id` (**aucune auto-attribution**) et est lui-même super-administrateur actif ; `revoked_*` renseignés ensemble et `active_key` nul à la révocation ; **aucun droit communal** n'est créé (DM-005, DM-121) ; la permission n'est jamais accordée « à tous » par défaut.

## 3. Attribution et révocation
Par **commandes console** (`app:cws:permission:grant|revoke|list`), exécutées par un opérateur de l'exploitation, **sans écran Sonata** en V1 (surface minimale ; un écran relèvera du lot d'administration CWS). L'opérateur désigne le super-administrateur **attribuant** (sinon refus) ; audit `CWS_PERMISSION_GRANTED` / `CWS_PERMISSION_REVOKED` (identifiants, permission, motif ; jamais de secret). Un super-administrateur désactivé ou révoqué perd **immédiatement** la fonction (la permission est relue à chaque exécution, sous verrou).

## 4. Fonction de clôture exceptionnelle (comportement)
1. **Accès** : page CWS dédiée (`/admin/cws/evenements/…`, mécanisme d'actions nommées existant, DT-052), **authentification complète**, super-administrateur actif **et** permission active relue en base.
2. **Affichage avant exécution** (DM-065) : événement (référence, nom, organisatrice, statut), **participations encore ouvertes** avec leur statut, nombre de demandes et de laissez-passer **sans aucune donnée personnelle** (DM-005), conséquences annoncées.
3. **Garde-fous** : ressaisie du **mot de passe** du super-administrateur (comparaison au condensat ; limiteur et journal d'échecs sans mot de passe) ; **motif obligatoire** d'au moins une phrase (borné) ; **référence de l'événement saisie** en confirmation ; jeton CSRF propre à l'événement ; POST uniquement.
4. **Exécution** : **même moteur** que la clôture normale (`EventLifecycleService::close`, DM-092) : verrouillage de l'événement puis des participations dans l'ordre DT-024, clôture de l'événement et de **toutes** les participations ouvertes dans la **même transaction**, statistiques de clôture, révocation des invitations, neutralisation des notifications de laissez-passer (DM-120), `batch_id` commun, **échec = rien n'est modifié**. Seule différence : l'acteur est un **opérateur CWS** (`AuditActor::support`), avec **justification** et **permission**, jamais un rôle communal.
5. **Audit complet** : opérateur réel, motif, participations clôturées avec leur **statut précédent**, compte des demandes et laissez-passer concernés ; **jamais** le mot de passe ni une donnée personnelle.
6. **Notification** (recommandation, à confirmer) : message aux **administrateurs communaux** de l'organisatrice et des participantes (« l'événement X a été clos par l'éditeur le … »), **sans le motif détaillé**.

## 5. Conséquences sur les autorisations et le contrôle terrain
| Domaine | Effet à la clôture exceptionnelle |
|---|---|
| Laissez-passer | état calculé `EVENT_CLOSED` : plus aucun droit (immédiat) |
| Contrôle **en ligne** | « Événement terminé » seul, sans donnée personnelle (DM-115, DM-120) |
| Contrôle **hors connexion** (LOT 09) | à la synchronisation suivante, jeu **vide marqué clos** : l'appareil supprime ses données ; **avant** elle, l'appareil reste borné par sa durée de confiance (**≤ 12 h au pilote**) : *risque R-OFF-01 s'applique* ; la désactivation des appareils de l'événement n'est **pas** automatique (inutile : le jeu vide suffit) |
| Appareils et codes d'activation | conservés comme historique jusqu'à la purge de l'événement ; aucun nouveau code ne peut être créé (événement clos) |
| Liens bénéficiaires | fermés à la clôture (DM-120) |
| Invitations intercommunales | révoquées ; réinvitation interdite (DM-089) |
| Demandes publiques | formulaire fermé |
| Archives, statistiques, conservation | cycle de vie normal de DM-120 |

## 6. Migration envisagée (à approuver avant exécution)
Création de **une table** `identity_cws_permission` (ajout seul). **Données existantes** : aucune permission n'est attribuée (la fonction reste indisponible jusqu'à une attribution explicite). **Retour arrière** : suppression de la table. **Intégration** : `AccountReferenceCatalog` (**RETAINED** : `user_id`, `granted_by`, `revoked_by`), `TestDatabase`, `CwsPermission` relue depuis la base par le port de vérification existant. **Risques** : faibles ; sauvegarde locale vérifiée avant exécution ; vérification des clés étrangères, de l'index unique et du schéma ; test de concurrence (deux attributions simultanées : une seule active).

## 7. Tests prévus
Attribution refusée : auto-attribution, bénéficiaire non super-administrateur ou désactivé, attributeur non habilité ; permission active unique (concurrence) ; révocation immédiate ; clôture refusée sans permission, avec permission révoquée, avec mauvais mot de passe, sans motif, avec mauvaise référence, par un administrateur communal (même organisateur), par un super-administrateur sans permission ; clôture réussie : toutes les participations clôturées, statistiques, invitations révoquées, notifications neutralisées, audit complet avec statuts précédents et sans donnée personnelle, **atomicité** (panne injectée = rien de modifié) ; événement déjà clos (refus) ; contrôle en ligne « terminé », jeu hors connexion vide marqué clos ; **aucun accès aux dossiers** par CWS (DM-005).
