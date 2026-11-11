# LOT 05.6 — Cycle de vie des événements, archives et suppression RGPD : cadrage (version 2.1)

**Statut : CADRAGE UNIQUEMENT (11 novembre 2026). Orientation et dix arbitrages du commanditaire INTÉGRÉS (§0). Aucune implémentation, aucune migration, aucune modification d'infrastructure, aucun déploiement. `PUBLIC_VERIFICATION_ENABLED` reste à 0.**

Cette version **remplace** la version 1 (anonymisation progressive des champs à `T`+30 j, `T`+3 mois, `T`+6 mois : **abandonnée**) et la version 2 initiale. Principe directeur (commanditaire, 11 novembre 2026) : Civipass est un outil **temporaire** de gestion d'événement ; les données opérationnelles ne sont plus accessibles à la clôture ; leur conservation ultérieure répond à une **finalité précise** (litige, obligation légale), dans une **archive intermédiaire strictement cloisonnée**, puis **purge et suppression définitive** lorsque cela est autorisé ; aucune base permanente de citoyens, de véhicules ou de demandes.

**Ce lot reste BLOQUANT avant toute mise en production** (DM-117 §3, [lot-05-5-6-recette.md](lot-05-5-6-recette.md) §7). Décision documentée : [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md).

**Avertissement sur les fondements juridiques.** Les références juridiques citées (RGPD, Code du patrimoine, contentieux administratif, prescriptions) sont données **à titre d'orientation, non vérifiées ici** (aucun accès aux textes à jour). **Aucune durée n'est présentée comme une obligation légale certaine** ; les durées sont des **hypothèses de travail** à confirmer avec le DPO et le service d'archives compétent.

---

## 0. Arbitrages du commanditaire (11 novembre 2026)

| # | Décision | Intégrée au |
|---|---|---|
| 1 | **Demandes non décidées** : **conservées dans l'archive avec leur statut réel**, comme les demandes refusées ou approuvées (ne sont plus supprimées à la clôture) | §3.2, §3.5, §4 |
| 2 | **12 mois = hypothèse, non durée validée** ; politique de conservation à confirmer avec le DPO et le service d'archives ; **gel contentieux motivé, limité au périmètre nécessaire, réexaminé régulièrement** | §3.2, §6.3, §8, R1 |
| 3 | **Accès aux archives : `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` de la commune concernée uniquement** ; rattachement de l'habilitation de l'organisateur à l'événement à vérifier ; aucun accès aux données confidentielles des autres communes | §6.1, §6.2, R9 |
| 4 | **Rapport de comptes candidats validé**, **aucune suppression automatique en V1** ; nettoyage des accès événementiels ; comptes partagés et permanents préservés | §5, R6 |
| 5 | **QR après purge : option O1** (410 conservé avant la purge ; 404 neutre accepté après) ; évolution du contrat à documenter et à tester | §7, R4 |
| 6 | **Lien bénéficiaire fermé immédiatement à la clôture** (le délai de 30 jours de DM-114 est supprimé) | §3.1, §4, R4 |
| 7 | **Audit et justificatifs** : conservation limitée aux besoins de preuve et aux obligations applicables, puis suppression **contrôlée** ; ne pas oublier fichiers, notifications et sauvegardes | §4, §8, R5, R8, R10 |
| 8 | **Clés étrangères `RESTRICT` conservées** ; service de suppression **explicite, idempotent et reprenable** ; **pas de `CASCADE` généralisé** | §2 C2, §3.3, §10 |
| 9 | **Multicommunal** : données **réellement communes** et décisions **propres à chaque commune** identifiées ; un gel contentieux **n'ouvre pas** les archives des autres communes | §4.2, §6.3 |
| 10 | **Événements sans fin** : dispositif de **détection et d'alerte**, **sans clôture automatique** susceptible de modifier les droits | §3.5, R2 |

---

## 1. Vocabulaire (à respecter dans l'interface et la documentation)

| Terme | Sens | À ne pas confondre avec |
|---|---|---|
| **Actif** | l'événement vit ; accès opérationnels normaux | |
| **Clôturé** | accès opérationnels fermés ; données conservées **de façon identifiable** dans l'archive intermédiaire | « anonymisé » |
| **Archivé (identifiable)** | données personnelles conservées, accessibles **seulement** par l'archive cloisonnée | **jamais « anonymisé »** : une donnée conservée dans une archive identifiable n'est pas anonyme |
| **Gelé** | un dossier conservé au-delà de la durée d'archive pour un motif précis, au bénéfice d'**une** commune | |
| **Purgé** (dossier) | données personnelles et opérationnelles du dossier supprimées | « supprimé » |
| **Supprimé** (événement) | l'événement et ses relations avec les communes n'existent plus | |
| **Statistiques anonymes** | comptages agrégés, sans identifiant ni donnée individuelle | |

## 2. Constats vérifiés dans le code et la base (11 novembre 2026)

