# Plan de recette SMTP réel — e-mails, authentification et délivrabilité

**Statut : plan préparé, NON exécuté.** Aucun e-mail réel n'est envoyé par ce document, aucun DNS n'est modifié, aucun serveur n'est créé. L'exécution a lieu sur le **serveur de recette HTTPS**, avec des **boîtes de test** et des **données fictives**, sur autorisation explicite.

Ce plan **complète** et ne duplique pas :
- [exploitation-notifications.md](../exploitation-notifications.md) : chemin d'un message, états de l'outbox, §4 transport SMTP (classement des erreurs, réessais 1/5/15/60/240 min), §5 lancement manuel, §7 supervision, §10 diagnostic, §11 remise manuelle, §12 reprise après panne, §13 points ouverts (n° 4, 5 et 8 sont clos par ce plan).
- [runbooks/information-des-refus.md](../runbooks/information-des-refus.md) et [runbooks/incident-notification.md](../runbooks/incident-notification.md).
- [lot-10-plan-recette.md](../lot-10-plan-recette.md) : R-NOT-01 à R-NOT-08, recettes conditionnelles « MailCatcher » (les 6 tests sont rejoués en local ; ici l'équivalent sur **SMTP réel**).
- [lot-10-textes-a-valider.md](../lot-10-textes-a-valider.md) (T3 e-mail d'invitation : date et heure d'expiration réelles).
- [lot-10-protocole-09-7.md](../lot-10-protocole-09-7.md) I7 : « SMTP de recette ».

## 0. Règles

1. **Aucune donnée réelle** : adresses de test sous contrôle de l'équipe, noms et plaques fictifs. Aucun envoi vers un tiers.
2. `REFUSAL_NOTICE_ENABLED` **reste à 0** tant que le libellé juridique (DM-136, T4) n'est pas validé : les scénarios « refus » sont **conditionnels** (§5, famille R). Avec l'interrupteur à 0, le seul résultat attendu est « aucun envoi ».
3. Aucun envoi massif : volumes de test bornés (§7). Respecter la politique d'usage du fournisseur SMTP.
4. Aucun secret (mot de passe SMTP, clés DKIM privées, `NOTIFICATION_KEYRING`) dans les preuves, tickets ou captures. Les preuves d'en-têtes sont **expurgées** (adresses de test conservées, jetons/liens masqués sauf vérification de forme).
5. `SENT` = « remis au serveur de messagerie », **jamais** « reçu » ; la réception se vérifie **dans la boîte**.
6. Un message portant une donnée sensible (voir §6, C-SENS) interrompt la recette et est consigné comme anomalie **Bloquante**.

## 1. Prérequis

| # | Prérequis | Vérification |
|---|---|---|
| S1 | Fournisseur SMTP choisi (décision D10 ouverte), identifiants de **recette** distincts de ceux de la production | décision écrite |
| S2 | Domaine d'expédition de **recette** (ex. sous-domaine) avec accès DNS pour SPF, DKIM, DMARC | `dig NS`, accès au gestionnaire DNS |
| S3 | `MAILER_DSN` (TLS exigé), `MAILER_FROM` (remplace `no-reply@civipass.invalid`), `DEFAULT_URI` en HTTPS, `OPS_ALERT_EMAIL` posés dans `/etc/civipass/civipass.env` (non versionné) | `ops/nginx/bin/check-deployment.sh` ; `bin/console debug:config framework mailer` (sans afficher le DSN dans la preuve) |
| S4 | Trousseau de chiffrement valide | `bin/console app:notifications:check-crypto` → code 0 |
| S5 | Distributeur planifié (minuteur `civipass-notifications-dispatch`) **ou** exécution manuelle de `app:notifications:dispatch` pendant les essais | `systemctl list-timers`, `app:ops:status` |
| S6 | Boîtes de test : Gmail, Outlook/Microsoft 365, une boîte « domaine propre » (accès aux en-têtes bruts), une adresse **inexistante** sur un domaine réel de test, une boîte **pleine** si possible | liste consignée |
| S7 | Jeu de données fictives (événement, communes A/B, laissez-passer, demandes) et comptes de test ; `PUBLIC_VERIFICATION_ENABLED` selon besoin du plan 09.7 | [lot-10-jeux-de-donnees.md](../lot-10-jeux-de-donnees.md) |
| S8 | Outils : `dig`, `openssl s_client -starttls smtp`, accès aux journaux de l'application (codes seulement) | — |
| S9 | Un outil d'analyse de délivrabilité **peut** être utilisé en complément (adresse d'analyse jetable) ; **il n'est pas une dépendance** : la recette reste réussie avec les seuls en-têtes relevés | décision du responsable de recette |

