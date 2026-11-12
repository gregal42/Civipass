# LOT 05.6.6 — Droits des personnes dans le modèle d'archive : cadrage (12 novembre 2026)

**Statut : CADRAGE soumis à validation (une seule fois, protocole de `CLAUDE.md`). Aucune implémentation, aucune migration exécutée.** Références : [rgpd-politique-conservation.md](rgpd-politique-conservation.md) §1, §5 ; [lot-05-6-cadrage.md](lot-05-6-cadrage.md) §4.2 et fiche 05.6.6 ; [lot-05-6-4-cadrage.md](lot-05-6-4-cadrage.md) ; DM-120. Les qualifications RGPD ci-dessous sont des **hypothèses à valider (DPO, collectivités)** ; les délais et durées sont des **paramètres**, jamais des validations juridiques.

## 1. Principes

- **Chaque commune traite SON dossier.** Les communes sont, dans l'hypothèse retenue, responsables de leur dossier ; **CWS est sous-traitant** : aucun accès aux demandes de droits, aucune décision, aucun relais dans l'application (la personne qui s'adresse à CWS est renvoyée à la commune par procédure hors Civipass, 05.6.7). Aucun droit communal implicite pour CWS.
- **Aucun compte citoyen, aucun portail** (règle 6) : la demande est reçue par la commune (courrier, guichet, e-mail) et **enregistrée par son `MUNICIPALITY_ADMIN`**. Civipass n'écrit jamais à la personne : la réponse et la remise de l'export se font par la commune, hors Civipass.
- **Aucune suppression automatique** : l'effacement est une décision humaine explicite, exécutée par le service de purge existant (simulation d'abord, interrupteur `RETENTION_PURGE_ENABLED` respecté).
- **Immutabilité** : demandes et décisions historiques ne sont jamais modifiées ; une rectification d'archive est une **mention** ajoutée, jamais un remplacement. Pas d'anonymisation en place (les archives identifiantes ne sont jamais dites « anonymisées »).
- **Isolation** : une demande de droits appartient à UNE commune et ne porte que sur les dossiers de cette commune ; l'export ne contient jamais le contenu propre d'une autre commune (lignes neutres seulement, règle du §4.2 du lot 05.6) ; un effacement ne supprime jamais une donnée partagée dont un autre dossier a besoin (règles de partage du lot 05.6.4).

## 2. Parcours fonctionnels