| # | Constat | Conséquence |
|---|---|---|
| C1 | La clôture (`EventLifecycleService::close`) passe l'événement et ses participations à « clos », audite, **mais ne touche à aucune donnée** : demandes, plaques, justificatifs, liens et jetons restent consultables par les écrans agent. | Fermeture des accès opérationnels **à construire** |
| C2 | **Toutes** les clés étrangères sont en `ON DELETE RESTRICT` (37 tables métier, plus le suivi des migrations ; voulu : règle 8). Supprimer un événement exige l'effacement ordonné d'une vingtaine de tables dépendantes. | Service de purge **explicite et ordonné** ; les `RESTRICT` restent le filet de sécurité ; **aucun `CASCADE`** (décision 8) |
| C3 | Tables liées à l'événement **sans clé étrangère** : `notification_outbox`, `notification_secret` (`permit_id` simples), `audit_log` (`subject_id` simples, aucune colonne événement). | Nettoyage applicatif obligatoire |
| C4 | Les **fichiers** justificatifs sont hors base (`LocalFileStorage`, clé `storage_key`, `delete()` existe). | Suppression de fichiers avec reprise |
| C5 | **Rattachement actuel des habilitations d'événement** (vérifié dans `EventAccessPolicy` et `EventPermission`) : une permission exige une appartenance `MunicipalityMembership` (utilisateur, **commune**) avec un rôle de la liste de la permission (le plus souvent `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER`), dans **la commune organisatrice** de l'événement (droits d'événement) ou **la commune participante** (droits de participation et de zones). **Il n'existe aucune désignation individuelle « pour cet événement »** : l'organisateur est un membre de la commune organisatrice. | Décision R9 (option A : on garde ce rattachement ; option B : désignation par événement) |
| C6 | Les comptes créés par une invitation (`AdministratorEnrollment`) sont des **administrateurs de la commune invitée** (appartenance de commune) ; une commune invitée est une commune **existante** de la plateforme ; aucune notion de compte temporaire. | Aucun compte n'est « temporaire » par nature (§5) |
| C7 | Une demande est **partagée** entre les communes concernées (identité, contact, adresse, plaques, justificatifs, laissez-passer, QR) ; la **ligne « demande × commune »** (`request_permit_request_municipality`) et la **décision** (`request_permit_request_decision`) sont **propres à chaque commune**, ainsi que l'autorisation et les révisions. | Archive cloisonnée **par commune** ; unité de gel et de purge : le **dossier** (§4.2, §6.3) |
| C8 | `Event.endsAt` est **nullable** : un événement sans fin planifiée et jamais clôturé reste actif indéfiniment ; ses autorisations « jusqu'à la clôture » restent valables. | Détection et alerte (§3.5) |
| C9 | Règle 8 : « pas de suppression physique d'une décision, d'une autorisation ou de son historique ». L'orientation prévoit leur suppression **après la conservation justifiée**. | **Écart à arbitrer** : reformulation proposée de la règle 8 (R2) ; **CLAUDE.md n'est pas modifié sans validation** |
| C10 | Le lien bénéficiaire (DM-114) restait valable 30 jours après la fin effective de l'événement. | **Décision 6 : fermé à la clôture** (DM-114 amendé) |
| C11 | La page publique d'un événement clos affiche déjà **uniquement « Événement terminé »** (DM-115 amendé le 10 novembre 2026) ; un jeton retiré répond 410 ; un jeton inconnu 404. | Contrat conservé avant la purge ; évolution après la purge : §7 |
| C12 | Aucune statistique de clôture n'est enregistrée. | Instantané anonyme à calculer à la clôture |
| C13 | Rien ne détecte aujourd'hui un événement dormant ou jamais clôturé. | §3.5 |

---

## 3. A. Politique de cycle de vie

### 3.1 Les phases

| Phase | Déclencheur | Ce qui se passe |
|---|---|---|
| **A. Actif** | activation | Fonctionnement actuel inchangé (agents habilités, QR selon les droits effectifs, périmètres des communes) |
| **B. Clôturé (archive intermédiaire)** | clôture de l'événement (acte existant, inchangé) | **Immédiatement, dans la même transaction** : (1) statistiques anonymes calculées ; (2) QR inutilisables pour autoriser un passage (droits terminés) ; page publique **« Événement terminé » seule** ; (3) **plus aucun accès opérationnel** aux demandes, plaques, identités, justificatifs, motifs libres et historiques nominatifs, pour **tous** les rôles, dont les `INSTRUCTOR` ; (4) accès propres à l'événement désactivés : liens d'invitation révoqués, **liens bénéficiaires fermés** (décision 6) ; (5) notifications en attente **annulées avec trace** ; (6) la **fiche événement** (nom, dates, communes, statistiques anonymes) reste consultable. Les dossiers — **y compris les demandes non décidées, avec leur statut réel** (décision 1) — restent en base, **identifiables**, accessibles **seulement** par l'archive (§6) |
| **C. Purge d'un dossier** | fin de la conservation de **toutes** les communes concernées par ce dossier, sans gel (§3.3) | Suppression de toutes les données personnelles et opérationnelles du dossier (§4) ; l'événement et ses statistiques anonymes subsistent |
| **D. Suppression de l'événement** | action explicite (§3.4) | Suppression de l'événement, de ses participations et relations avec les communes, de ses zones et statistiques ; trace d'audit minimale |

Le seul traitement différé est la **purge d'un dossier** : tout ce qui n'est plus nécessaire à une finalité est supprimé à ce moment, **pas progressivement champ par champ**. Il n'y a **pas d'anonymisation de champs** (R7).

### 3.2 Archive intermédiaire : contenu et durée

- **Contenu (R3, archive complète)** : demandes **de tout statut** (approuvées, refusées, **non décidées**, avec leur statut réel), identité, contact, adresse, motifs et textes libres, plaques, justificatifs, décisions et motifs de refus, autorisations, périodes, zones, révisions, jetons, **intentions de notification sans donnée personnelle** (preuve qu'une décision a été notifiée). Raison : simplicité et couverture d'un litige.
- **Supprimé dès la clôture**, car sans utilité de preuve : clés d'idempotence, secrets chiffrés d'acheminement, liens d'invitation et de réinitialisation liés à l'événement, liens bénéficiaires.
- **Durée d'archive : 12 mois après la clôture, HYPOTHÈSE DE TRAVAIL, non validée** (décision 2). Elle est paramétrable et **ne devient une durée qu'après confirmation par le DPO et le service d'archives** (§8). Elle ne doit pas être présentée comme une obligation légale.
- **Archives publiques.** Les communes sont des personnes publiques : leurs décisions peuvent relever des **archives publiques** (Code du patrimoine), dont l'élimination est en principe soumise au **visa de l'administration des archives** et à un tableau de gestion (durée d'utilité administrative, sort final). **À vérifier avec le service d'archives.** Le lot prévoit un **bordereau de purge** (événement, communes, dates, comptages, **sans donnée personnelle**).

### 3.3 Fin de l'archive et purge (unité : le dossier)

- L'accès d'une commune à un dossier se ferme à `clôture + durée d'archive`, **sauf gel de cette commune sur ce dossier**.
- Un **dossier** est purgé quand **plus aucune commune concernée** n'y a d'accès ouvert ni de gel : les données communes (une seule copie) attendent la dernière commune.
- Un événement est « entièrement purgé » quand tous ses dossiers le sont.
- **Déclenchement proposé** : à l'échéance, **après un préavis de 30 jours** aux `MUNICIPALITY_ADMIN` de chaque commune concernée (qui peuvent poser ou prolonger un gel motivé) ; opération **par lots, idempotente, reprenable**, avec **simulation (dry-run)** ; verrou, refus si un gel existe ou si l'événement n'est pas clos ; **service explicite** (aucun `CASCADE`, décision 8).

### 3.4 Suppression définitive de l'événement

- Action **explicite**, réservée à `MUNICIPALITY_ADMIN` de la commune organisatrice, avec saisie de la référence de l'événement ; **uniquement** sur un événement **entièrement purgé**, ou **sans donnée personnelle** (brouillon, événement annulé sans demande).
- Effets : suppression de l'événement, de ses participations (donc des relations avec les communes), de ses zones et validités, des statistiques, des liens d'invitation ; trace d'audit minimale (qui, quand, référence, comptages) ; **ressources partagées jamais touchées** (communes, comptes, types, référentiels).

### 3.5 Cas particuliers

| Cas | Proposition |
|---|---|
| **Événement sans fin ou jamais clôturé** (décision 10) | **Détection et alerte, sans clôture automatique.** Règles de détection : (a) fin planifiée dépassée de 30 jours et événement non clos ; (b) pas de fin planifiée et **aucune activité** (dernière demande, décision, révision de droits ou action d'événement) depuis 90 jours ; (c) événement actif dont toutes les autorisations ont expiré. Seuils paramétrables. **Alertes** : bandeau sur la page de l'événement et des événements de la commune pour `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` de la commune organisatrice ; rapport console `app:event:dormancy-report` (identifiants et comptages, **sans donnée personnelle**) pour CWS ; **e-mail de rappel** aux administrateurs de la commune organisatrice, renouvelé tous les 30 jours, avec report motivé possible. Un événement signalé **reste actif, ses droits sont inchangés**. Pas de butoir de clôture automatique. **Risque résiduel** : sans action humaine, les données restent ; à rappeler dans les conditions d'utilisation (R2). |
| **Événement annulé** | Traité comme une **clôture** (cause « annulé ») ; si aucune demande n'existe, suppression directe possible (§3.4) |
| **Demande refusée** | Dans l'archive avec ses décisions et motifs |
| **Demande non décidée à la clôture** (décision 1) | **Dans l'archive, avec son statut réel** ; aucune décision n'est prise à la clôture ; aucune instruction possible après la clôture (déjà refusée) |
| **Contentieux** | **Gel** motivé, limité au dossier concerné et à la commune qui le pose (§6.3) |
| **Commune suspendue ou qui a quitté** | Son accès archive suit son statut ; sa période de conservation court quand même |

---

## 4. B. Matrice des données

« Pendant » = qui peut accéder pendant l'événement **actif**. Après la clôture, seules les archives (§6) donnent accès.

| Donnée | Table ou stockage | Accès pendant | À la clôture | Archive | À la purge du dossier | À la suppression de l'événement | Contraintes |
|---|---|---|---|---|---|---|---|
| Identité, contact, adresse, textes libres de la demande | `request_permit_request` | INSTRUCTOR, MUNICIPALITY_ADMIN, EVENT_MANAGER des communes concernées | écrans opérationnels fermés | **oui** (identifiable), **tout statut** | supprimé | — | **commune** à toutes les communes concernées |
| Plaques déclarées | `request_permit_request_vehicle` | idem | idem | oui | supprimé | — | |
| Plaques autorisées | `permit_vehicle` | agents ; contrôle public avant clôture | idem | oui | supprimé | — | plaques **communes** au laissez-passer |
| Plaques des révisions | `permit_revision_vehicle` | commune qui a agi | idem | oui | supprimé | — | |
| Justificatifs : lignes | `request_permit_request_attachment` | agents des communes concernées | idem | oui | supprimé | — | |
| Justificatifs : **fichiers** | stockage privé (`LocalFileStorage`) | idem | idem | oui | **fichier supprimé** (reprise) | — | irréversible ; hors cascade SQL ; copies en sauvegarde |
| Décisions, motifs de refus | `request_permit_request_decision` | commune décisionnaire | idem | oui | supprimé | — | **propre à chaque commune** |
| Ligne demande × commune (statut réel, gel) | `request_permit_request_municipality` | commune | idem | oui | supprimé | — | **propre à chaque commune** ; porte le gel |
| Autorisations, périodes, zones copiées | `permit_authorization*` | commune concernée | idem | oui | supprimé | — | propre à chaque commune |
| Laissez-passer | `permit_permit` | idem | idem | oui | supprimé | — | commun |
| Révisions (structure, motifs, commentaires) | `permit_revision` | commune qui a agi ; ligne neutre pour les autres | idem | oui | supprimé | — | propre à chaque commune |
| Jetons QR (actif, retirés) | `permit_verification_token` | contrôle public, agents | public : « Événement terminé » seul ; 410 pour un retiré | oui (pour conserver le contrat) | **supprimé** | — | sort du 410 après purge : **O1** (§7) |
| Liens bénéficiaires | `permit_access_token` | bénéficiaire | **fermés** (décision 6) | non | supprimé | — | |
| Participations des communes | `event_participation` | organisateur, commune | « clos » (existant) | oui | conservé | **supprimé** | |
| Zones, validités | `event_zone`, `event_zone_validity` | organisateur, commune | idem | oui | conservé | **supprimé** | |
| Événement | `event_event` | organisateur, communes | clos ; fiche + statistiques | oui | conservé | **supprimé** | |
| Liens d'invitation | `onboarding_invitation_link` | organisateur | **révoqués** | non | supprimé | — | contiennent une adresse e-mail |
| Notifications : intentions | `notification_outbox` | agents (état d'envoi) | **annulées si en attente** ; les envoyées **conservées** (preuve de notification, sans donnée personnelle) | oui | supprimées avec le dossier | — | aucune clé étrangère (C3) |
| Notifications : secrets chiffrés | `notification_secret` | — | **supprimés** | non | — | — | |
| Clés d'idempotence | `request_idempotency_key` | système | **supprimées** | non | — | — | empreintes |
| Audit lié à l'événement | `audit_log` | agents habilités | conservé | oui (preuve, **limitée aux besoins de preuve**) | **supprimé**, sauf une ligne de purge | trace minimale | aucune valeur personnelle (DT-037) ; identifiants collectés avant effacement (C3) |
| Audit global (comptes, communes, référentiels) | `audit_log` | CWS, administrateurs | inchangé | inchangé | inchangé | inchangé | hors purge |
| Statistiques anonymes | `event_event` (JSON proposé) | organisateur, communes | **calculées** | conservées | conservées | supprimées | seuil de secret statistique ; jamais de ventilation individuelle |
| Sauvegardes | hébergeur | — | — | — | — | — | R10 |

**Statistiques anonymes** : nombre de demandes reçues, approuvées, refusées, **non décidées** (par commune et au total), d'autorisations, de laissez-passer, de remplacements de QR, de révocations ; répartitions par code de catégorie et de motif **avec seuil** ; aucune plaque, aucun nom, aucune date individuelle.

### 4.2 Données réellement communes et décisions propres à chaque commune (décision 9)

| Famille | Données | Nature | Visible dans l'archive de la commune `M` | Effet d'un gel posé par `M` |
|---|---|---|---|---|
| **Communes à toutes les communes concernées par le dossier** | identité, contact, adresse, motif et textes libres de la demande ; plaques déclarées ; justificatifs (lignes et fichiers) ; laissez-passer, plaques autorisées, jetons, liens | **une seule copie** | oui, **pour tout dossier qui concerne `M`** (pendant son accès ouvert ou son gel) | la copie est **conservée** tant que `M` a un gel ; **seul `M` y accède** |
| **Propres à chaque commune** | ligne demande × commune (statut réel), **décision** (résultat, motif de refus, commentaire), autorisation, périodes, zones copiées, **révisions** (motifs, commentaires, auteur) | une copie **par commune** | **seulement les siennes** ; celles des autres : **lignes neutres** (type, date, commune), jamais motif ni auteur | protège **les siennes** ; n'ouvre **rien** des autres communes |
| **Événement** | zones, participations, statistiques | par commune pour les zones et la participation | les siennes ; statistiques : les siennes | aucun |

**Règle d'accès** : `M` accède au dossier `D` ssi `M` est concernée par `D` **et** (l'accès de `M` est dans sa durée d'archive **ou** `M` a un gel sur `D`). Le gel d'une commune **ne prolonge jamais** l'accès des autres communes.

---

## 5. C. Matrice des utilisateurs

### 5.1 Ce que le modèle permet de distinguer

L'appartenance est `MunicipalityMembership` (utilisateur, **commune**, rôles cumulables) ; aucune appartenance n'est liée à un événement (C5, C6). Une commune participe à un événement par `EventParticipation`.

| Type | Définition dans le modèle | À la clôture | À la purge | À la suppression de l'événement |
|---|---|---|---|---|
| Commune organisatrice | `Event.organizerMunicipality` | comptes **conservés** ; accès aux archives : §6 | conservés | **jamais supprimés** |
| Commune invitée | commune existante liée par `EventParticipation` (invitée) | accès opérationnels fermés ; archive selon rôle | idem | participation et liens d'invitation supprimés ; **commune et comptes conservés** |
| Commune participante | idem, participation acceptée | idem | idem | idem |
| Compte permanent | tout compte ayant une appartenance à une commune qui a d'autres événements, un abonnement ou un droit commercial, ou un statut CWS | inchangé | inchangé | **jamais supprimé** |
| Compte « temporaire » | **aucune notion dans le modèle** (C6) | inchangé | inchangé | voir règle §5.2 |
| Compte partagé | utilisateur avec plusieurs appartenances ou rôles | inchangé | inchangé | **jamais supprimé** à cause d'un seul événement |
| Compte CWS | permissions CWS, accès commercial | inchangé | inchangé | jamais |

### 5.2 Règle de nettoyage (décision 4 : rapport validé, aucune suppression automatique en V1)

- **Nettoyage des accès événementiels** (propre à l'événement, lors de la clôture et de la suppression) : participations, liens d'invitation, liens de réinitialisation liés à l'événement, liens bénéficiaires. Les appartenances (utilisateur, commune) **ne sont pas propres à l'événement** : elles restent.
- **Aucun utilisateur global n'est supprimé** parce qu'un événement est purgé ou supprimé. Un **rapport de comptes candidats** (console, sans suppression) est produit pour décision de CWS. Un compte est **candidat** si, **après** la purge : (1) aucune permission CWS ni accès commercial ; (2) chacune de ses communes n'a **aucun autre événement actif ou archivé**, n'est organisatrice d'aucun événement, n'a aucun abonnement ou droit commercial ; (3) le compte n'est référencé par **aucun enregistrement conservé** (les clés `RESTRICT` vers `identity_user` sont le **filet de sécurité**) ; (4) aucune connexion récente (durée à fixer) ; (5) un opérateur CWS confirme, de façon tracée.
- Pas de marque d'origine « compte créé par invitation d'événement » en V1 (elle ne serait ajoutée, avec migration, que si la règle ci-dessus s'avère insuffisante).
- Les **communes** (organisations) ne sont jamais supprimées par ce lot.

---

## 6. Archives : accès, périmètre multicommunal, gel

### 6.1 Profils autorisés (décision 3)

**Uniquement** `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` **de la commune concernée**. **Jamais** `INSTRUCTOR`, jamais un utilisateur d'une autre commune, **organisateur compris** : l'organisateur n'obtient **aucune** donnée confidentielle des autres communes. Un utilisateur d'une commune dont l'accès à l'archive est fermé n'a aucun accès, même s'il a l'un de ces deux rôles.

### 6.2 Rattacher l'habilitation de l'organisateur à l'événement (vérification demandée)

Constat (C5) : l'habilitation est **communale** ; l'événement est relié à la commune organisatrice (`Event.organizerMunicipality`) et aux communes participantes (`EventParticipation`).

| Option | Principe | Avantages | Inconvénients | Migration |
|---|---|---|---|---|
| **A (recommandée en V1)** | Accès à l'archive de `M` pour tout `EVENT_MANAGER` ou `MUNICIPALITY_ADMIN` **de `M`**, pour les événements où `M` est **organisatrice ou participante** (le même rattachement que les droits d'événement existants) | cohérent avec le modèle ; aucun changement ; testable avec le même mécanisme | un `EVENT_MANAGER` de `M` voit l'archive de **tous** les événements de `M`, pas d'un seul | Non |
| **B** | Désignation individuelle d'un organisateur **par événement** (table d'affectation) | plus fin : « habilité pour cet événement » | nouveau concept, écran d'administration, migration, jamais demandé jusqu'ici | **Oui** |

Dans les deux options, l'archive reste **bornée à la commune** (§4.2). **Décision de portée à valider** (R9) ; sans validation, l'option A est retenue pour la conception.

### 6.3 Fonctionnement (sans workflow d'approbation)

- **Écrans séparés** des écrans opérationnels (module dédié) ; **aucune** route opérationnelle ne sert une donnée personnelle après la clôture.
- **Justification** à chaque consultation : code (litige, obligation légale, exercice d'un droit d'une personne, contrôle, autre) et commentaire court déconseillant toute donnée personnelle ; **journalisée** (acteur, commune, dossier, code, date ; aucun contenu personnel). **Lecture seule.**
- **Fermeture à terme** : l'accès de `M` à un dossier se ferme à `clôture + durée d'archive`, sauf gel de `M` sur ce dossier.
- **Gel contentieux** (décision 2) : **motivé** (motif codé et commentaire court, sans donnée personnelle), **limité au périmètre nécessaire** (un ou plusieurs **dossiers** précis, jamais l'événement entier par défaut), posé et levé par `MUNICIPALITY_ADMIN` de la commune, avec **date de revue** (tous les 6 mois, paramétrable) ; l'absence de revue produit une alerte, **jamais une prolongation automatique silencieuse** ; chaque pose, revue et levée est journalisée.
- **Le gel d'une commune n'ouvre pas les archives des autres** (décision 9) : voir la règle d'accès du §4.2.
- **Responsabilité conjointe** : les données communes sont une seule copie ; l'éventuelle responsabilité conjointe des communes (RGPD, à vérifier avec le DPO) et le rôle de sous-traitant de CWS sont à préciser dans le registre des traitements.

---

## 7. QR codes et jetons : option O1 retenue (décision 5)

**Contrat HTTP avant la purge : inchangé.** QR stable pendant la vie du laissez-passer ; QR compromis remplaçable ; ancien QR remplacé : **410** ; QR inconnu : **404** ; QR d'événement clos : **« Événement terminé »** sans donnée personnelle.

**Évolution documentée (validée) : après la purge du dossier, tout jeton répond 404 neutre**, y compris un ancien QR remplacé (qui répondait 410). Aucune conservation résiduelle (ni valeur claire, ni empreinte, ni table).

| État du jeton | Événement actif | Événement clos, **avant** la purge (archive) | **Après la purge** du dossier |
|---|---|---|---|
| Jeton actif | page selon les droits | « Événement terminé » seul | **404 neutre** (évolution) |
| Jeton retiré (remplacé) | **410** | **410** | **404 neutre** (évolution) |
| Jeton inconnu ou mal formé | 404 neutre | 404 neutre | 404 neutre |

Raisons de l'abandon de l'empreinte SHA-256 tronquée : tant qu'elle est liée au laissez-passer, c'est une **pseudonymisation** (donnée personnelle indirecte) ; elle n'est utile que si l'on conserve quelque chose **après** la purge, ce que l'option O1 exclut. Les options O2 (empreintes sans lien) et O3 (empreintes liées à l'événement) sont **écartées** ; elles ne seraient rouvertes que si le contrat 410 devait survivre à la purge.

**Tests à prévoir** (sous-lot 05.6.4) : (1) avant la purge : 410, 404, « Événement terminé » **inchangés** (tests des LOT 05.4 et 05.5 conservés) ; (2) après la purge : 404 pour un jeton actif, retiré, inconnu ; réponse **identique** (page, code, en-têtes) pour ne rien révéler ; (3) aucune ligne de jeton résiduelle ; (4) compteur d'échecs de limitation de débit : un jeton purgé compte comme un jeton **inconnu** (comportement existant) ; (5) documentation : conditions d'utilisation (« un QR n'a de sens que pendant l'événement et sa période d'archive »). Évolution inscrite dans DM-115 (amendement) et DM-120.

---

## 8. Politique de conservation : hypothèses à confirmer (décision 2)

| Étape | Proposition | Statut | Fondement à vérifier |
|---|---|---|---|
| Accès opérationnel | jusqu'à la clôture | principe validé | limitation de la finalité (RGPD, art. 5) |
| Archive intermédiaire par commune | **12 mois** après la clôture | **HYPOTHÈSE, non validée** | délais de contestation ; réclamations ; **DPO et service d'archives** |
| Gel contentieux | motivé, limité au dossier, revue tous les 6 mois | principe validé ; durées et motifs admissibles à fixer | exercice ou défense de droits en justice, obligation légale (RGPD, art. 17 §3) |
| Préavis avant purge | 30 jours | hypothèse | information des administrateurs |
| Purge du dossier | à l'échéance, sans gel | principe validé | |
| Sort final des documents (élimination, versement) et durée d'utilité administrative | **à définir avec le service d'archives** ; bordereau de purge prévu | **à confirmer** | Code du patrimoine, tableau de gestion |
| Statistiques anonymes | sans limite (aucune donnée personnelle ; seuils) | à confirmer : absence de réidentification | |
| Audit lié à l'événement | conservé pendant l'archive **pour la preuve**, supprimé à la purge (sauf la ligne de purge) | principe validé (décision 7) | |
| Justificatifs et fichiers | conservés pendant l'archive, supprimés à la purge | principe validé (décision 7) | |
| Notifications | intentions envoyées conservées pendant l'archive (preuve de notification), supprimées à la purge ; secrets et en-attente supprimés ou annulés à la clôture | principe validé | |
| Sauvegardes | rotation alignée ou rejeu de la purge après restauration | **à valider avec l'exploitation** | R10 |

**Éléments à faire confirmer par le DPO et le service d'archives** : la durée d'archive ; le sort final et le visa d'élimination ; les motifs admissibles d'un gel ; la qualification des communes (responsables de traitement, éventuelle responsabilité conjointe) et de CWS (sous-traitant) ; la durée de rotation des sauvegardes.

---

## 9. D. Arbitrages R1 à R10 reformulés

Légende : **V** = validé par le commanditaire (principe) ; **H** = hypothèse à confirmer (DPO, archives) ; **À trancher** = décision encore ouverte. Les migrations citées sont **seulement envisagées** (§10).

| R | Question reformulée | Décision et recommandation | Statut | Conséquences fonctionnelles | Conséquences techniques | Risques RGPD | Migration |
|---|---|---|---|---|---|---|---|
| **R1** | Durée d'archive intermédiaire et gel contentieux | **12 mois = HYPOTHÈSE** ; gel **motivé, limité au dossier, revue tous les 6 mois**, posé par `MUNICIPALITY_ADMIN` | durée **H** ; gel **V** | Les communes disposent d'une période pour un litige ; au-delà, sans gel, plus d'accès | Durée en configuration ; gel par ligne demande × commune ; alerte de revue | Durée trop longue : non-minimisation ; gel abusif : conservation sans fin → revue obligatoire | **Oui** (gel) |
| **R2** | Cycle de vie : clôture, purge, suppression, événements sans fin, règle 8 | Fermeture immédiate ; **non décidées dans l'archive** ; purge par dossier avec préavis de 30 jours ; suppression explicite de l'événement ; **détection et alerte** des événements sans fin, **sans clôture automatique** ; règle 8 reformulée | cycle **V** ; **règle 8 : À trancher** | Rien à faire dans le cas normal ; alertes de dormance | Service de purge ordonné, idempotent, reprenable, simulation ; règles et rapport de dormance | Événement jamais clos : conservation sans fin tant que personne n'agit | **Oui** (statistiques, état de l'événement) |
| **R3** | Contenu de l'archive | **Archive complète**, de **tout statut**, y compris non décidées ; données purement techniques supprimées à la clôture | **V** | Un litige peut être instruit avec toutes les pièces | Aucune transformation de champ ; accès uniquement par l'archive | Textes libres et justificatifs peuvent contenir des données de tiers ; option minimisée si le DPO l'exige | Non |
| **R4** | Liens bénéficiaires et QR | **Lien fermé à la clôture** ; **O1** (410 avant purge, 404 après) ; évolution documentée et testée | **V** | Le bénéficiaire ne rouvre plus sa page après la clôture (DM-114 amendé) ; après purge, un vieux QR donne 404 | Fermeture du lien dans la clôture ; aucune table résiduelle ; tests §7 | Aucun résiduel (O1) | Non |
| **R5** | Justificatifs, fichiers, notifications, clés d'idempotence | Conservés pour la **preuve** pendant l'archive, supprimés à la purge **de façon contrôlée** ; clés et secrets supprimés à la clôture ; fichiers, notifications **et sauvegardes** traités | **V** (principe) | Aucun justificatif après la purge | Suppression de fichier **avec reprise** (marquer, supprimer le fichier, supprimer la ligne) ; nettoyage des orphelins ; cohérence base/disque | Suppression **irréversible** ; copies en sauvegarde ; fichier orphelin si échec | Non |
| **R6** | Comptes utilisateurs | **Rapport de comptes candidats** (règle §5.2), **aucune suppression automatique en V1** ; nettoyage des accès événementiels ; comptes partagés et permanents préservés | **V** | Aucun compte supprimé sans décision | Requêtes de candidature ; filet des clés `RESTRICT` | Données d'agents conservées tant que non traitées (finalité à définir avec le DPO) | Non (marque d'origine seulement si nécessaire) |
| **R7** | Modèle d'état | **Pas de colonne `anonymized_at` généralisée** ; état de l'événement, gel par ligne demande × commune, statistiques | **V** ; **SQL à valider** | Aucun écran « anonymisé » ; un dossier est archivé, gelé ou purgé | Quelques colonnes (§10.1) ; `permit_revision.anonymized_at` existant reste inutilisé | Aucun | **Oui, minimale** |
| **R8** | Audit lié à l'événement | **Preuve pendant l'archive, supprimé à la purge** (sauf la ligne de purge) ; audit global inchangé ; identifiants collectés avant effacement | **V** | La preuve détaillée disparaît avec les données ; la preuve de la purge reste | Collecte par table, suppression ordonnée ; test que rien de global n'est supprimé | Identifiants d'agents (pseudonymes) | Non |
| **R9** | Accès aux archives et droits des personnes | `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` **de la commune** ; jamais INSTRUCTOR ni autre commune ; **rattachement : option A** (communal) ou B (par événement) ; droits des personnes : accès par export du périmètre de la commune, effacement refusé tant qu'une finalité subsiste | profils **V** ; **portée de l'organisateur : À trancher** | Une personne peut obtenir ses données ; en archive, l'effacement est refusé avec motif tant qu'une finalité subsiste | Module d'archive cloisonné ; politique d'accès dédiée ; journal | Effacement partiel (e-mails reçus, PDF téléchargés, sauvegardes) ; corresponsabilité | Non (A) ; oui (B) |
| **R10** | Exploitation : déclenchement, sauvegardes, journaux, bordereau, détection | Commande de purge manuelle en **simulation** d'abord ; planification validée avec l'exploitation (hors lot) ; **rejeu de la purge après restauration** (registre des purges conservé hors de la base) ; bordereau de purge ; rapport de dormance ; journaux : durée à fixer | À valider avec l'exploitation | Aucun | Commandes idempotentes ; registre ; documentation d'exploitation | Les sauvegardes détiennent les données au-delà des durées ; une restauration réintroduit des données purgées | Non |

---

## 10. E. Analyse d'impact technique

### 10.1 Migrations envisagées (aucune exécutée ; SQL à présenter pour accord)

1. `request_permit_request_municipality` (ligne demande × commune) : gel contentieux : `legal_hold_reason VARCHAR(30) NULL`, `legal_hold_comment VARCHAR(200) NULL`, `legal_hold_review_at DATETIME NULL`. L'historique des poses, revues et levées est dans l'audit.
2. `event_event` : `closure_statistics JSON NULL`, `purged_at DATETIME NULL` (renseignée quand le dernier dossier est purgé).
3. **Aucune** colonne d'état sur les demandes, plaques, justificatifs, comptes (R7, R6). **Aucune** table résiduelle de QR (O1). Option B de R9 : une table d'affectation par événement (non retenue en V1).
4. **Les `ON DELETE RESTRICT` ne sont pas modifiés** (décision 8) : une migration vers `CASCADE` ôterait le filet de sécurité de la règle 8.

### 10.2 Composants concernés

| Domaine | Éléments | Nature du changement |
|---|---|---|
| **Cycle de vie** | `EventLifecycleService::close`, `Event`, `EventStatus`, `EventAuditRecorder` | clôture étendue : statistiques, fermeture des accès et des liens bénéficiaires, annulation des notifications en attente ; suppression de l'événement ; détection de dormance |
| **Écrans opérationnels** | `MunicipalRequestController`, `MunicipalRequestReader`, `DoctrineMunicipalRequestReadModel`, `MunicipalRequestAttachmentController` (téléchargement), `MunicipalRequestDecisionController`, `RequestIdentityController`, `AgentRequestController`, `DirectIssuanceController`, `PermitRightsController`, `PermitZonesController`, `PermitVehiclesController`, `PermitPeriodController`, `PermitQrController`, `PermitAccessController` et leurs gabarits | ne **plus** servir de donnée personnelle quand l'événement est clos ; **prédicat unique** « événement ouvert pour l'exploitation » appliqué aux lecteurs, **pas écran par écran** |
| **Dépôt public** | `PublicRequestController`, `RequestSubmissionService`, `TemporaryUploadService` | refuser un dépôt sur un événement clos (**à vérifier**) ; fichiers temporaires |
| **Contrôle public** | `PublicVerificationReader`, `PublicVerificationController` | inchangé avant la purge ; **404 après la purge** (O1) à tester |
| **Bénéficiaire** | `BeneficiaryPassController`, `BeneficiaryPassReader`, `PermitAccessService` | fermeture à la clôture (décision 6) |
| **Archive (nouveau)** | module dédié : politique d'accès (`MUNICIPALITY_ADMIN`, `EVENT_MANAGER`), lecteurs bornés par commune et par dossier, écrans en lecture seule, justification, journal d'accès, gel et revue | nouveau code, nouveaux écrans |
| **Purge et suppression (nouveau)** | service ordonné par table (≈ 20), **idempotent, reprenable**, simulation ; suppression de **fichiers** (`LocalFileStorage`) ; nettoyage de `notification_outbox`, `notification_secret`, `audit_log` ; bordereau ; registre des purges | nouveau code |
| **Comptes** | `identity_user`, `municipality_membership`, `onboarding_invitation_link`, `UserManager` | rapport de candidats ; aucune suppression automatique ; suppression des liens d'invitation |
| **Permissions** | `EventAccessPolicy`, `EventPermission`, `PermitRevisionGuard`, `MunicipalityContext` | permissions d'archive ; interdiction de l'exploitation après clôture |
| **Audit** | `AuditAction`, `AuditTrail`, `AuditSubjectType` | actions : clôture étendue, consultation d'archive, gel (pose, revue, levée), purge, suppression ; aucune valeur personnelle |
| **Notifications** | `NotificationDispatcher`, `OutboxPurger`, `PurgeOutboxCommand`, `SecretPurger` | annulation à la clôture ; la purge générale à 90 jours (provisoire) devient sans objet pour les événements clos |
| **Doctrine** | `Event`, `RequestMunicipality` | quelques colonnes (§10.1) ; aucune relation modifiée |
| **Exploitation** | commandes console, documentation, sauvegardes | purge, rapport de dormance, rapport de comptes candidats, registre ; **aucune** planification créée |

### 10.3 Risques de régression

- **Contrat public des LOT 05.4 et 05.5 préservé** avant la purge : « Événement terminé », 410, 404 ; les opérations de droits des LOT 05.5 restent refusées sur événement clos.
- Une garde « événement clos » **mal placée** peut masquer des données **avant** la clôture ou en laisser **après** : un prédicat unique, testé écran par écran.
- La purge ordonnée peut laisser des **orphelins** si une étape échoue : transaction par lot, reprise idempotente, comptages avant et après.
- La suppression de fichiers n'est pas transactionnelle avec la base.
- Les tests existants qui lisent des demandes sur événement clos devront être adaptés **sans être affaiblis**.

### 10.4 Tests nécessaires

**Clôture** : statistiques exactes et anonymes (seuils, non décidées comprises) ; plus aucune route opérationnelle ne sert une donnée personnelle (parcours de **tous** les écrans listés, pour INSTRUCTOR, EVENT_MANAGER, MUNICIPALITY_ADMIN, CWS) ; réponses publiques inchangées ; liens bénéficiaires et d'invitation fermés ; notifications en attente annulées, envoyées conservées ; demandes non décidées présentes dans l'archive avec leur statut. **Archive** : permissions par rôle et par commune avec **identifiants forgés** (autre commune, autre événement, dossier non concerné, événement non clos, accès fermé) ; INSTRUCTOR refusé ; périmètre multicommunal (lignes neutres, aucune fuite) ; **un gel de `A` n'ouvre pas `B`** ; journal d'accès sans donnée personnelle ; gel posé, revu, levé ; fermeture à l'échéance. **Purge** : simulation sans écriture ; idempotence ; **reprise après échec au milieu** ; **concurrence réelle MySQL** (purge contre opération, gel, clôture) ; aucun orphelin ; ressources partagées (communes, comptes, types, référentiels) **intactes** ; fichiers supprimés et cohérence disque ; audit global intact ; bordereau sans donnée personnelle ; 404 après purge (§7). **Suppression de l'événement** : refus si non purgé ou si donnée personnelle ; saisie de la référence ; trace minimale. **Comptes** : jamais supprimé automatiquement ; candidats exacts ; filet `RESTRICT`. **Dormance** : détection des trois règles ; **aucun changement de droits ni de statut** ; alerte renouvelée ; report motivé. `bin/qa full`.

### 10.5 Découpage proposé

05.6.1 politique, registre des traitements, confirmation DPO et archives (documentation) ; 05.6.2 clôture étendue (fermeture des accès et des liens, statistiques, annulation des notifications) ; 05.6.3 module d'archive (accès, justification, journal, gel) ; 05.6.4 purge et suppression (service ordonné, fichiers, audit, bordereau, 404 après purge) ; 05.6.5 comptes (rapport de candidats) et dormance (détection, alertes) ; 05.6.6 recette. Chaque sous-lot : migration éventuelle **présentée avant exécution**.

---

## 11. Écarts documentaires et décisions mises à jour

[DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) (nouvelle décision, orientation validée) ; **amendements** : DM-114 (lien bénéficiaire fermé à la clôture), DM-115 (comportement après la purge, O1) ; **notes de remplacement** : DM-117 §3 et DM-119 §9 (hypothèses de durées abandonnées) ; `lot-05-schema.md` §11 ; [CONTRADICTIONS.md](revisions/CONTRADICTIONS.md) L5-26 à L5-31. **Non modifiés** : CLAUDE.md règle 8 (reformulation à valider) ; `exploitation-notifications.md` (purge de l'outbox, à reprendre au 05.6.4).

## 12. Arbitrages réellement bloquants avant le développement

1. **Règle 8 de CLAUDE.md** : valider la reformulation (« pas de suppression avant la fin de la conservation justifiée, puis suppression contrôlée et tracée ») : **bloque 05.6.4** (purge et suppression).
2. **Portée de l'habilitation de l'organisateur** (R9, §6.2) : option A (communal, recommandée) ou B (par événement, migration) : **bloque 05.6.3** (archive).
3. **Gel et purge par dossier** (§3.3, §6.3, §10.1) : valider l'unité « dossier » (demande × commune) plutôt que « commune » ou « événement » : **bloque 05.6.3 et la migration du gel**.
4. **SQL des migrations** (§10.1) : à présenter et valider **avant** chaque sous-lot concerné.

**Non bloquants pour le développement** (ils bloquent la **production** ou une configuration) : durée d'archive et sort final (DPO, archives) ; corresponsabilité des communes ; politique de sauvegarde ; seuils de dormance et de statistiques ; durée de revue du gel.

Aucune implémentation, aucune migration, aucun commit poussé, avant votre accord explicite.