## 2. Rôles

| Rôle | Tâches |
|---|---|
| Administrateur système | DNS, SMTP, variables d'environnement, minuteurs, journaux |
| Testeur | Déclenche les parcours applicatifs, relève les messages et en-têtes dans les boîtes |
| Responsable de recette | Consigne les preuves, tranche les gravités, décide de l'activation du refus (non, tant que T4 non validé) |
| Juriste / métier | Valide le libellé du refus (hors recette technique) |

## 3. Données de test

Comptes : administrateur communal fictif (adresse de test), agent invité (adresse de test), contact de commune invitée (adresse de test), bénéficiaire fictif (Gmail / Outlook / domaine propre), demandeur fictif. Laissez-passer : un valide avec adresse, un sans adresse, un pour chaque opération prioritaire (révocation, raccourcissement, retrait de zone, retrait de plaque, remplacement du QR). Adresses en erreur : adresse syntaxiquement invalide, adresse inexistante sur domaine réel, domaine sans MX, boîte pleine. Aucune adresse réelle d'un agent ou citoyen.

## 4. Convention

- **Priorité** : P0 = condition du pilote ; P1 = avant pilote ; P2 = amélioration.
- **Gravité** d'un échec : **Bloquante** (aucun message reçu, lien inutilisable, donnée sensible, usurpation possible) · **Majeure** (message en spam, authentification partielle, reprise défaillante) · **Mineure** (texte, mise en forme).
- **Preuve** : en-têtes bruts expurgés, capture de la boîte, sortie de commande (compteurs seulement), extrait d'audit. Nommage : `SMTP-<ID>-<n>`.

## 5. Scénarios

### Famille A — Messages applicatifs (contenu, lien, expiration)

