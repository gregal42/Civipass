# LOT 08.5 — Notification des refus : schéma proposé (non approuvé)

**Date** : 17 novembre 2026 · **Statut** : **proposition de schéma à approuver ; aucune migration créée ni exécutée ; aucun développement.** Décisions : [DM-136](decisions/metier/DM-136-notification-des-refus-obligatoire.md) (notification obligatoire, voie A : asynchrone fiable), amendement de [DM-110](decisions/metier/DM-110-motifs-de-refus.md).

## 1. Besoin
Quand une commune **refuse** sa part d'une demande, le demandeur doit en être informé : e-mail si une adresse existe, sinon situation **visible des agents** avec procédure alternative (appel, guichet, courrier), **sans SMS en V1**. Envoi **asynchrone, fiable** : réessais, suivi des échecs, jamais de perte silencieuse, jamais de blocage de la décision (même principe que DT-049).

Contraintes : le commentaire libre d'un refus reste interne (DM-110) ; aucun lien, aucun QR, aucune donnée de laissez-passer ; l'adresse e-mail est **non vérifiée** (tout contenu est exposé à une personne non vérifiée) ; **ne jamais exposer l'existence d'une autre demande** ; aucune donnée personnelle dans la table de suivi, l'audit ni les journaux.

## 2. Pourquoi une table dédiée plutôt que `notification_outbox`
`notification_outbox` est liée à un laissez-passer (`permit_id` non nul), à ses autorisations, révisions et à un secret chiffré de lien ; un refus n'a **aucun** laissez-passer. La généraliser imposerait de rendre nullables plusieurs colonnes de cette table critique et de modifier un distributeur complexe (regroupement, secrets, bail). Une table **dédiée et minimale** réutilise la même politique (`RetryPolicy` : 1, 5, 15, 60, 240 min, abandon après 6 essais ou 24 h), le même classifieur d'erreurs SMTP et la même commande périodique `app:notifications:dispatch`, **sans toucher** à l'existant.

## 3. Schéma proposé : `request_refusal_notice`

