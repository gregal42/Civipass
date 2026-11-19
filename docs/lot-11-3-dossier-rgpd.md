# LOT 11.3 — Dossier RGPD technique (projet pour validation DPO)

> **PROJET TECHNIQUE — pas une validation juridique.** Ce dossier décrit ce que **fait réellement le code** au 19 novembre 2026 (schéma Doctrine, services de purge, configuration, scripts d'exploitation). Les qualifications (responsable, sous-traitant, bases légales), les durées et les décisions d'acceptation de risque restent **à valider par le responsable de traitement, le DPO ou un juriste**. Aucune durée n'est « validée » ici : toutes sont des hypothèses ou des valeurs techniques provisoires.
>
> Nature : constats de lecture (schéma, services, configuration, scripts). **Aucune modification du code ni de la configuration.** Rien n'a été exécuté contre une base de production ; aucun test nouveau.
>
> Sources réutilisées sans les dupliquer : [politique de conservation](rgpd-politique-conservation.md) (registre T1 à T12, cycle de vie, droits), [documentation destinée aux collectivités](rgpd-documentation-collectivites.md), [AIPD technique du contrôle hors connexion](aipd-controle-hors-connexion-technique.md), cadrages [05.6](lot-05-6-cadrage.md) à [05.6.7](lot-05-6-7-cadrage.md), [écarts connus](ecarts-techniques-connus.md), [registre de sécurité](registre-securite.md), runbooks ([incident-securite](runbooks/incident-securite.md), [restauration](runbooks/restauration.md), [sauvegarde](runbooks/sauvegarde.md), [violation-de-donnees](runbooks/violation-de-donnees.md)).

**Méthode de vérification.** Les tables viennent des entités `src/*/Domain` (`#[ORM\Table]`, `#[ORM\Column]`, 50 tables). Les mécanismes de suppression viennent des `DELETE FROM` réels de `src/Retention/Application/{DossierPurgeService,SharedPurgeService,EventDeletionService}.php`, de `src/Notification/Application/OutboxPurger.php` et des commandes `app:*-purge`. Une donnée est dite « sans purge constatée » quand aucun `DELETE` ne la vise dans `src` (recherche textuelle ; à confirmer par un test dédié en 11.1).

Légende criticité : **Élevée** (exposition de données identifiantes ou obstacle probable à un pilote réel) · **Moyenne** · **Basse** · **Info**.

---

## 1. Inventaire des données personnelles : champ → table → finalité → base → durée → destinataires → purge réelle

**Base légale** : pour toutes les lignes de gestion des laissez-passer, la piste est « mission d'intérêt public » (art. 6.1.e) de la commune, **non retenue** ; sécurité : « obligation de sécurité » (art. 32) / intérêt légitime, **à confirmer**. **Durées** : valeurs **provisoires** du code ou de la politique, jamais validées. « Dossier » = dossier communal (`request_permit_request_municipality`).

### 1.1 Demandeurs et bénéficiaires (T1 à T5)

| Donnée | Table.colonne | Finalité | Durée (hypothèse) | Destinataires | Purge réelle (vérifiée) |
|---|---|---|---|---|---|
| Nom, prénom | `request_permit_request.last_name`, `first_name` | Instruire et décider | Clôture + 12 mois (`ARCHIVE_RETENTION_MONTHS`) | Agents de la commune concernée selon rôle ; public via QR (nom, prénom) | `SharedPurgeService` : `request_permit_request` supprimée quand plus aucun dossier ne la justifie |
| Adresse, e-mail, téléphone | `request_permit_request.address`, `email`, `phone` | Instruire, informer le bénéficiaire | idem | idem ; e-mail : prestataire de messagerie | idem |
| Catégorie, motif, texte libre | `category_label`, `motif_label`, `motif_detail` (**texte libre**) | Motiver la demande | idem | idem | idem |
| Mode de déplacement, attestation d'information | `travel_mode`, `privacy_attested_at`, `privacy_notice_version` | Preuve d'information | idem | idem | idem |
| Plaques déclarées | `request_permit_request_vehicle.registration` | Identifier les véhicules | idem | idem | `SharedPurgeService` |
| Zones demandées (libellé) | `request_permit_request_zone.zone_label` | Périmètre de la demande | idem | Commune | `DossierPurgeService` (par dossier) |
| Justificatifs (nom d'origine, type, taille, clé de stockage ; **fichier dans `var/storage`**) | `request_permit_request_attachment.original_name`, `mime_type`, `size_bytes`, `storage_key` | Appuyer l'instruction | idem ; **contenu libre potentiellement sensible** | Agents concernés ; téléchargements journalisés | `SharedPurgeService` supprime ligne **et fichier** (compteur `filesDeleted`) ; temporaires non rattachés : `app:request-uploads:purge` à 24 h |
| Décision, motif de refus (**texte libre** `refusal_comment`) | `request_permit_request_decision.*` | Motiver | Dossier | Commune décisionnaire | `DossierPurgeService` |
| Suivi d'information du refus | `request_refusal_notice.*` (aucune donnée directe ; référence la décision) | Informer le demandeur | Dossier | Agents | `DossierPurgeService` (avant la décision, FK RESTRICT) |
| Clé d'idempotence | `request_idempotency_key.key_hash` (haché) | Anti-doublon technique | Court | — | `SharedPurgeService` ; supprimée à la clôture selon la politique §3 (**à vérifier**, voir F13) |
| Mentions de rectification, demandes de droits | `archive_rectification_mention`, `rights_request`, `rights_request_dossier` | Droits des personnes | Dossier | Administrateur communal | `DossierPurgeService` (mentions, dossier de droits) ; `rights_request` : **sans purge constatée** (voir F12) |

### 1.2 Laissez-passer, QR, véhicules, notifications (T2, T3, T6)

| Donnée | Table.colonne | Finalité | Durée | Destinataires | Purge réelle |
|---|---|---|---|---|---|
| Référence du laissez-passer | `permit_permit.reference` | Identifier | Dossier / demande | Agents, public | `SharedPurgeService` |
| Jeton de contrôle public (`/v/{jeton}`) | `permit_verification_token.token` (**en clair**, collation binaire) | Contrôle sans compte | Jusqu'à retrait ou purge | Quiconque détient le QR | `SharedPurgeService` ; **non haché** (F06) |
| Secret du lien bénéficiaire | `permit_access_token.secret_hash` (haché), `issued_by_*` | Accès du bénéficiaire | Jusqu'à clôture | Bénéficiaire | `SharedPurgeService` |
| Plaques autorisées, historique | `permit_vehicle.registration`, `permit_revision_vehicle.registration` | Contrôle, historique des droits | Dossier | Agents, public (plaques valides) | `SharedPurgeService` / `DossierPurgeService` |
| Autorisations, périodes, zones | `permit_authorization*`, `permit_authorization_zone.zone_label` | Droits par commune | Dossier | Agents, public (zones, validité) | `DossierPurgeService` |
| Révisions (motif libre `reason_text`, états avant/après JSON) | `permit_revision.*` | Traçabilité des modifications de droits | Dossier | Commune | `DossierPurgeService` ; **texte libre** (F07) |
| Messages sortants (type, identifiants, erreur) | `notification_outbox.*` (sans adresse : l'adresse est relue depuis la demande) | Informer | **90 j** après traitement, **constante de code** `OutboxPurger::DEFAULT_RETENTION_DAYS` | Prestataire SMTP | `app:notifications:purge-outbox` (planifiable) ; `DossierPurgeService` |
| Secrets de lien chiffrés | `notification_secret.ciphertext`, `nonce`, `key_id` | Envoi du lien | **48 h** (provisoire) | — | `app:notifications:purge-secrets` ; `DossierPurgeService` |

### 1.3 Événements, communes, comptes (T7, T10)

| Donnée | Table.colonne | Finalité | Durée | Destinataires | Purge réelle |
|---|---|---|---|---|---|
| Événement : nom, description, **consignes opérationnelles** (texte libre), motif de suspension | `event_event.name`, `description`, `operational_instructions`, `suspension_reason` | Gestion de l'événement | Jusqu'à suppression explicite de l'événement | Communes participantes | `EventDeletionService` (suppression par un administrateur, événement purgé ou sans donnée personnelle) |
| Statistiques de clôture | `event_event.closure_statistics` (JSON, seuil `CLOSURE_STATISTICS_MIN_COUNT=5`) | Bilan | Avec l'événement | Organisateur | `EventDeletionService` |
| Participations (message d'invitation libre, motif de suspension) | `event_participation.invitation_message`, `suspension_reason` | Coordination intercommunale | Avec l'événement | Communes | `EventDeletionService` |
| Compte agent : e-mail, nom, prénom, empreinte du mot de passe, dernière connexion | `identity_user.email`, `first_name`, `last_name`, `password`, `last_login_at` | Authentifier | Durée de la relation (hypothèse : 24 mois d'inactivité) | CWS, administrateurs communaux, Sonata (lecture seule) | **Aucune exécution** : `AccountCandidateReader` produit un rapport de candidats en **lecture seule** (F02) |
| Appartenances, rôles | `municipality_membership.roles`, `active` | Droits | Relation | Administrateurs | Désactivation, pas de suppression |
| Habilitations CWS (motif libre) | `identity_cws_permission.reason` | Habilitations exceptionnelles | Sans durée | CWS | Sans purge constatée |
| Commune : contact (nom, e-mail, téléphone), adresse | `municipality.contact_*`, `address_line`, `postal_code`, `city` | Relation commerciale | Hors politique événementielle (T10) | CWS | Sans purge constatée ; durée à définir |
| Accès commerciaux (motif libre) | `municipality_commercial_access.reason` | Droit d'utilisation | idem | CWS | idem |
| Invitations : e-mail destinataire, empreinte du jeton | `onboarding_invitation_link.recipient_email`, `municipality_admin_invitation.recipient_email` | Inviter un contact ou un administrateur | Liens : 3 j (administrateur), 14 j (contact) ; **ligne conservée** | CWS, communes | Liens liés à un événement : `EventDeletionService` ; **invitations d'administrateur : sans purge constatée** (F04) |
| Réinitialisation du mot de passe | `identity_password_reset_request.token_hash`, `expires_at`, `used_at` | Récupération d'accès | Lien court ; **ligne conservée** | — | **Sans purge constatée** (F05) |

### 1.4 Contrôle, archives, traçabilité (T8, T9)

| Donnée | Table.colonne | Finalité | Durée | Destinataires | Purge réelle |
|---|---|---|---|---|---|
| Appareil de contrôle : libellé libre, plateforme, code de couplage, empreinte du secret, dernière synchro | `control_device.label`, `platform`, `pairing_code`, `secret_hash`, `last_sync_at` | Contrôle hors connexion | Jusqu'à expiration/désactivation ; événement | Commune activatrice | `EventDeletionService` ; **le libellé peut contenir un nom de personne** (F07) |
| Activation (code haché) | `control_activation.code_hash`, … | Autoriser des appareils | Court | Commune | `EventDeletionService` |
| Données **sur l'appareil** (nom, prénom, plaques, zones, fenêtres) | IndexedDB du navigateur (hors base) | Contrôle sans réseau | ≤ 12 h de confiance (pilote), suppression à l'ouverture | Contrôleur | Voir [AIPD hors connexion](aipd-controle-hors-connexion-technique.md) §5 |
| Archive : consultations (motif libre), désignations, gels (commentaire libre) | `archive_consultation`, `archive_manager_designation`, `archive_legal_hold` | Preuve, contestation | Gel revu tous les 6 mois | Administrateur / organisateur désigné | `DossierPurgeService` (gels, consultations) ; désignations quand plus aucun dossier |
| Journal d'audit (acteur, objet, valeurs **structurées sans valeur personnelle**, DT-037) | `audit_log.actor_id`, `subject_id`, `new_value`, `previous_value` | Imputabilité | Lié à l'objet ; **pas de durée générale** | CWS, administrateurs habilités | `DELETE` ciblés : `PERMIT_REQUEST`, `PERMIT`, `LEGAL_HOLD`, `ARCHIVE_DESIGNATION`, `EVENT` ; **autres sujets (utilisateur, commune, habilitation, …) conservés sans limite** (F03) |
| Registre de purge | `retention_purge_record` + fichier `purge-register.jsonl`, `anchors.jsonl` | Preuve de purge (sans donnée personnelle) | Illimitée par conception | Exploitation | Non purgé (volontaire) |
| Sessions | Fichiers PHP (`session.gc_maxlifetime=10800` proposé) ; brouillon public : `public_request.<réf>` | Authentification, saisie en cours | Inactivité agents 30 min, CWS 15 min ; brouillon anonyme 30 min | — | `app:request-drafts:purge` ; ramasse-miettes PHP (voir [M6](lot-10-m6-sessions-php.md)) |

### 1.5 Données hors base

| Donnée | Emplacement | Durée | Mécanisme |
|---|---|---|---|
| Journaux d'accès Nginx (adresses IP ; jeton masqué après `/v/`) | `/var/log/civipass/` | **30 j** (`ACCESS_LOG_RETENTION_DAYS`), erreurs **168 h** | Rotation horaire du kit `ops/nginx/` (à contrôler sur serveur réel) |
| Journaux applicatifs Symfony / PHP-FPM | À définir à l'installation | **Non défini** | `SecretScrubbingLogger` masque les jetons ; durée à fixer (F09) |
| Sauvegardes (base, `var/storage`, copies du registre) | `BACKUP_DIR` | **35 j** (`BACKUP_RETENTION_DAYS`, hypothèse A1) | `ops/backup/backup.sh` ; **ni chiffrées ni externalisées** dans le script (F01) |
| E-mails sortants | Prestataire SMTP | Selon prestataire | Sous-traitant ultérieur à lister (D8) |

---

## 2. Constats d'écart entre la politique et le code

Chaque constat : criticité, preuves (fichiers lus), correction **recommandée** (**aucune appliquée** ; toute correction passe par un sous-lot cadré).

| ID | Criticité | Constat | Preuves | Correction recommandée |
|---|---|---|---|---|
| **F01** | **Élevée** | Les sauvegardes contiennent toutes les données identifiantes **et les justificatifs** ; le script ne les chiffre pas et ne les copie pas hors de la machine (`mysqldump` suivi de `gzip`, `tar -czf`, répertoire local). Le jeton QR en clair (F06) s'y trouve aussi. Une perte de disque ou un accès au répertoire exposerait tous les dossiers non encore purgés (jusqu'à 35 j de purges). | `ops/backup/backup.sh` l. 33-38 (aucun `gpg`, `age`, `openssl`, `rclone`, `scp`) ; [runbooks/sauvegarde.md](runbooks/sauvegarde.md) | Chiffrement des sauvegardes (clé hors serveur) et copie externalisée vers un stockage UE, **avant toute donnée réelle**. À traiter dans le kit d'installation (préparation en cours). |
| **F02** | Moyenne | Aucun mécanisme exécutable de suppression ou d'anonymisation des comptes agents inactifs ; seul un rapport de candidats existe. `ACCOUNT_INACTIVITY_MONTHS=24` n'est lu que par ce rapport. Un compte reste donc indéfiniment (e-mail, nom, prénom, dernière connexion). | `src/Identity/Application/AccountCandidateReader.php` (lecture seule) ; seule occurrence de `ACCOUNT_INACTIVITY` | Décision de durée (DPO) ; en V1, procédure manuelle documentée (revue annuelle du rapport + désactivation), exécution automatisée en sous-lot ultérieur. |
| **F03** | Moyenne | Le journal d'audit n'a pas de durée générale : seuls les sujets liés aux demandes, laissez-passer, gels, désignations et événements sont supprimés. Les audits sur les utilisateurs, communes et habilitations sont conservés sans limite. Valeurs structurées sans texte libre (DT-037) donc risque faible, mais les identifiants d'acteur restent des données pseudonymes. | `EventDeletionService` l. 99-126 ; `DossierPurgeService` (`audit_log*`) ; DT-037 | Fixer une durée de conservation de l'audit (DPO) ; vérifier en 11.1 par test que les valeurs d'audit ne contiennent ni nom, e-mail, plaque ni texte libre. |
| **F04** | Moyenne | `municipality_admin_invitation.recipient_email` et les invitations de type `ADMINISTRATOR` ne sont supprimées par aucun `DELETE` constaté ; les lignes consommées ou expirées conservent l'e-mail. | grep `DELETE FROM` dans `src` : seul `onboarding_invitation_link` (événements) est visé | Purge des invitations expirées/consommées après une durée à fixer ; correction en sous-lot. |
| **F05** | Basse | Les demandes de réinitialisation expirées ou utilisées ne sont pas purgées (empreinte du jeton, lien vers le compte, dates). | `PasswordResetService` : aucun `DELETE` ; table `identity_password_reset_request` | Purge périodique (par exemple 30 j après expiration). |
| **F06** | Moyenne | Le jeton de contrôle public `permit_verification_token.token` est stocké **en clair**, alors que les autres secrets (accès bénéficiaire, appareils, activations, réinitialisations) sont hachés. Une fuite de base ou de sauvegarde (F01) donne des QR valides révélant nom, prénom et plaques. | `src/Permit/Domain/VerificationToken.php` l. 33-34 (`string $token`, collation binaire) vs `PermitAccessToken::$secretHash` | Décision d'architecture : hachage avec recherche par empreinte (compatible avec le contrôle hors connexion ?) ou acceptation documentée. À arbitrer par l'équipe technique et le commanditaire, pas par ce dossier. |
| **F07** | Moyenne | Plusieurs textes libres (motif, commentaire de refus, motif de révision, message d'invitation, consignes d'événement, libellé d'appareil, commentaire de gel) peuvent contenir des données inattendues, y compris sensibles. Les premiers sont purgés avec le dossier ; ceux de l'événement ou de l'appareil durent jusqu'à la suppression de l'événement. | `request_permit_request.motif_detail`, `request_permit_request_decision.refusal_comment`, `permit_revision.reason_text`, `event_event.operational_instructions`, `control_device.label` | Consigne aux agents (aucune donnée de santé ni nominative dans les champs libres), limitation de longueur ; mention dans la notice. |
| **F08** | Moyenne | **Toutes** les durées sont des hypothèses non validées (voir §3). L'outbox est fixée à 90 j par une **constante de code**, alors que la politique impose des durées configurables (§4.2.2). | `OutboxPurger.php` l. 32 ; `config/services.yaml` l. 55-80 | Validation DPO, puis rendre l'outbox paramétrable (correction mineure, P2). |
| **F09** | Moyenne | Pas de durée définie pour les journaux applicatifs (Symfony, PHP-FPM, systemd) ; seuls les journaux Nginx ont une rétention (30 j / 168 h). | `ops/nginx/civipass.env.example` l. 27-28 | Fixer et documenter dans le guide d'exploitation ; à vérifier sur serveur réel (11.4). |
| **F10** | Moyenne | `RETENTION_PURGE_ENABLED=0` et `RIGHTS_ERASURE_EXECUTION_ENABLED=0` : aucune purge ne s'exécute tant qu'ils valent 0. Conforme à la règle 8 et au choix de prudence, mais en pilote réel les données s'accumuleront au-delà des durées annoncées. | `config/services.yaml` l. 78-79 | Décision d'activation conditionnée à la validation des durées, à un essai de simulation sur le serveur de recette et à la vérification du rejeu après restauration. |
| **F11** | Moyenne | Les justificatifs sont écrits dans `var/storage` ; aucun chiffrement applicatif au repos n'est constaté (non vérifié de façon exhaustive). | `config/services.yaml` l. 21 ; `request_permit_request_attachment.storage_key` | Chiffrement du disque par l'hébergeur ou du volume (décision D8) ; permissions 0700 propriétaire `civipass` (kit). |
| **F12** | Basse | `rights_request` (demandes d'exercice de droits) : seul `rights_request_dossier` est supprimé avec le dossier ; la demande elle-même n'a pas de purge constatée (attendu : preuve de traitement, durée à fixer). | `DossierPurgeService` (`rights_request_dossier`) | Durée de conservation de la preuve (DPO). |
| **F13** | Basse | La politique annonce la suppression des clés d'idempotence à la clôture ; le seul `DELETE` constaté est dans la purge partagée. | `SharedPurgeService` l. 98 ; politique §3 | Confirmer ou corriger la formulation de la politique (vérification en 11.1). |
| **F14** | Basse | Cookie technique du dépôt public (`PHPSESSID`, session) : information livrée (texte validé) mais qualification juridique non validée ; brouillon effacé après 30 min côté serveur, nettoyage PHP dépendant du serveur cible. | DT-058 ; [lot-10-m6-sessions-php.md](lot-10-m6-sessions-php.md) | Qualification par le juriste (D5) ; contrôle du nettoyage sur le serveur cible. |
| **F15** | Info | La procédure de violation de données n'existait pas (`incident-securite.md` la renvoyait au responsable de traitement). | `docs/runbooks/incident-securite.md` §Décisions | Livrée dans ce lot : [violation-de-donnees.md](runbooks/violation-de-donnees.md), à valider. |

**Ce que le code fait bien, constaté** (preuves de conformité aux principes) : purge par service explicite, simulation par défaut, verrous, bordereau sans donnée personnelle, registre chaîné et ancré hors base ; suppression des fichiers avec les lignes ; secrets hachés ou chiffrés ; audit sans valeur personnelle (DT-037) ; cloisonnement par commune dans les requêtes de purge (`municipality_id = :m`).

---

## 3. Durées de conservation actuellement en place (toutes provisoires)

| Paramètre | Valeur | Où | Nature | Statut |
|---|---|---|---|---|
| Archive après clôture | 12 mois | `ARCHIVE_RETENTION_MONTHS` | hypothèse | À valider (DPO, archives) |
| Préavis avant purge | 30 j | `RETENTION_NOTICE_DAYS` | hypothèse | idem |
| Revue d'un gel | 6 mois | `ARCHIVE_HOLD_REVIEW_MONTHS` | hypothèse | idem |
| Lien de consultation d'archive | 30 min | `ARCHIVE_CONSULTATION_MINUTES` | technique | — |
| Dormance d'un événement | 90 j, rappel 30 j | `EVENT_DORMANCY_DAYS`, `EVENT_DORMANCY_REMINDER_DAYS` | hypothèse | idem |
| Inactivité d'un compte | 24 mois | `ACCOUNT_INACTIVITY_MONTHS` | hypothèse, rapport seulement | idem (F02) |
| Réponse aux droits / prolongation | 30 j / 60 j | `RIGHTS_RESPONSE_DAYS`, `RIGHTS_EXTENSION_DAYS` | délai légal à confirmer | juridique |
| Export de droits | 30 j, 3 téléchargements | `RIGHTS_EXPORT_*` | technique | — |
| Seuil statistique | 5 | `CLOSURE_STATISTICS_MIN_COUNT` | provisoire | DPO |
| Outbox | 90 j | constante `OutboxPurger` | provisoire | DPO (F08) |
| Secrets de lien | 48 h | code | provisoire | DPO |
| Justificatifs temporaires | 24 h | `app:request-uploads:purge` | technique | — |
| Brouillon public | 30 min | `app:request-drafts:purge` | technique | juridique (F14) |
| Sessions | inactivité 30 min (agents), 15 min (CWS), durée absolue 12 h / 8 h | `SessionLifetime` | technique | politique de sécurité |
| Sauvegardes | 35 j | `BACKUP_RETENTION_DAYS` | hypothèse A1 | exploitation + DPO |
| Journaux Nginx | 30 j / 168 h | `civipass.env.example` | hypothèse | DPO |
| Confiance hors connexion | avertissement 6 h, seuil dur 12 h | cadrage 09 | décision pilote | R-OFF-01 |

---

## 4. Politique de purge : ce qui est livré, ce qui manque

- **Livré** : purge par dossier communal (`app:retention:purge`, simulation par défaut, `--execute` refusé si `RETENTION_PURGE_ENABLED=0`), purge partagée quand plus aucun dossier ne justifie la demande, suppression d'événement explicite, préavis (`app:retention:notice`, non activé), gels contentieux, rejeu après restauration, registre chaîné et ancré, contrôle de cohérence ([runbooks/restauration.md](runbooks/restauration.md), [runbooks/coherence.md](runbooks/coherence.md)).
- **Manque (F02 à F05, F12)** : comptes agents, invitations d'administrateur, demandes de réinitialisation, demandes de droits, audit hors événement, contacts de commune. Ces catégories sont **hors politique événementielle** (T7, T10) : leur règle de conservation est à décider (décisions 4 et 5).
- **Sauvegardes** : rotation de 35 j **et** rejeu obligatoire après restauration (A1, [runbooks/restauration.md](runbooks/restauration.md)).

---

## 5. AIPD technique consolidée (projet)

> Projet technique pour le DPO. Le **seuil de nécessité d'une AIPD** et la qualification des risques relèvent du responsable de traitement et du DPO. L'AIPD détaillée du contrôle hors connexion est dans [aipd-controle-hors-connexion-technique.md](aipd-controle-hors-connexion-technique.md) (données, mesures, annexes A et B, modèle d'acceptation du risque R-OFF-01) ; elle n'est pas répétée ici.

**Traitement.** Plateforme multi-communes de laissez-passer en situation de crise : dépôt public sans compte, instruction par des agents, délivrance d'un QR, contrôle public ou hors connexion, archivage et purge. Données : identité, contact, plaques, zones, justificatifs libres, historique des droits.

**Facteurs rendant l'AIPD plausible (à qualifier par le DPO)** : traitement de crise à l'échelle de plusieurs communes ; consultation publique d'un nom et de plaques par simple détention du QR (DM-115) ; justificatifs et textes libres pouvant révéler des données sensibles ; données conservées sur des téléphones de contrôle ; personnes potentiellement vulnérables (sinistrés, riverains).

**Nécessité et proportionnalité (éléments techniques).** Minimisation : pas de compte citoyen, pas de journal de scan (DM-127), aucune donnée personnelle dans le QR, audit sans valeur personnelle, pas de cartographie. Limitation : cloisonnement par commune, archive restreinte à l'administrateur et à l'organisateur désigné, purge par dossier. Transparence : notice par collectivité (brouillon), information sur le cookie.

**Risques pour les personnes (analyse de travail) et mesures.**

| # | Risque | Gravité × vraisemblance (à confirmer) | Mesures existantes | Mesures manquantes ou à prouver | Lien |
|---|---|---|---|---|---|
| R1 | Accès à un dossier d'une autre commune | Élevée × Faible | Filtrage de périmètre serveur, voters, matrice testée (1 853 tests), identifiants forgés | Rejouer en 11.1 et sur le serveur | Registre sécurité |
| R2 | Détention frauduleuse d'un QR (copie, capture) | Moyenne × Moyenne | Rappel de rapprochement (DM-125), remplacement du QR (DM-113), limitation de débit | Information des bénéficiaires | `ecarts-techniques-connus.md` |
| R3 | Fuite de la base ou des sauvegardes | Élevée × Faible | Secrets hachés, chiffrement des secrets de lien, droits 0600/0700 | **F01 chiffrement et externalisation, F06 jeton en clair, F11 stockage** | F01, F06, F11 |
| R4 | Données sur un téléphone perdu ou volé | Élevée × Moyenne | Durée de confiance 12 h, suppression à l'ouverture, désactivation à distance à la reconnexion | Acceptation écrite de R-OFF-01, consigne de terrain, recette 09.7 | AIPD hors connexion |
| R5 | Conservation excessive | Moyenne × Moyenne | Purge, registre, gels | **Durées à valider, F02 à F05, F10 (purge inactive)** | §2 |
| R6 | Données inattendues dans les textes libres | Moyenne × Moyenne | Audit sans texte libre | Consigne, limite de longueur (F07) | F07 |
| R7 | Compromission d'un compte agent | Élevée × Moyenne | Limiteur de connexion, expiration de session, SEC-001 corrigée | Politique de mot de passe et 2FA à trancher (S3) | Registre sécurité |
| R8 | Résurgence de données purgées après restauration | Moyenne × Faible | Registre hors base, rejeu obligatoire, contrôle de restauration | Essai de restauration réelle (11.4) | runbooks |
| R9 | Divulgation par e-mail (erreur de destinataire, boîte compromise) | Moyenne × Moyenne | Messages sans donnée sensible, liens à durée limitée | Essai SMTP réel, SPF/DKIM/DMARC | plan SMTP |
| R10 | Cookie ou brouillon lisible sur un navigateur partagé | Faible × Moyenne | Effacement à 30 min, bouton « Effacer ma saisie » | Qualification juridique, nettoyage de session sur serveur | F14 |

**Conclusion technique provisoire.** Aucune faille bloquante du modèle d'accès n'est connue. Trois obstacles à un usage réel : **F01** (sauvegardes), **F10/F08** (durées et activation de la purge) et la **recette sur appareils et serveur réels**. La conclusion de l'AIPD (acceptable, à traiter ou à soumettre à la CNIL) appartient au DPO.

---

## 6. Droits des personnes et information (état technique)

Livrés au LOT 05.6.6 : accès (export borné au dossier de la commune), rectification par mention tracée en archive, effacement anticipé **désactivé** par défaut (`RIGHTS_ERASURE_EXECUTION_ENABLED=0`), suivi des délais (`app:rights:overdue-report`). Les procédures et textes d'information sont à valider ([documentation collectivités](rgpd-documentation-collectivites.md), §8 « à compléter par la collectivité »). Aucun exercice de droit n'est possible par le contrôle public ; le citoyen passe par la commune.

---

## 7. Décisions nécessitant validation juridique ou DPO

| # | Qui décide | Question | Recommandation technique | Impact si non tranché |
|---|---|---|---|---|
| 1 | Communes, juriste | Qualification : chaque commune responsable de son dossier, ou **responsabilité conjointe** sur les données partagées (art. 26) ? | Prévoir une convention type d'invitation intercommunale mentionnant la répartition ; le code cloisonne déjà par dossier | Notices et AIPD ne peuvent être finalisées |
| 2 | CWS, communes, juriste | CWS **sous-traitant** (art. 28) : contrat et clauses ; responsable pour ses données propres (T7, T10) | Contrat avec délai de notification de violation, localisation UE, sous-traitants ultérieurs listés | Pas de traitement de données réelles |
| 3 | DPO, juriste | **Bases légales** de chaque traitement T1 à T12 | Piste mission d'intérêt public pour les communes ; contrat / intérêt légitime pour CWS | Notices non rédigeables |
| 4 | DPO, service d'archives | **Durées** : archive 12 mois, préavis 30 j, gel 6 mois, dormance 90 j, outbox 90 j, secrets 48 h, sauvegardes 35 j, journaux 30 j / 168 h ; sort final après archive (élimination ou versement) | Valider ou corriger chaque durée ; conserver les valeurs en configuration | **La purge reste désactivée (F10)** ; accumulation de données |
| 5 | DPO | Règles pour les catégories hors politique événementielle : comptes inactifs (24 mois ?), invitations, réinitialisations, audit hors événement, demandes de droits, contacts de commune | 24 mois d'inactivité ; invitations et réinitialisations purgées 30 j après expiration ; audit à durée fixe (par exemple 5 ans) ; preuve de droits à durée alignée sur la prescription | F02 à F05, F12 restent ouverts |
| 6 | Commanditaire, DPO | **Activation de la purge** (`RETENTION_PURGE_ENABLED`) et de l'effacement anticipé, après simulation sur le serveur de recette | Simulation, puis exécution sur données fictives, rejeu après restauration, puis activation par décision écrite | Aucune élimination en pilote |
| 7 | Juriste | **Cookie du dépôt public** (strictement nécessaire ?) et publication du texte (D5) | Texte variante B déjà intégré ; confirmer la dispense de consentement | Risque de non-conformité d'information |
| 8 | Juriste, commune | **Message de refus** et voies de recours (D7) ; envoi réel (`REFUSAL_NOTICE_ENABLED`) | Valider le libellé ; envoi activé seulement après | Les refus ne sont pas communiqués ; blocage fonctionnel du pilote |
| 9 | Commanditaire, DPO | **Acceptation écrite de R-OFF-01** et durée de confiance (6 h / 12 h) ; consigne de terrain | Utiliser le modèle de l'annexe A de l'AIPD hors connexion | Contrôle hors connexion non utilisable avec données réelles |
| 10 | Commanditaire, hébergeur | **Hébergeur UE**, chiffrement du disque, stockage externalisé des sauvegardes, sous-traitants ultérieurs (SMTP) (D8) | Hébergeur UE, disque chiffré, sauvegardes chiffrées hors serveur | F01 et F11 non résolus |
| 11 | Équipe technique, commanditaire | **Jeton de contrôle en clair** (F06) : hachage ou acceptation documentée | Arbitrage avec le contrôle hors connexion ; en attendant, sauvegardes chiffrées | Une fuite de base révèle des QR valides |
| 12 | DPO, communes | **Personne chargée des droits** dans chaque commune, délai d'un mois, vérification d'identité | Désigner un point de contact par commune à l'ouverture | Droits sans interlocuteur |
| 13 | Responsable, DPO | **Violation de données** : validation du runbook, délai contractuel CWS → commune, registre des violations, qui notifie la CNIL | Adopter [violation-de-donnees.md](runbooks/violation-de-donnees.md) | Procédure non applicable en pilote |
| 14 | Commanditaire | **AIPD** : seuil de nécessité, conclusion, consultation préalable éventuelle de la CNIL | Faire valider l'AIPD (cette §5 + l'AIPD hors connexion) avant tout pilote | Pas de pilote |
| 15 | Commanditaire | **Audit externe** (interne seul ou test d'intrusion) | Externe, après installation de recette | Risque résiduel non borné |
| 16 | Commanditaire | **Politique de mot de passe et 2FA** (S3), au moins pour CWS | 2FA pour CWS et administrateurs communaux | Risque R7 |

---

## 8. Prochaines étapes (hors de ce dossier)

1. 11.1 : tests automatisés des constats F03, F04, F05, F13 (présence ou absence de purge, contenu de l'audit).
2. Kit d'installation : chiffrement et externalisation des sauvegardes (F01), droits des répertoires (F11), journaux (F09).
3. Corrections F02 à F08 en **sous-lots séparés**, après validation du commanditaire (P1 : F01 ; P2 : les autres, sauf décision contraire).
4. Soumission du dossier au DPO ; mise à jour de ce document à chaque décision.