| ID | Pr. | Étapes | Attendu | Preuve |
|---|---|---|---|---|
| **A1 — Invitation d'un agent** | P0 | 1. Administrateur communal invite un agent (adresse de test). 2. `app:notifications:dispatch` si besoin (ou attendre le minuteur). 3. Ouvrir le message dans Gmail et Outlook. 4. Suivre le lien jusqu'à l'activation du compte. | Message reçu < 5 min, expéditeur `MAILER_FROM`, gabarit aux couleurs du design system (HTML + alternative texte), lien en **HTTPS** sur le domaine de recette (`DEFAULT_URI`), lien à usage unique, validité annoncée cohérente avec la durée réelle (7 jours pour l’invitation d’administrateur, à confirmer pour les agents) ; activation réussie ; un second clic sur le lien est refusé. | En-têtes + captures + capture de l'activation |
| **A1b — Lien invitation expiré** | P1 | Utiliser un lien dont l'échéance est dépassée (recette avec durée raccourcie ou horloge applicative de test). | Refus clair sans fuite d'information ; demande d'un nouveau lien possible. | Capture |
| **A2 — Invitation d'une commune à un événement** | P0 | 1. Commune organisatrice invite la commune B (contact + administrateur : deux messages, gabarits `event_invitation` et `event_invitation_administrator`). 2. Lire les deux messages. 3. Suivre le lien. | Deux messages distincts ; l'heure d'expiration **affichée correspond à l'expiration réelle** du lien (jour et heure de Paris, T3) ; texte « l'invitation reste en attente jusqu'à la clôture… » ; lien fonctionnel jusqu'à l'échéance ; après échéance, message de refus du lien et possibilité de nouveau lien ; l'invitation, elle, reste en attente. | En-têtes, captures, comparaison heure affichée/heure réelle (base) |
| **A3 — Accusé de réception de la demande** | P1 | Déposer une demande publique avec e-mail (adresse de test). | Accusé reçu (`request_receipt`), référence, aucune pièce jointe ni motif ; aucun jeton secret. | Capture |
| **A4 — Réinitialisation du mot de passe** | P1 | Demande de réinitialisation sur un compte existant puis sur un compte inexistant. | Message reçu pour le compte existant ; **aucun message ni réponse distincte** pour l'inexistant (pas d'énumération) ; lien à usage unique ; message de confirmation `password_changed` après changement ; lien expiré refusé. | Captures, comparaison des réponses HTTP (temps et corps) |
| **A5 — Transmission d'un laissez-passer : lien** | P0 | 1. Approuver une demande dont le demandeur a une adresse (bénéficiaire Gmail). 2. Distributeur. 3. Ouvrir le message `permit_issued` : lien personnel, QR/PDF selon le gabarit. 4. Ouvrir le lien sur téléphone. | Message reçu ; lien en HTTPS ; page bénéficiaire affichée sans compte, sans cookie ; aucune donnée plus étendue que le nécessaire ; état de l'outbox `SENT` ; le secret chiffré est purgé (`app:notifications:purge-secrets`, contrôle `status`). | En-têtes, captures, sortie `status` |
| **A6 — Transmission : PDF** | P0 | Depuis le lien, générer le PDF (« photographie des droits à l'instant »). Imprimer ou afficher le QR. | PDF généré à la demande, jamais stocké ; QR lisible ; consigne « seul le contrôle du QR code fait foi ». Si le PDF est joint à un message : poids raisonnable, type MIME correct. | PDF (fictif), capture |
| **A7 — Laissez-passer sans adresse** | P1 | Approuver une demande sans e-mail. | Ligne `NO_RECIPIENT`, écran agent invitant à la remise manuelle ; aucun envoi. Remise manuelle : lien affiché une seule fois. | Capture |
| **A8 — Notifications essentielles (prioritaires)** | P0 | Pour chaque opération : révocation d'autorisation, raccourcissement, retrait de zone, retrait de plaque, remplacement du QR : exécuter, distribuer, lire. | Un message par opération, disponible aussitôt (pas de regroupement), sujet explicite, **aucun motif ni commentaire interne**, aucun lien secret quand non nécessaire ; le message `qr_replaced` dit que les anciens QR/PDF ne fonctionnent plus. | Un jeu de captures par type |
| **A9 — Notifications ordinaires regroupées** | P1 | Prolongation, ajout de zone, ajout de plaque en rafale. | Un message regroupé par bénéficiaire, ordonné ; messages redondants `SUPPRESSED` (audit `NOTIFICATION_SUPPRESSED`). | Capture + `status` |
| **A10 — Clôture d'événement** | P1 | Clôturer l'événement avec des messages en attente. | Messages en attente `SUPPRESSED`/`EVENT_CLOSED`, secrets purgés ; aucun envoi tardif. | `status`, capture |
| **A11 — Messages système et alertes d'exploitation** | P1 | Provoquer un échec d'unité (`civipass-failure@`) ; vérifier l'alerte à `OPS_ALERT_EMAIL`. | Alerte reçue (nom d'unité, hôte, date ; **aucune donnée personnelle**). | En-têtes + capture |

### Famille R — Refus (conditionnelle : texte juridique non validé)

| ID | Pr. | Étapes | Attendu | Preuve |
|---|---|---|---|---|
| **R0 — Interrupteur à 0** | P0 | `REFUSAL_NOTICE_ENABLED=0` ; refuser une demande avec adresse. | **Aucun envoi** ; ligne de suivi `PENDING` ; apparition dans « Refus à communiquer » si applicable. | `status`, capture |
| **R1 — Envoi (SEULEMENT après validation écrite de T4)** | P1 | Interrupteur à 1 sur **ce serveur de recette**, accord horodaté du responsable du projet ; refuser une demande (adresse de test). | Message minimal : référence, événement, commune, motif du référentiel **sauf « doublon » et « autre »**, contact de la mairie ; voies de recours conformes au texte validé ; aucune donnée sensible (pas de pièces, pas de notes privées). | Capture, texte validé joint |
| **R2 — Échec** | P1 | Provoquer un échec SMTP sur un refus. | Réessais 1/5/15/60/240 min ; abandon après 6 essais ou 24 h ; apparition dans « Refus à communiquer » ; marquage manuel « informé ». | `status`, capture |
| **R3 — Sans adresse** | P1 | Refus d'une demande sans e-mail. | « À informer autrement » ; saisie manuelle du canal. | Capture |
| **R4 — Remise à 0 après recette** | P0 | Replacer l'interrupteur à 0. | Plus aucun envoi. | Capture config |

