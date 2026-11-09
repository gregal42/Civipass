# DT-049 — Outbox transactionnelle et distributeur périodique des notifications

- **Identifiant** : DT-049
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Modification** : arbitrages du LOT 05.3 (D1 à D14, 9 novembre 2026), ci-dessous « Révision du LOT 05.3 ». Elle remplace le point 2 (jeton créé à l'envoi) et complète les points 1 et 3.
- **Décisions remplacées** : Aucune.

## Contexte

DM-117 ; `DeferredMailQueue` est en mémoire et non transactionnel ; Messenger n'a que le transport `sync` ; aucun worker ni cron dans Docker.

## Décision

1. **Outbox maison** : une ligne `notification_outbox` écrite **dans la transaction métier** (annulée avec elle), clé de dédoublonnage unique (`PERMIT_ISSUED:{laissez-passer}`, `AUTHORIZATION_ADDED:{autorisation}`…), états (en attente, réservée, envoyée, abandonnée, sans destinataire).
2. **Distributeur** : commande Symfony périodique, hors transaction métier ; réservation par bail pour éviter deux traitements simultanés ; nouvelles tentatives espacées ; abandon après N essais ; erreurs observables (sans destinataire ni jeton). Adresse relue au moment de l'envoi ; jeton bénéficiaire créé à l'envoi (plusieurs liens actifs tolérés). Livraison au moins une fois ; un doublon résiduel (arrêt entre l'acceptation SMTP et le marquage) est accepté et documenté.
3. **Pas de dépendance Messenger Doctrine** (aucun bénéfice démontré). Toute modification Docker ou de l'exécution (processus périodique) est présentée et validée avant application.
4. Implémentation : **LOT 05.3** ; rien n'est créé au 05.1.

## Révision du LOT 05.3 (arbitrages du 9 novembre 2026)

1. **Secret bénéficiaire (D1, remplace « jeton créé à l'envoi »)** : le lien est créé **à l'enregistrement** par le système, son empreinte SHA-256 va dans `PermitAccessToken` ; le secret n'est conservé que **chiffré** (libsodium XChaCha20-Poly1305, nonce aléatoire, données associées liant message, type et laissez-passer) dans une **table séparée** de l'outbox, réutilisé à chaque tentative, supprimé physiquement après traitement définitif et au plus tard 48 h après. Jamais en clair (journaux, audits, exceptions, suivi). Mise en œuvre : 05.3.2.
2. **Liens (D2, D9)** : seul le premier message contient le lien ; les suivants n'en créent pas. Pas de plaque, d'adresse postale, de justificatif ni de motif détaillé ; pas de pixel de suivi.
3. **Clés (D3)** : fournies par l'environnement, trousseau identifié, procédure de rotation, vérification explicite avant activation. **Fail-closed validé le 9 novembre 2026** : une approbation n'est jamais bloquée ; sans clé valide, aucun lien automatique, aucun clair, la ligne reste `PENDING` avec `CRYPTO_UNAVAILABLE`, aucune tentative SMTP consommée, anomalie signalée, remise manuelle possible ; le plafond SMTP de 24 h ne s'applique pas pendant l'anomalie, mais l'attente est limitée à **7 jours à compter de la création de l'intention** (échec définitif `CRYPTO_UNAVAILABLE_EXPIRED`, aucun lien) ; à la reprise, le laissez-passer doit être encore utilisable, sinon échec définitif `NOT_RELEVANT` (aucune annonce devenue fausse).
4. **Exécution (D4)** : `app:notifications:dispatch` par cron hôte chaque minute ; ni scheduler Symfony, ni worker Messenger ; aucune modification d'infrastructure.
5. **Regroupement (D5)** : silence 10 min, attente maximale 60 min, première approbation sans délai, révocation prioritaire, message de commune supplémentaire supprimé s'il est redondant. Au 05.3.1 : `SchedulePolicy` pose la date de première disponibilité (0 ou 10 min).
6. **Réessais (D6)** : 1, 5, 15, 60, 240 min ; abandon après six tentatives ou 24 h ; erreurs permanentes distinguées ; au moins une fois, jamais un nouveau lien.
7. **Absence d'adresse (D8)** : état `NO_RECIPIENT` posé **à l'enregistrement**, définitif ; une adresse corrigée plus tard ne déclenche aucun envoi ; la remise manuelle reste possible.
8. **Architecture (D11)** : module `src/Notification` ; port `NotificationIntentRecorder` possédé par Permit, implémenté par Notification ; écriture dans la transaction métier, aucun SMTP dans cette transaction.
9. **Émetteur système (D12)** : `PermitAccessToken.issuer_type` (`AGENT` | `SYSTEM`) ; un lien système n'a ni agent ni commune émettrice, une révocation sans auteur est une révocation système ; audit avec acteur `system` (livré au 05.3.2, migration `Version20261109201436`).
10. **Conservation (D13, provisoire, soumise à validation RGPD)** : secret chiffré supprimé après traitement, 48 h maximum ; métadonnées d'outbox 90 jours, sans contenu ni adresse ; purge automatisable et testée.
11. **Supervision (D14)** : `app:notifications:status` (volumes par état, échecs, ancienneté, seuils configurables, code de sortie non nul, aucune donnée personnelle).
12. **Suspension et clôture anticipée (D7)** : non développées ; proposition spécifique à fournir.

## Conséquences fonctionnelles ou techniques

- **LOT 05.3.1 (livré)** : table `notification_outbox` (migration `Version20261109194302`), états `PENDING`, `RESERVED`, `SENT`, `ABANDONED`, `NO_RECIPIENT`, clés de dédoublonnage `PERMIT_ISSUED:{laissez-passer}` et `AUTHORIZATION_ADDED:{autorisation}` (`UNIQUE`). Identifiants et dates seulement : jamais d'adresse, de nom, de plaque, de jeton ni de contenu. Aucun envoi, aucune commande, aucun chiffrement.
- **LOT 05.3.2 (livré, migration non appliquée sur `civipass`)** : table `notification_secret` (secret chiffré XChaCha20-Poly1305, `UNIQUE(outbox_id)` et `UNIQUE(access_token_id)`, échéance 48 h), trousseau `NOTIFICATION_KEYRING` / `NOTIFICATION_ACTIVE_KEY_ID` (environnement seulement), `SecretCipher`, `SecretProvisioner` (lien système + secret + état dans une transaction, sous le verrou de la ligne d'outbox), `SecretVault`, `SecretPurger`, commandes `app:notifications:check-crypto` et `app:notifications:purge-secrets`. Données associées : version | message | type | laissez-passer. Aucun distributeur, aucun SMTP.
- **LOT 05.3.3 (livré, migration `Version20261109203506` non appliquée sur `civipass`)** : `NotificationDispatcher` et commande `app:notifications:dispatch` (un passage, prévu chaque minute par le cron de l'hôte, **non configuré**), supervision `app:notifications:status` (codes de sortie 0 / 1 / 2), réservation sous verrou de ligne avec bail de 10 minutes, réessais 1, 5, 15, 60, 240 minutes, abandon à six tentatives ou 24 heures après la première tentative, erreurs permanentes abandonnées sans réessai, Message-ID stable (dérivé de l'identifiant du message), état courant relu avant chaque envoi, envoi hors de toute transaction et sans verrou, purge des secrets intégrée à chaque passage. Garantie : au moins une fois ; un arrêt après l'acceptation SMTP peut doubler le message (même Message-ID, même lien), jamais créer un nouveau lien (`UNIQUE(outbox_id)`, et un réessai ne provisionne jamais).
- **LOT 05.3.4 (livré, migration `Version20261109213016` non appliquée sur `civipass`)** : ordre des messages d'un même laissez-passer (`NotificationOrdering`), état définitif `SUPPRESSED` avec motif (`ALREADY_INCLUDED`, `FIRST_NOT_DELIVERED`, `INACCURATE`), regroupement déterministe des changements ordinaires à l'enregistrement (`SchedulePolicy::groupAvailableAt`), structure réutilisable pour le LOT 05.5 (`ChangeCategory`, types de message sans producteur ni gabarit), suivi agent (`AgentNotificationStatus`, port `NotificationStatusProvider`) et envoi manuel (`PermitAccessService::requestNotification`, port `ManualNotificationRequester`). Colonne `covered_authorizations` : preuve de redondance, identifiants seulement.
- **LOT 05.3.5 (livré, aucune migration)** : anti-abus du renvoi manuel (`ManualRequestPolicy` : un message en attente, 5 min, 3 par 24 h), purge de l'outbox à 90 jours (`app:notifications:purge-outbox`, durée provisoire), correction du bail (horloge réelle), recette interactive, [`exploitation-notifications.md`](../../exploitation-notifications.md).
- Le rattrapage `app:permit:backfill` n'enregistre aucune notification (`PermitIssuer::issue(..., notify: false)`).

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