| Colonne | Type | Null | Rôle |
|---|---|---|---|
| `id` | `BINARY(16)` | non | PK, UUID v7 |
| `decision_id` | `BINARY(16)` | non | FK `request_permit_request_decision(id)` `RESTRICT` ; **unique** : une information par refus |
| `municipality_id` | `BINARY(16)` | non | FK `municipality(id)` `RESTRICT` ; commune qui a refusé (filtrage et isolation de la liste des agents) |
| `status` | `VARCHAR(16)` | non | `PENDING` (en attente / en cours de réessai), `SENT`, `NO_RECIPIENT`, `ABANDONED`, `MANUAL` (informé par un autre moyen) |
| `attempts` | `SMALLINT UNSIGNED` | non | défaut 0 |
| `available_at` | `DATETIME` | non | prochaine date d'essai |
| `leased_until` | `DATETIME` | oui | bail de traitement (deux distributeurs simultanés ne traitent pas la même ligne) |
| `first_attempt_at` | `DATETIME` | oui | point de départ de la fenêtre de 24 h |
| `processed_at` | `DATETIME` | oui | envoi réussi, abandon ou état définitif |
| `last_error_code` | `VARCHAR(40)` | oui | code (jamais le message SMTP ni l'adresse) |
| `created_at` | `DATETIME` | non | |
| `manual_channel` | `VARCHAR(12)` | oui | `PHONE`, `COUNTER`, `POST`, `OTHER` : moyen par lequel un agent a informé le demandeur |
| `manual_by` | `BINARY(16)` | oui | FK `identity_user(id)` `RESTRICT` |
| `manual_at` | `DATETIME` | oui | |

**Index** : `UNIQUE uniq_refusal_notice_decision (decision_id)` ; `idx_refusal_notice_dispatch (status, available_at)` (distributeur) ; `idx_refusal_notice_municipality (municipality_id, status)` (liste « refus à communiquer » et compteur de l'accueil, rapides sans calcul de validité) ; index automatiques des clés étrangères.

**Absent volontairement** : adresse e-mail, nom, motif libellé, commentaire, texte du message (tout est relu au moment de l'envoi : adresse courante de la demande, code et libellé du motif copiés dans la décision, nom de l'événement et de la commune).

## 4. Règles
1. **Création** : dans la **même transaction** que la décision de refus (`MunicipalDecisionApplier::refuse`, chemins instruction **et** délivrance directe n'en produisent jamais : la délivrance directe n'est pas un refus). Demande **sans adresse e-mail** : statut `NO_RECIPIENT` dès la création, définitif (comme DT-049 D8), jamais d'envoi ultérieur automatique.
2. **Envoi** : le distributeur réserve par bail, **relit l'adresse**, compose et envoie ; échec temporaire = réessai selon `RetryPolicy` ; échec permanent ou plafond atteint = `ABANDONED` avec code ; succès = `SENT`. **Jamais** d'exception vers la décision métier.
3. **Contenu (minimal, à valider juridiquement)** : référence `DEM-…`, nom de l'événement, nom de la commune, **libellé du motif du référentiel**, invitation à contacter la mairie en citant la référence. **Jamais** : le commentaire, un nom, une adresse, une plaque, un lien, un QR. **Exception** : pour les motifs `duplicate` (« Demande en doublon ») et `other`, **aucun libellé de motif** n'est envoyé (formulation générique « votre demande n'a pas pu être acceptée »), afin de ne **jamais révéler l'existence d'une autre demande** ni un texte libre.
4. **Refus partiel** : un message **par commune qui refuse**, sans mention de la décision d'une autre commune ; l'approbation d'une autre commune garde son propre e-mail (laissez-passer).
5. **Agents (visibilité)** : la fiche d'un dossier montre, pour la part de la commune, l'état « information du demandeur » : envoyée le …, en attente de réessai, **échec définitif**, **aucune adresse e-mail : à informer par un autre moyen**. Une liste « **Refus à communiquer** » (statuts `NO_RECIPIENT` et `ABANDONED`) est proposée aux instructeurs et administrateurs de la commune ; l'agent peut **marquer le demandeur comme informé** en choisissant un moyen (`PHONE`, `COUNTER`, `POST`, `OTHER`) : statut `MANUAL`, auteur et date conservés, audit sans coordonnée. L'accueil (08.1) affiche le **nombre** de refus à communiquer.
6. **Écran de confirmation du dépôt** : mention « si vous avez fourni une adresse e-mail, vous serez informé de la décision de chaque commune ; sinon contactez la mairie en indiquant la référence » (aucun suivi public, SFD §1.3).
7. **Clôture de l'événement** : *question à trancher* — recommandation : l'information de refus **n'est pas neutralisée** par la clôture (elle ne contient ni lien ni donnée de dossier) mais reste soumise à la fenêtre de 24 h ; sinon, elle serait neutralisée comme les notifications de laissez-passer (DM-120).
8. **Conservation et purge** : la ligne suit le cycle de vie du dossier : `DossierPurgeService` la supprime **avant** la décision (clé `RESTRICT`) ; table de suivi sans donnée personnelle. `manual_by` rejoint `AccountReferenceCatalog` (**RETAINED**, traçabilité des actions d'agent).
9. **Audit** : `REFUSAL_NOTICE_SENT`, `REFUSAL_NOTICE_ABANDONED`, `REFUSAL_NOTICE_MARKED_INFORMED` (identifiants, codes, moyen) ; jamais l'adresse ni le texte.
10. **Isolation** : toute liste et toute action (marquer informé) est filtrée par la commune de l'agent, relue sous droits (instructeur ou administrateur, participation non suspendue pour l'écriture) ; identifiants forgés = refus uniforme.

## 5. Impact sur l'existant
- Code : `MunicipalDecisionApplier` (création de la ligne), distributeur (nouveau service, **même commande** `app:notifications:dispatch`), gabarit d'e-mail, fiche et liste agents, `DossierPurgeService`, `AccountReferenceCatalog`, `TestDatabase` (purge).
- **Aucun changement** de `notification_outbox`, de `NotificationType` ni de la politique de regroupement des laissez-passer.
- Dépendance d'exploitation : comme toute notification, l'envoi effectif exige un **SMTP réel** et l'unité de distribution installée (installation finale). Sans SMTP : seuls l'état et la procédure agent fonctionnent.

## 6. Migration envisagée (à approuver avant exécution)
Création de **une table** `request_refusal_notice` (ajout seul) avec les contraintes ci-dessus. **Données existantes** : les refus déjà enregistrés n'ont pas de ligne ; **aucun envoi rétroactif** (décision de principe : on n'écrit pas aux demandeurs refusés avant la mise en service). **Retour arrière** : suppression de la table et de ses clés étrangères. **Risques** : faibles (table indépendante) ; sauvegarde locale vérifiée avant exécution ; vérification `doctrine:schema:validate`, clés étrangères et index ; tests de transitions et de concurrence (deux distributeurs, bail).

## 7. Tests prévus
Création dans la transaction du refus (et annulation avec elle) ; `NO_RECIPIENT` sans adresse ; envoi et état `SENT` ; réessais selon `RetryPolicy` et abandon à 6 essais / 24 h ; **deux distributeurs simultanés** (bail) ; adresse relue à l'envoi (corrigée entre-temps) ; **aucun contenu interdit** (commentaire, nom, motif `duplicate`/`other`) ; refus partiel (un message par commune) ; panne SMTP sans effet sur la décision ; liste « à communiquer » isolée par commune et identifiants forgés ; marquage manuel et audit ; purge du dossier ; absence de l'adresse dans la table, l'audit et les journaux.