Tant que T4 n'est pas validé : **R1 et R2 sont « Non exécutés (texte provisoire) »**, R0, R3, R4 sont exécutés.

### Famille V — Authentification de l'expéditeur (SPF, DKIM, DMARC)

À exécuter sur le **domaine d'expédition de recette**, puis à **rejouer** sur le domaine définitif avant le pilote.

| ID | Pr. | Étapes / commandes | Attendu | Preuve |
|---|---|---|---|---|
| **V1 — SPF** | P0 | `dig +short TXT <domaine>` ; vérifier un seul enregistrement `v=spf1` incluant le fournisseur et se terminant par `-all` (ou `~all` en recette documentée). Message de test → en-tête `Authentication-Results: spf=pass`. | `spf=pass`, un seul enregistrement SPF, pas plus de 10 recherches DNS. | Sortie `dig` + en-tête |
| **V2 — DKIM** | P0 | `dig +short TXT <sélecteur>._domainkey.<domaine>` ; message de test → en-tête `DKIM-Signature` (`d=`, `s=`) et `dkim=pass`. | `dkim=pass`, domaine signataire = domaine d'expédition, clé ≥ 2048 bits. | Sortie `dig` + en-tête (sans clé privée) |
| **V3 — DMARC** | P0 | `dig +short TXT _dmarc.<domaine>` ; politique de départ `p=none` avec `rua=` (boîte de rapports) en recette, cible `p=quarantine` puis `p=reject` avant/après pilote (décision) ; en-tête `dmarc=pass`. | `dmarc=pass` ; enregistrement présent ; boîte de rapports contrôlée. | Sortie `dig` + en-tête |
| **V4 — Alignement** | P0 | Comparer `From:` (`MAILER_FROM`), `Return-Path`/enveloppe (SPF) et `d=` (DKIM). | Au moins SPF **ou** DKIM aligné avec le domaine du `From` (idéalement les deux) ; `dmarc=pass` en conséquence. | En-têtes comparés |
| **V5 — Enregistrement inverse et en-têtes** | P2 | Si relais propre : PTR/HELO cohérents ; `Message-ID` stable, `Date`, `MIME-Version`, alternative texte présente. | Conforme ; `Message-ID` identique lors d'un réessai (garantie « au moins une fois »). | En-têtes |
| **V6 — Domaine du lien** | P0 | Les liens des messages utilisent `DEFAULT_URI` (HTTPS), jamais l'en-tête `Host` : essayer de déclencher un message avec un `Host` forgé. | Lien toujours sur le domaine configuré. | Message reçu |

### Famille T — Transport et sécurité de la liaison