1. **Enregistrer** (`MUNICIPALITY_ADMIN`) : type (`ACCESS`, `RECTIFICATION`, `ERASURE`, `OBJECTION_LIMITATION`), dossier(s) de la commune désigné(s) **par leur référence publique** (aucune recherche par nom ni e-mail), date de réception. Référence interne `DR-…`, échéance = réception + `RIGHTS_RESPONSE_DAYS` (30). Prorogation motivée possible une fois (jusqu'à +`RIGHTS_EXTENSION_DAYS`, 60), motif codé.
2. **Vérifier l'identité** : attestation **obligatoire** de l'administrateur (méthode codée : référence et courriel du dossier concordants, pièce vérifiée, autre) avant toute lecture, export ou décision ; la procédure détaillée est documentée (05.6.7). Civipass ne prouve pas l'identité, il trace qui l'atteste.
3. **Instruire et décider** (`GRANTED`, `PARTIAL`, `REFUSED`, motif codé obligatoire pour un refus ou un report) :
   - **Accès** : export du dossier de la commune, généré **à la volée** (jamais stocké), fenêtre de disponibilité `RIGHTS_EXPORT_WINDOW_DAYS` (30) et plafond `RIGHTS_EXPORT_MAX_DOWNLOADS` (3) ; chaque téléchargement est tracé.
   - **Rectification** : événement actif : flux de correction d'identité existant (instructeur/administrateur, inchangé) ; événement clos : **mention de rectification** rattachée au dossier de la commune, affichée à côté de la valeur d'origine dans l'archive, supprimée avec le dossier.
   - **Effacement** : refusé si un gel est actif, si l'événement n'est pas clos, ou pour un motif de conservation codé (`RETENTION_JUSTIFIED`, `LEGAL_OBLIGATION`, `OTHER_GROUNDS`) ; accordé sinon : **purge anticipée du dossier communal** (simulation affichée avec les effectifs et la mention « données partagées conservées : dossier d'une autre commune »), conditions relues sous verrou à l'exécution (un gel posé entre-temps l'empêche).
   - **Opposition / limitation** : décision motivée ; aucun effet automatique (la limitation passe par un gel de conservation posé séparément, fonction existante).
4. **Clôturer** : date et trace de la réponse (envoyée hors Civipass). Une demande en retard est signalée à l'administrateur (écran et `app:rights:overdue-report`, identifiants et dates seulement).

Demande touchant plusieurs communes : l'administrateur voit seulement ses dossiers et un rappel neutre (« d'autres communes peuvent détenir des données : la personne doit s'adresser à chacune ») ; aucun transfert ni coordination automatique entre communes.

## 3. Habilitations

| Acte | Qui | Refus |
|---|---|---|
| Enregistrer, vérifier, décider, exporter, exécuter l'effacement, ajouter une mention | `MUNICIPALITY_ADMIN` actif de **la commune du dossier**, droit relu côté serveur, **avant** le jeton CSRF | 404 uniforme (jamais « clos », « droits » ni existence du dossier) |
| Instructeur, organisateur (même désigné), super-administrateur CWS, autre commune | aucun | 404 uniforme |

Préfixe de routes `/communes/{m}/droits/…` **réservé** (hors barrière de clôture, classé `Reserved`), `no-store` partout, identifiants forgés testés. Aucune donnée personnelle dans les courriels ou notifications ordinaires (aucun courriel n'est ajouté ; le bandeau de retard ne porte que des références).

## 4. Données concernées (par dossier communal de la commune)

Export (JSON + justificatifs en archive ZIP) : identité, contact, adresse, motif et textes libres ; plaques ; justificatifs (fichiers) ; décision **de la commune** (résultat, motif) ; autorisation, périodes, zones, révisions **de la commune** ; état du laissez-passer partagé ; historique de notifications (type, date, état, jamais le lien) ; catégories de destinataires (fonctions, jamais les noms d'agents) ; lignes neutres des autres communes (type, date, commune). **Exclus** : contenu propre des autres communes, jetons et secrets, audit nominatif, journaux d'accès d'autres personnes. Effacement : tout le dossier communal et, si c'est le dernier, les données partagées (service de purge existant).

## 5. Exceptions liées à la conservation

Gel actif (refus, jamais levé par cette fonction) ; événement non clos (effacement reporté à la clôture, accès et rectification traités autrement) ; finalité de conservation invoquée par la commune (motif codé, tracé, revue) ; donnée partagée tant qu'un autre dossier subsiste (conservée, sans effet sur l'autre commune). Le registre des demandes de droits lui-même ne contient aucune donnée de la personne (références, dates, codes) : sa durée de conservation est un **point DPO**.

## 6. Migration envisagée M9 (à approuver ; SQL présenté, rien d'exécuté)

Trois tables, clés `RESTRICT`, aucune donnée existante touchée :

```sql
CREATE TABLE rights_request (id BINARY(16) NOT NULL, municipality_id BINARY(16) NOT NULL, public_reference VARCHAR(20) NOT NULL, kind VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL, received_at DATETIME NOT NULL, received_by_user_id BINARY(16) NOT NULL, due_at DATETIME NOT NULL, extended_until DATETIME DEFAULT NULL, extension_reason VARCHAR(30) DEFAULT NULL, identity_verified_at DATETIME DEFAULT NULL, identity_verified_by_user_id BINARY(16) DEFAULT NULL, identity_method VARCHAR(30) DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decided_by_user_id BINARY(16) DEFAULT NULL, decision VARCHAR(16) DEFAULT NULL, refusal_reason VARCHAR(30) DEFAULT NULL, executed_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, dossier_count INT DEFAULT 0 NOT NULL, export_available_until DATETIME DEFAULT NULL, export_downloads INT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_rights_request_reference (public_reference), INDEX idx_rights_request_municipality (municipality_id, status), INDEX idx_rights_request_due (due_at), PRIMARY KEY (id));
CREATE TABLE rights_request_dossier (id BINARY(16) NOT NULL, rights_request_id BINARY(16) NOT NULL, request_municipality_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_rights_dossier (rights_request_id, request_municipality_id), INDEX idx_rights_dossier_rm (request_municipality_id), PRIMARY KEY (id));
CREATE TABLE archive_rectification_mention (id BINARY(16) NOT NULL, request_municipality_id BINARY(16) NOT NULL, rights_request_id BINARY(16) NOT NULL, fields VARCHAR(100) NOT NULL, mention VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, created_by_user_id BINARY(16) NOT NULL, INDEX idx_rectification_rm (request_municipality_id), PRIMARY KEY (id));
-- + clés étrangères RESTRICT : rights_request → municipality, identity_user (x3) ; rights_request_dossier → rights_request, request_permit_request_municipality ; archive_rectification_mention → request_permit_request_municipality, rights_request, identity_user
```

Invariants : un dossier appartient à la commune de la demande (vérifié par le service, test) ; la purge d'un dossier supprime ses lignes `rights_request_dossier` et ses mentions (la ligne `rights_request` reste, sans donnée personnelle, `executed_at` renseigné) ; les nouvelles clés vers `identity_user` sont classées dans `AccountReferenceCatalog` (test de couverture). Retour arrière : `down()` refusé si une table contient des lignes. Sauvegarde vérifiée avant exécution locale (`civipass_test`, puis `civipass`).

## 7. Critères d'acceptation et tests majeurs

Aucune fuite entre communes (export, mention, effacement, identifiants forgés, 404 uniforme avant CSRF) ; export borné, jamais stocké, plafonné, tracé, sans secret ni contenu d'une autre commune ; effacement refusé sous gel, sur événement actif ou avec motif de conservation ; **ordres A puis B et B puis A** : la donnée partagée subsiste tant qu'un autre dossier la justifie ; exécution idempotente, reprenable, simulation sans écriture, interrupteur de purge respecté ; mention supprimée avec le dossier ; délais, prorogation et retard ; identité attestée avant tout accès ; aucune modification de décision ni de demande historique ; audit sans valeur personnelle ; catalogue des clés étrangères à jour ; `bin/qa full` vert.

## 8. Dépendances avec le 05.6.7

Runbook des demandes de droits (réception, vérification d'identité, réponse hors Civipass, renvoi des demandes adressées à CWS) ; **rejeu des effacements après restauration** (les purges anticipées sont inscrites au registre hors base comme les autres, `batch_id` = référence de la demande) ; durée de conservation du registre des demandes ; recette de bout en bout ; aucune planification créée.

## 9. Arbitrages nécessaires (défaut si aucune réponse : recommandation)

1. **D1 — Effacement avant l'échéance de conservation** : l'administrateur de la commune **décide au cas par cas** avec un motif codé, seuls un gel ou un événement non clos bloquent mécaniquement (**recommandé**) ; ou refus systématique tant que la conservation provisoire n'est pas atteinte (position DPO à obtenir).
2. **D2 — Anonymisation** : **non fournie** (effacement du dossier communal entier seulement ; aucune modification de l'historique) (**recommandé**) ; sinon cadrage séparé.
3. **D3 — Export sur événement actif** : l'administrateur de la commune peut exporter son dossier **même si l'événement est actif**, lié à une demande attestée et tracé (**recommandé**, nouvelle lecture limitée à sa commune) ; ou report de l'accès jusqu'à la clôture.
4. **D4 — Migration M9 et mention de rectification d'archive** : approuver les trois tables (**recommandé**) ; sinon rectification d'archive refusée et rien d'autre à migrer pour les droits.

Confirmations implicites : CWS sans accès ni décision ; aucun courriel citoyen ; export à la volée sans stockage ; paramètres `RIGHTS_RESPONSE_DAYS` 30, `RIGHTS_EXTENSION_DAYS` 60, `RIGHTS_EXPORT_WINDOW_DAYS` 30, `RIGHTS_EXPORT_MAX_DOWNLOADS` 3 ; `PUBLIC_VERIFICATION_ENABLED` reste à `0` ; aucun push ni déploiement.