| ID | Pr. | Étapes / commandes | Attendu | Preuve |
|---|---|---|---|---|
| **T1 — TLS vers le relais** | P0 | `openssl s_client -starttls smtp -connect <relais>:587 -servername <relais>` (ou 465 en TLS implicite) ; vérifier version ≥ TLS 1.2 et chaîne valide ; le DSN impose le chiffrement. | TLS négocié, certificat valide, **pas de repli en clair**. | Sortie expurgée |
| **T2 — Authentification refusée (défaut de configuration)** | P1 | Mot de passe SMTP volontairement faux (sur le serveur de recette). | Erreur classée **réessayable** (530/534/535/538 : défaut de configuration, pas d'abandon définitif) ; alerte visible dans `status` (code 1/2) ; aucun secret dans les journaux. | `status`, extrait de journal |
| **T3 — Journaux** | P0 | Parcourir journaux applicatifs, Nginx et systemd après les scénarios A. | Aucune adresse, aucun jeton, aucun secret, aucun corps de message (codes et compteurs seulement). | Recherche `grep` documentée (motifs, pas de contenu) |
| **T4 — Expéditeur et domaine d'envoi** | P0 | Vérifier que `MAILER_FROM` n'est plus `civipass.invalid`. | Adresse réelle du domaine de recette ; réponses (`Reply-To`) conformes à la décision. | Config (sans secret) |

### Famille E — Erreurs d'envoi, reprise, limitation

| ID | Pr. | Étapes | Attendu | Preuve |
|---|---|---|---|---|
| **E1 — Adresse invalide** | P1 | Bénéficiaire avec adresse non conforme. | `ADDRESS_INVALID`, jamais réessayée, signalée à l'agent ; correction puis renvoi manuel. | `status`, capture |
| **E2 — Adresse inexistante (rebond)** | P1 | Bénéficiaire avec adresse inexistante sur domaine réel de test. | Rejet immédiat (5xx) → `SMTP_PERMANENT` abandon ; ou acceptation puis rebond **asynchrone** : l'état reste `SENT` (limite connue, « envoyé ≠ reçu ») ; **relever le message de rebond** reçu à l'adresse d'enveloppe et décider du traitement (consigner). | En-têtes du rebond, `status` |
| **E3 — Boîte pleine / refus temporaire** | P2 | Si disponible. | Classé temporaire, réessais puis abandon et renvoi manuel. | `status` |
| **E4 — Panne SMTP puis reprise** | P0 | 1. Couper l'accès au relais (règle de pare-feu de recette ou DSN invalide temporaire). 2. Provoquer 3 messages dont un prioritaire. 3. Observer les réessais (1/5/15/60/240 min). 4. Rétablir le relais. 5. `app:notifications:dispatch`. | Réessais conformes ; **même `Message-ID` et même lien** à la reprise, aucun doublon de lien ; `status` passe de 2/1 à 0 ; au-delà de 6 essais ou 24 h : `ABANDONED` + renvoi manuel après rétablissement (« Renvoyer par e-mail », audité). | `status` avant/après, en-têtes (Message-ID) |
| **E5 — Arrêt brutal pendant un envoi** | P2 | Tuer le distributeur pendant un lot. | Message `RESERVED` repris à l'expiration du bail (10 min) ; doublon possible (même Message-ID), jamais de nouveau lien. | `status` |
| **E6 — Distributeur arrêté** | P1 | Arrêter le minuteur 15 min. | Alerte de supervision (`status` code 2 : message échu depuis plus de 900 s) ; reprise au redémarrage. | `status`, `app:ops:status` |
| **E7 — Limitation de débit du fournisseur** | P1 | Envoyer un lot de test borné (§7) et relever le comportement face aux limites du fournisseur (4xx « trop de messages »). | Classé temporaire ; pas de perte ; pas de boucle d'envoi ; débit du distributeur (jusqu'à 100 messages/50 s) compatible avec les quotas du fournisseur (sinon : `--limit` à ajuster dans l'unité). | Sortie compteurs |
| **E8 — Renvoi manuel : limites** | P2 | Deux renvois à moins de 5 min ; un message de mise à disposition déjà en attente. | Refus conforme (limites 5 min, un seul en attente). | Capture |

### Famille D — Délivrabilité et contenu

| ID | Pr. | Étapes | Attendu | Preuve |
|---|---|---|---|---|
| **D1 — Gmail** | P0 | Envoyer A1, A2, A5, A8 vers Gmail ; relever dossier (principal/spam/promotions), « Afficher l'original » : SPF/DKIM/DMARC. | Boîte de réception, authentification `PASS`, pas d'avertissement de sécurité ni de lien « suspect ». | Captures + « original » expurgé |
| **D2 — Outlook / Microsoft 365** | P0 | Idem vers Outlook.com et M365 ; en-têtes `Authentication-Results`. | Boîte de réception ou, au pire, « Courrier indésirable » **consigné** (Majeure) ; authentification `pass`. | Captures + en-têtes |
| **D3 — Boîte de domaine propre** | P1 | Idem vers une boîte du domaine de recette. | Réception, en-têtes complets lisibles. | En-têtes |
| **D4 — Note de réputation** | P2 | Optionnel : outil d'analyse de délivrabilité (adresse d'analyse jetable) ; liste noire du relais (consultation passive). | Note consignée à titre indicatif ; **non bloquant** (pas de dépendance). | Sortie ou « non utilisé » |
| **D5 — Clients de messagerie** | P1 | Afficher HTML et texte sur Gmail mobile, Outlook mobile, Apple Mail ; vue « sans images ». | Lisible, liens cliquables, bouton principal visible, alternative texte lisible ; thème sombre acceptable. | Captures |
| **D6 — Sujets et expéditeur** | P1 | Relire sujets et nom d'expéditeur (« Civipass ») ; absence de mots à risque (spam) ; absence de pièces jointes inutiles. | Sujets explicites, sans donnée personnelle. | Liste des sujets |
| **D7 — Désabonnement / nature du message** | P2 | Messages transactionnels : vérifier la mention de contact et l'absence de contenu promotionnel. | Conforme à la nature transactionnelle (décision juridique associée). | Relecture |

### Famille C — Contenu sans donnée sensible

| ID | Pr. | Étapes | Attendu | Preuve |
|---|---|---|---|---|
| **C-SENS-1 — Messages prioritaires** | P0 | Relire chaque message de la famille A8. | **Aucun** motif de révocation, commentaire interne, note privée, pièce, ni lien secret superflu ; plaques et noms **uniquement** là où le gabarit le prévoit et seulement pour le bénéficiaire concerné. | Relecture signée |
| **C-SENS-2 — Aucune donnée dans l'objet ni les en-têtes** | P0 | Parcourir sujets et en-têtes (`X-*`, `Reply-To`, `List-*`). | Aucun nom, plaque, jeton, référence interne exploitable. | En-têtes |
| **C-SENS-3 — Liens à durée limitée** | P0 | Pour chaque type de lien (invitation agent ou administrateur (7 j selon l’audit, à confirmer), invitation de commune 14 j, réinitialisation, lien bénéficiaire) : relever l'échéance **annoncée dans le message** et l'échéance **réelle** (audit/base) ; tester après échéance. | Concordance annoncée/réelle ; refus après échéance ; aucun lien réutilisable après usage (invitations). | Tableau annoncé/réelle |
| **C-SENS-4 — Pas de suivi ni de ressource externe** | P0 | Inspecter le HTML : pixels espions, images distantes, scripts. | Aucune ressource tierce ni pixel de suivi ; images internes seulement. | Source HTML |
| **C-SENS-5 — Purge** | P1 | `app:notifications:purge-secrets` puis `status` ; `purge-outbox` (provisoire 90 j) en simulation. | Secrets supprimés ≤ 48 h ; aucune ligne en attente purgée. | Compteurs |

## 6. Ordre d'exécution recommandé

1. S1–S9, `check-crypto`, `status` au repos (état « OK »).
2. V1–V6 (authentification), T1, T4.
3. A1, A2, A4, A5, A6, A8 (P0) vers Gmail et Outlook (D1, D2).
4. E4 (panne et reprise), E1, E2.
5. C-SENS-1 à 4, T3 (journaux).
6. A3, A7, A9–A11, E5–E8, D3–D7, R0, R3, R4 (P1/P2).
7. R1, R2 **si et seulement si** T4 validé ; sinon marquer « Non exécuté (texte provisoire) ».

## 7. Volumétrie de test

Au plus **100 messages** au total par campagne, répartis sur les boîtes de test ; un lot de 20 messages en rafale pour E7. Aucun envoi à un tiers.

## 8. Critères de réussite

- **Réussite** : tous les scénarios **P0** réussis (A1, A2, A5, A6, A8, V1–V4, V6, T1, T3, T4, E4, D1, D2, C-SENS-1 à 4, R0, R4) ; `dmarc=pass` ; aucun message prioritaire en spam sur Gmail ni Outlook ; reprise après panne sans nouveau lien ; aucune anomalie **Bloquante** ouverte ; **Majeures** documentées avec plan de correction.
- **Échec de la recette** : message jamais reçu, lien inutilisable ou HTTP, donnée sensible dans un message, authentification absente, doublon de lien.
- **Hors périmètre technique** : validation juridique du libellé du refus, des durées de conservation de l'outbox (90 jours) et de la notice de confidentialité ; choix définitif du fournisseur.
- Les anomalies sont consignées dans le registre des anomalies (preuve, gravité) ; les corrections font l'objet de sous-lots séparés.

## 9. Fiche de résultats (vierge)

| Champ | À remplir |
|---|---|
| Date, lieu, testeur, responsable de recette | |
| Version déployée (hash Git), serveur | |
| Fournisseur SMTP, port, mode TLS, domaine d'expédition | |
| Enregistrements DNS relevés (SPF / DKIM sélecteur / DMARC) | |
| `MAILER_FROM` utilisé, `OPS_ALERT_EMAIL` | |
| Boîtes de test (Gmail / Outlook / propre) | |
| État de `REFUSAL_NOTICE_ENABLED` | |

| ID | Scénario | Résultat (Réussi / Échoué / Non exécuté) | Preuve (fichier) | Gravité si échec | Anomalie liée | Remarques |
|---|---|---|---|---|---|---|
| A1 | Invitation d'un agent | | | | | |
| A1b | Lien expiré | | | | | |
| A2 | Invitation d'une commune | | | | | |
| A3 | Accusé de réception | | | | | |
| A4 | Réinitialisation du mot de passe | | | | | |
| A5 | Laissez-passer : lien | | | | | |
| A6 | Laissez-passer : PDF | | | | | |
| A7 | Sans adresse | | | | | |
| A8 | Notifications prioritaires | | | | | |
| A9 | Notifications regroupées | | | | | |
| A10 | Clôture d'événement | | | | | |
| A11 | Alerte d'exploitation | | | | | |
| R0 | Refus : interrupteur à 0 | | | | | |
| R1 | Refus : envoi (conditionnel) | | | | | |
| R2 | Refus : échec (conditionnel) | | | | | |
| R3 | Refus : sans adresse | | | | | |
| R4 | Refus : retour à 0 | | | | | |
| V1 | SPF | | | | | |
| V2 | DKIM | | | | | |
| V3 | DMARC | | | | | |
| V4 | Alignement | | | | | |
| V5 | En-têtes / PTR | | | | | |
| V6 | Domaine du lien | | | | | |
| T1 | TLS | | | | | |
| T2 | Authentification refusée | | | | | |
| T3 | Journaux | | | | | |
| T4 | Expéditeur | | | | | |
| E1 | Adresse invalide | | | | | |
| E2 | Adresse inexistante / rebond | | | | | |
| E3 | Boîte pleine | | | | | |
| E4 | Panne et reprise | | | | | |
| E5 | Arrêt brutal | | | | | |
| E6 | Distributeur arrêté | | | | | |
| E7 | Limitation de débit | | | | | |
| E8 | Renvoi manuel : limites | | | | | |
| D1 | Gmail | | | | | |
| D2 | Outlook / M365 | | | | | |
| D3 | Boîte propre | | | | | |
| D4 | Réputation (optionnel) | | | | | |
| D5 | Clients de messagerie | | | | | |
| D6 | Sujets et expéditeur | | | | | |
| D7 | Nature transactionnelle | | | | | |
| C-SENS-1..5 | Contenu sans donnée sensible | | | | | |

**Signatures** : testeur ______ · administrateur système ______ · responsable de recette ______ · date ______.

## 10. Fin de recette

Remise de `REFUSAL_NOTICE_ENABLED` à 0 si activé, suppression des comptes et messages fictifs, révocation des identifiants SMTP de recette, conservation des enregistrements DNS de recette ou retrait selon décision, mise à jour de [exploitation-notifications.md](../exploitation-notifications.md) §13 (points 4, 5, 8) et de [lot-10-registre-recette.md](../lot-10-registre-recette.md) (R-NOT-01 à R-NOT-07).
