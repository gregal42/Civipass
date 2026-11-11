# LOT 05.6 — Cycle de vie des événements, archives et suppression RGPD : cadrage (version 2.2)

**Statut : CADRAGE UNIQUEMENT (11 novembre 2026). Orientation, dix arbitrages et quatre décisions de conception du commanditaire INTÉGRÉS (§0 et §0 bis). Aucune implémentation, aucune migration, aucune modification d'infrastructure, aucun déploiement. `PUBLIC_VERIFICATION_ENABLED` reste à 0.**

Cette version **remplace** la version 1 (anonymisation progressive des champs à `T`+30 j, `T`+3 mois, `T`+6 mois : **abandonnée**) et la version 2 initiale. Principe directeur (commanditaire, 11 novembre 2026) : Civipass est un outil **temporaire** de gestion d'événement ; les données opérationnelles ne sont plus accessibles à la clôture ; leur conservation ultérieure répond à une **finalité précise** (litige, obligation légale), dans une **archive intermédiaire strictement cloisonnée**, puis **purge et suppression définitive** lorsque cela est autorisé ; aucune base permanente de citoyens, de véhicules ou de demandes.

**Ce lot reste BLOQUANT avant toute mise en production** (DM-117 §3, [lot-05-5-6-recette.md](lot-05-5-6-recette.md) §7). Décision documentée : [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md).

**Avertissement sur les fondements juridiques.** Les références juridiques citées (RGPD, Code du patrimoine, contentieux administratif, prescriptions) sont données **à titre d'orientation, non vérifiées ici** (aucun accès aux textes à jour). **Aucune durée n'est présentée comme une obligation légale certaine** ; les durées sont des **hypothèses de travail** à confirmer avec le DPO et le service d'archives compétent.

---

## 0. Arbitrages du commanditaire (11 novembre 2026)

| # | Décision | Intégrée au |
|---|---|---|
| 1 | **Demandes non décidées** : **conservées dans l'archive avec leur statut réel**, comme les demandes refusées ou approuvées (ne sont plus supprimées à la clôture) | §3.2, §3.5, §4 |
| 2 | **12 mois = hypothèse, non durée validée** ; politique de conservation à confirmer avec le DPO et le service d'archives ; **gel contentieux motivé, limité au périmètre nécessaire, réexaminé régulièrement** | §3.2, §6.3, §8, R1 |
| 3 | **Accès aux archives : `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` de la commune concernée uniquement** ; rattachement de l'organisateur à l'événement : **option B, désignation explicite** (D2) ; aucun accès aux données confidentielles des autres communes | §6.1, §6.2, R9 |
| 4 | **Rapport de comptes candidats validé**, **aucune suppression automatique en V1** ; nettoyage des accès événementiels ; comptes partagés et permanents préservés | §5, R6 |
| 5 | **QR après purge : option O1** (410 conservé avant la purge ; 404 neutre accepté après) ; évolution du contrat à documenter et à tester | §7, R4 |
| 6 | **Lien bénéficiaire fermé immédiatement à la clôture** (le délai de 30 jours de DM-114 est supprimé) | §3.1, §4, R4 |
| 7 | **Audit et justificatifs** : conservation limitée aux besoins de preuve et aux obligations applicables, puis suppression **contrôlée** ; ne pas oublier fichiers, notifications et sauvegardes | §4, §8, R5, R8, R10 |
| 8 | **Clés étrangères `RESTRICT` conservées** ; service de suppression **explicite, idempotent et reprenable** ; **pas de `CASCADE` généralisé** | §2 C2, §3.3, §10 |
| 9 | **Multicommunal** : données **réellement communes** et décisions **propres à chaque commune** identifiées ; un gel contentieux **n'ouvre pas** les archives des autres communes | §4.2, §6.3 |
| 10 | **Événements sans fin** : dispositif de **détection et d'alerte**, **sans clôture automatique** susceptible de modifier les droits | §3.5, R2 |

### 0 bis. Décisions de conception définitives (11 novembre 2026, DM-120)

| # | Décision | Intégrée au |
|---|---|---|
| D1 | **Règle 8 reformulée — VALIDÉE** : « Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. » | §2 C9, DM-120, CONTRADICTIONS L5-28 |
| D2 | **Habilitation de l'organisateur — OPTION B** : `EVENT_MANAGER` reste un rôle communal ; l'accès aux archives exige une **désignation explicite** pour **chaque événement et chaque commune** ; `MUNICIPALITY_ADMIN` accède aux archives de sa commune ; `INSTRUCTOR` exclu ; **aucune extension automatique** à d'autres événements ou communes ; permissions opérationnelles **inchangées** | §6, §10.1, §13 (05.6.3), Annexe A |
| D3 | **Unité de gel et de purge — DOSSIER COMMUNAL** (demande × commune) ; **la clôture reste globale** à l'événement ; les données **propres à une commune** peuvent être purgées quand leur conservation n'est plus justifiée ; les **données partagées** ne sont supprimées physiquement que lorsqu'**aucun** dossier ne justifie plus leur conservation ; un gel ne donne **jamais** accès aux dossiers des autres communes ; **tests multicommunaux** (références partagées, accès, suppressions partielles) | §3.3, §4.2, §13 (05.6.4), §10.4 |
| D4 | **Migrations : accord de principe uniquement** ; avant **chaque** sous-lot concerné, présenter : SQL exact, entités Doctrine, contraintes et index, traitement des données existantes, risques et retour arrière, tests de non-régression ; **aucune exécution sans validation explicite** | Annexe B, §13 |
| D5 | **Politique de conservation** : la durée de **12 mois reste une hypothèse** ; durées, règles d'élimination, obligations d'archives publiques et modalités de gel à valider par les responsables compétents **avant la production** ; **jamais présentée comme une règle juridiquement acquise** | §8, §12 |

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
| C5 | **Rattachement actuel des habilitations d'événement** (vérifié dans `EventAccessPolicy` et `EventPermission`) : une permission exige une appartenance `MunicipalityMembership` (utilisateur, **commune**) avec un rôle de la liste de la permission (le plus souvent `MUNICIPALITY_ADMIN` ou `EVENT_MANAGER`), dans **la commune organisatrice** de l'événement (droits d'événement) ou **la commune participante** (droits de participation et de zones). **Il n'existe aucune désignation individuelle « pour cet événement »**. | **Décision D2 : option B** — une désignation explicite par événement et commune est ajoutée **pour l'accès aux archives seulement** ; les droits opérationnels existants ne changent pas |
| C6 | Les comptes créés par une invitation (`AdministratorEnrollment`) sont des **administrateurs de la commune invitée** (appartenance de commune) ; une commune invitée est une commune **existante** de la plateforme ; aucune notion de compte temporaire. | Aucun compte n'est « temporaire » par nature (§5) |
| C7 | Une demande est **partagée** entre les communes concernées (identité, contact, adresse, plaques, justificatifs, laissez-passer, QR) ; la **ligne « demande × commune »** (`request_permit_request_municipality`) et la **décision** (`request_permit_request_decision`) sont **propres à chaque commune**, ainsi que l'autorisation et les révisions. | Archive cloisonnée **par commune** ; unité de gel et de purge : le **dossier** (§4.2, §6.3) |
| C8 | `Event.endsAt` est **nullable** : un événement sans fin planifiée et jamais clôturé reste actif indéfiniment ; ses autorisations « jusqu'à la clôture » restent valables. | Détection et alerte (§3.5) |
| C9 | Règle 8 : « pas de suppression physique d'une décision, d'une autorisation ou de son historique ». | **Décision D1 : reformulation VALIDÉE** (« Aucune suppression de données avant la fin de leur conservation justifiée. À l'issue, suppression contrôlée, traçable et conforme aux obligations applicables. ») ; reportée dans DM-120 et CLAUDE.md |
| C10 | Le lien bénéficiaire (DM-114) restait valable 30 jours après la fin effective de l'événement. | **Décision 6 : fermé à la clôture** (DM-114 amendé) |
| C11 | La page publique d'un événement clos affiche déjà **uniquement « Événement terminé »** (DM-115 amendé le 10 novembre 2026) ; un jeton retiré répond 410 ; un jeton inconnu 404. | Contrat conservé avant la purge ; évolution après la purge : §7 |
| C12 | Aucune statistique de clôture n'est enregistrée. | Instantané anonyme à calculer à la clôture |
| C13 | Rien ne détecte aujourd'hui un événement dormant ou jamais clôturé. | §3.5 |
| C14 | **Dépendance « donnée partagée → donnée propre à une commune »** (vérifiée dans le schéma) : `permit_permit.first_decision_id` est une clé **non nulle** vers la décision de la **première commune approbatrice** ; son getter `Permit::firstDecision()` n'est utilisé nulle part (provenance). C'est la **seule** clé de ce sens ; les autres liens vont d'une donnée propre à une commune vers une donnée partagée (demande, laissez-passer). | Elle empêche de supprimer physiquement cette décision tant que le laissez-passer existe : voir §3.3 et la décision à confirmer à l'ouverture du 05.6.4 |

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

### 3.3 Fin de l'archive et purge (unité : le dossier communal, décision D3)

La **clôture est globale** à l'événement ; la **conservation, le gel et la purge** sont **par dossier communal** (demande × commune, `request_permit_request_municipality`).

- L'accès d'une commune à son dossier se ferme à `clôture + durée d'archive` (hypothèse de 12 mois), **sauf gel de cette commune sur ce dossier**.
- **Purge à deux niveaux** :
  1. **Niveau commune** — quand le dossier de la commune `M` n'est plus justifié (accès fermé, aucun gel) : suppression des données **propres à `M`** : ligne demande × commune, décision et motifs de refus de `M`, autorisation de `M` (zones copiées, périodes), révisions de `M` (dont leurs lignes de plaques), audit et notifications propres à `M`. Les autres communes ne sont pas touchées ; **leurs lignes neutres relatives aux actions de `M` disparaissent de leur historique** (conséquence documentée et testée).
  2. **Niveau données partagées** — quand **plus aucun** dossier de la demande ne justifie la conservation (tous les dossiers communaux sont purgés) : suppression de la demande (identité, contact, adresse, textes libres), plaques déclarées, justificatifs **et fichiers**, laissez-passer, plaques autorisées, jetons, liens, intentions de notification du laissez-passer.
- **Dépendance C14** : la décision de la **première commune approbatrice** reste référencée par le laissez-passer (clé non nulle). *Option 1 (recommandée, sans migration)* : cette ligne de décision **est supprimée avec les données partagées** ; elle ne contient **aucun texte libre** (`refusal_comment` est vide pour une approbation) ni donnée de la personne, seulement le résultat, les dates et un identifiant d'agent ; son accès est fermé comme le reste du dossier de `M`. *Option 2* : rendre la clé nullable (migration) pour supprimer la décision au niveau commune. **À confirmer à l'ouverture du 05.6.4** (la décision d'approbation conservée plus longtemps est une exception documentée à « purge propre à la commune »).
- Un événement est « entièrement purgé » quand tous ses dossiers le sont.
- **Déclenchement proposé** : à l'échéance, **après un préavis de 30 jours** aux `MUNICIPALITY_ADMIN` de la commune concernée (qui peut poser ou prolonger un gel motivé) ; opération **par lots, idempotente, reprenable**, avec **simulation (dry-run)** ; refus si un gel existe ou si l'événement n'est pas clos ; **service explicite** (aucun `CASCADE`, décision 8) ; chaque étape journalisée.

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

**Règle d'accès** : `M` accède au dossier `D` ssi `M` est concernée par `D` **et** (l'accès de `M` est dans sa durée d'archive **ou** `M` a un gel sur `D`) **et** l'utilisateur est `MUNICIPALITY_ADMIN` de `M` ou `EVENT_MANAGER` explicitement désigné pour cet événement et `M` (décision D2). Le gel d'une commune **ne prolonge jamais** l'accès des autres communes.

**Règles de suppression partielle (décision D3)** :
1. Les données **propres à `M`** peuvent être supprimées quand le dossier de `M` n'est plus justifié, **sans** attendre les autres communes (sous réserve de la dépendance C14).
2. Les données **partagées** ne sont supprimées que lorsque **tous** les dossiers de la demande sont purgés : tant qu'**un seul** dossier est gelé ou ouvert, la copie partagée reste, **accessible uniquement** au titulaire de ce dossier.
3. Un gel ne donne **jamais** accès aux données propres d'une autre commune, ni à l'archive d'un autre événement.
4. Après la purge de `M`, un autre dossier voit **moins de lignes neutres** (celles des actions de `M`), jamais plus.

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

### 6.1 Profils autorisés (décisions 3 et D2)

- **`MUNICIPALITY_ADMIN`** de la commune concernée : accède aux archives **de sa commune**.
- **`EVENT_MANAGER`** de la commune concernée : accède **uniquement** aux archives des événements pour lesquels il est **explicitement désigné**, **pour cette commune**.
- **`INSTRUCTOR`** : jamais. Un utilisateur d'une autre commune : jamais ; **l'organisateur n'obtient aucune donnée confidentielle des autres communes**. Un utilisateur dont la commune a fermé son accès à l'archive n'a aucun accès, même désigné.
- **Aucune extension automatique** : une désignation ne vaut ni pour un autre événement, ni pour une autre commune, ni pour un autre utilisateur.

### 6.2 Désignation explicite de l'organisateur habilité (option B, décision D2)

**Principe.** `EVENT_MANAGER` reste le rôle **communal** actuel ; les permissions opérationnelles (événement, participation, zones, instruction) sont **inchangées**. S'y ajoute une **désignation** « utilisateur `U` est l'organisateur habilité aux archives de l'événement `E` pour la commune `M` », propre à l'archive.

**Modèle (adaptation proposée, voir Annexe A).** Une entité `EventManagerDesignation` rattachée à la **participation** `(E, M)` (`event_participation`, unique par événement et commune) et à l'utilisateur `U` : date et auteur de la désignation, date et auteur de la révocation. Le lien à la participation **garantit** que `M` participe à `E` et **interdit** d'étendre la désignation à un autre événement ou une autre commune.

**Règles proposées** (à confirmer à l'ouverture du 05.6.3 sauf mention) :
1. **Qui désigne** : `MUNICIPALITY_ADMIN` de `M`, uniquement ; **jamais** l'organisateur désigné lui-même ni un administrateur d'une autre commune.
2. **Pré-requis** : `U` a un rôle `EVENT_MANAGER` effectif dans `M` **au moment de la désignation** ; le rôle est **revérifié à chaque accès** (un rôle retiré ferme l'accès immédiatement).
3. **Quand** : possible pendant l'événement et **après la clôture** (l'administrateur a toujours accès et peut désigner), journalisé.
4. **Retrait du rôle ou de l'appartenance** : les désignations de `U` dans `M` sont **révoquées explicitement** (pas de réactivation silencieuse si le rôle est rendu plus tard).
5. **Fermeture** : les désignations deviennent inopérantes avec l'accès de `M` à l'archive (échéance ou purge) ; elles sont supprimées avec la participation à la suppression de l'événement.
6. **Audit** : désignation, révocation, accès ; aucune donnée personnelle (identifiants seulement).
7. **Existant** : aucune désignation n'existe au départ ; sans désignation, **seul** `MUNICIPALITY_ADMIN` accède à l'archive.
8. **Utilité** : une seule désignation pour l'événement et la commune ne donne aucun accès à une autre commune participante, y compris la commune organisatrice si `U` y a aussi un rôle (il faut une désignation par commune).

### 6.3 Fonctionnement (sans workflow d'approbation)

- **Écrans séparés** des écrans opérationnels (module dédié) ; **aucune** route opérationnelle ne sert une donnée personnelle après la clôture.
- **Justification** à chaque consultation : code (litige, obligation légale, exercice d'un droit d'une personne, contrôle, autre) et commentaire court déconseillant toute donnée personnelle ; **journalisée** (acteur, commune, dossier, code, date ; aucun contenu personnel). **Lecture seule.**
- **Fermeture à terme** : l'accès de `M` à un dossier se ferme à `clôture + durée d'archive`, sauf gel de `M` sur ce dossier.
- **Gel contentieux** (décision 2) : **motivé** (motif codé et commentaire court, sans donnée personnelle), **limité au périmètre nécessaire** (un ou plusieurs **dossiers communaux** précis, jamais l'événement entier par défaut), posé et levé par `MUNICIPALITY_ADMIN` de la commune, avec **date de revue** (tous les 6 mois, paramétrable) ; l'absence de revue produit une alerte, **jamais une prolongation automatique silencieuse** ; chaque pose, revue et levée est journalisée.
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
| **R2** | Cycle de vie : clôture, purge, suppression, événements sans fin, règle 8 | Fermeture immédiate ; **non décidées dans l'archive** ; purge par dossier avec préavis de 30 jours ; suppression explicite de l'événement ; **détection et alerte** des événements sans fin, **sans clôture automatique** ; règle 8 reformulée (D1) | cycle **V** ; **règle 8 : V** (D1) | Rien à faire dans le cas normal ; alertes de dormance | Service de purge ordonné, idempotent, reprenable, simulation ; règles et rapport de dormance | Événement jamais clos : conservation sans fin tant que personne n'agit | **Oui** (statistiques, état de l'événement) |
| **R3** | Contenu de l'archive | **Archive complète**, de **tout statut**, y compris non décidées ; données purement techniques supprimées à la clôture | **V** | Un litige peut être instruit avec toutes les pièces | Aucune transformation de champ ; accès uniquement par l'archive | Textes libres et justificatifs peuvent contenir des données de tiers ; option minimisée si le DPO l'exige | Non |
| **R4** | Liens bénéficiaires et QR | **Lien fermé à la clôture** ; **O1** (410 avant purge, 404 après) ; évolution documentée et testée | **V** | Le bénéficiaire ne rouvre plus sa page après la clôture (DM-114 amendé) ; après purge, un vieux QR donne 404 | Fermeture du lien dans la clôture ; aucune table résiduelle ; tests §7 | Aucun résiduel (O1) | Non |
| **R5** | Justificatifs, fichiers, notifications, clés d'idempotence | Conservés pour la **preuve** pendant l'archive, supprimés à la purge **de façon contrôlée** ; clés et secrets supprimés à la clôture ; fichiers, notifications **et sauvegardes** traités | **V** (principe) | Aucun justificatif après la purge | Suppression de fichier **avec reprise** (marquer, supprimer le fichier, supprimer la ligne) ; nettoyage des orphelins ; cohérence base/disque | Suppression **irréversible** ; copies en sauvegarde ; fichier orphelin si échec | Non |
| **R6** | Comptes utilisateurs | **Rapport de comptes candidats** (règle §5.2), **aucune suppression automatique en V1** ; nettoyage des accès événementiels ; comptes partagés et permanents préservés | **V** | Aucun compte supprimé sans décision | Requêtes de candidature ; filet des clés `RESTRICT` | Données d'agents conservées tant que non traitées (finalité à définir avec le DPO) | Non (marque d'origine seulement si nécessaire) |
| **R7** | Modèle d'état | **Pas de colonne `anonymized_at` généralisée** ; état de l'événement, gel par dossier communal, statistiques, désignation des organisateurs | **V** ; **SQL à valider avant chaque sous-lot (D4)** | Aucun écran « anonymisé » ; un dossier est archivé, gelé ou purgé | Quelques colonnes et une table (§10.1) ; `permit_revision.anonymized_at` existant reste inutilisé | Aucun | **Oui, minimale** |
| **R8** | Audit lié à l'événement | **Preuve pendant l'archive, supprimé à la purge** (sauf la ligne de purge) ; audit global inchangé ; identifiants collectés avant effacement | **V** | La preuve détaillée disparaît avec les données ; la preuve de la purge reste | Collecte par table, suppression ordonnée ; test que rien de global n'est supprimé | Identifiants d'agents (pseudonymes) | Non |
| **R9** | Accès aux archives et droits des personnes | `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` **de la commune** ; jamais INSTRUCTOR ni autre commune ; **rattachement : option B, désignation explicite par événement et commune** (D2) ; droits des personnes : accès par export du périmètre de la commune, effacement refusé tant qu'une finalité subsiste | profils **V** ; **option B : V** (D2) | Une personne peut obtenir ses données ; en archive, l'effacement est refusé avec motif tant qu'une finalité subsiste | Module d'archive cloisonné ; politique d'accès dédiée ; journal | Effacement partiel (e-mails reçus, PDF téléchargés, sauvegardes) ; corresponsabilité | **Oui** (table de désignation) |
| **R10** | Exploitation : déclenchement, sauvegardes, journaux, bordereau, détection | Commande de purge manuelle en **simulation** d'abord ; planification validée avec l'exploitation (hors lot) ; **rejeu de la purge après restauration** (registre des purges conservé hors de la base) ; bordereau de purge ; rapport de dormance ; journaux : durée à fixer | À valider avec l'exploitation | Aucun | Commandes idempotentes ; registre ; documentation d'exploitation | Les sauvegardes détiennent les données au-delà des durées ; une restauration réintroduit des données purgées | Non |

---

## 10. E. Analyse d'impact technique

### 10.1 Migrations envisagées (accord de PRINCIPE uniquement ; aucune exécutée ; décision D4)

| Réf. | Sous-lot | Objet | Contenu envisagé |
|---|---|---|---|
| **M1** | 05.6.2 | statistiques de clôture | `event_event.closure_statistics JSON NULL` |
| **M2** | 05.6.3 | désignation des organisateurs (option B) | **nouvelle table** `event_manager_designation` rattachée à `event_participation` et `identity_user` (Annexe A) |
| **M3** | 05.6.3 | gel contentieux par dossier communal | `request_permit_request_municipality` : `legal_hold_reason`, `legal_hold_comment`, `legal_hold_review_at` |
| **M4** | 05.6.4 | état de purge de l'événement | `event_event.purged_at DATETIME NULL` |
| **M5** | 05.6.4 (**option 2 de C14 seulement**) | décision de la première commune approbatrice | `permit_permit.first_decision_id` rendue **nullable** (non retenue par défaut) |
| **M6** | 05.6.5 (**seulement si le besoin est démontré**) | report et rappels de dormance | `event_event` : `dormancy_snoozed_until`, `dormancy_alerted_at` (alternative sans colonne : lire l'audit) |

- **Aucune** colonne d'état d'anonymisation sur les demandes, plaques, justificatifs, comptes (R7, R6). **Aucune** table résiduelle de QR (O1).
- Les `ON DELETE RESTRICT` **ne sont pas modifiés** (décision 8) ; aucun `CASCADE`.
- **Avant chaque migration**, présentation obligatoire selon l'**Annexe B** (SQL exact, entités Doctrine, contraintes et index, traitement des données existantes, risques et retour arrière, tests de non-régression), puis **validation explicite** ; **aucune exécution sans accord**.
- **Données existantes** : l'application n'est pas en production ; seules les bases de développement et de test existent. Toutes les colonnes ajoutées sont **nullables** (aucune ligne existante n'est modifiée) ; la table de désignation est **vide** au départ.

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
| **Permissions** | `EventAccessPolicy`, `EventPermission`, `PermitRevisionGuard`, `MunicipalityContext` | politique d'archive **séparée** ; **permissions opérationnelles inchangées** ; interdiction de l'exploitation après clôture |
| **Désignation (nouveau, D2)** | entité `EventManagerDesignation`, dépôt, service ; `MembershipManager` (révocation des désignations au retrait d'un rôle ou d'une appartenance) | création, révocation, vérification à chaque accès |
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

**Clôture** : statistiques exactes et anonymes (seuils, non décidées comprises) ; plus aucune route opérationnelle ne sert une donnée personnelle (parcours de **tous** les écrans listés, pour INSTRUCTOR, EVENT_MANAGER, MUNICIPALITY_ADMIN, CWS) ; réponses publiques inchangées ; liens bénéficiaires et d'invitation fermés ; notifications en attente annulées, envoyées conservées ; demandes non décidées présentes dans l'archive avec leur statut. **Archive** : permissions par rôle et par commune avec **identifiants forgés** (autre commune, autre événement, dossier non concerné, événement non clos, accès fermé) ; INSTRUCTOR refusé ; périmètre multicommunal (lignes neutres, aucune fuite) ; **un gel de `A` n'ouvre pas `B`** ; journal d'accès sans donnée personnelle ; gel posé, revu, levé ; fermeture à l'échéance. **Purge** : simulation sans écriture ; idempotence ; **reprise après échec au milieu** ; **concurrence réelle MySQL** (purge contre opération, gel, clôture) ; aucun orphelin ; ressources partagées (communes, comptes, types, référentiels) **intactes** ; fichiers supprimés et cohérence disque ; audit global intact ; bordereau sans donnée personnelle ; 404 après purge (§7). **Désignation (D2)** : `EVENT_MANAGER` non désigné refusé ; désigné pour `(E, M)` refusé pour `(E, M')` et `(E', M)` ; désignation par un non-administrateur ou par un administrateur d'une autre commune refusée ; rôle ou appartenance retirés : accès fermé **et** désignations révoquées, **sans réactivation** si le rôle est rendu ; permissions opérationnelles existantes **inchangées** (tests existants conservés). **Multicommunal (D3)** : références partagées (identité, plaques, justificatifs, laissez-passer) lues par chaque commune concernée ; dossier de `A` purgé, dossier de `B` gelé : la copie partagée **reste**, `B` y accède, `A` n'a plus rien, `B` perd les lignes neutres de `A` ; gel de `A` n'ouvre rien de `B` ; purge de `B` ensuite : tout est supprimé ; dépendance C14 ; ressources partagées intactes ; suppressions partielles idempotentes et reprenables. **Suppression de l'événement** : refus si non purgé ou si donnée personnelle ; saisie de la référence ; trace minimale. **Comptes** : jamais supprimé automatiquement ; candidats exacts ; filet `RESTRICT`. **Dormance** : détection des trois règles ; **aucun changement de droits ni de statut** ; alerte renouvelée ; report motivé. `bin/qa full`.

### 10.5 Découpage

Voir **§13** (découpage détaillé en sept sous-lots, avec objectif, périmètre, impacts, migrations, tests et critères d'acceptation).

## 11. Écarts documentaires et décisions mises à jour

[DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) (décisions de conception D1 à D5 validées) ; **amendements** : DM-114 (lien bénéficiaire fermé à la clôture), DM-115 (comportement après la purge, O1) ; **notes de remplacement** : DM-117 §3 et DM-119 §9 ; `lot-05-schema.md` §11 ; **CLAUDE.md règle 8** reformulée (D1) ; [CONTRADICTIONS.md](revisions/CONTRADICTIONS.md) L5-26 à L5-32. **À reprendre au sous-lot concerné** : `exploitation-notifications.md` (purge de l'outbox, 05.6.4).

## 12. Décisions restantes (aucune ne bloque l'ouverture du 05.6.1)

**Plus aucun arbitrage ne bloque le démarrage du développement du LOT 05.6.** Restent :
- **À confirmer à l'ouverture d'un sous-lot** : (a) **C14** : suppression de la décision d'approbation avec les données partagées (option 1, sans migration, recommandée) ou clé nullable (option 2, M5) : **05.6.4** ; (b) règles de désignation 1 à 8 du §6.2 : **05.6.3** ; (c) seuils de dormance, seuil de secret statistique, période de revue du gel : **05.6.2, 05.6.3, 05.6.5**.
- **SQL de chaque migration** (M1 à M6) : à présenter selon l'Annexe B et à valider **avant** le sous-lot concerné (D4).
- **Avant la production (responsables compétents)** : durée d'archive (**hypothèse de 12 mois, non acquise**), règles d'élimination et obligations d'archives publiques, modalités et motifs de gel, qualification des communes (responsabilité conjointe) et de CWS, politique de sauvegarde (D5).

## 13. Découpage détaillé en sous-lots

Ordre : 05.6.1 → 05.6.2 → 05.6.3 → 05.6.4 ; 05.6.5 peut suivre 05.6.2 ; 05.6.6 après 05.6.4 ; 05.6.7 clôt le lot. Chaque sous-lot se termine par `bin/qa full` (= `make quality`), `verify-docs.sh`, un compte rendu (fichiers, migrations, tests, risques) et **ne démarre qu'après validation du précédent**. Toute migration est présentée **avant** exécution (Annexe B).

### 05.6.1 — Politique de conservation, registre et gouvernance

| | |
|---|---|
| **Objectif** | Figer la politique de conservation **soumise** à validation (DPO, service d'archives), sans la présenter comme acquise. |
| **Périmètre fonctionnel** | Aucun écran. Documents : politique de conservation (durée d'archive **hypothèse**), registre des traitements (finalités, destinataires, responsables, sous-traitant), motifs de gel admissibles, règles d'élimination et bordereau, texte des conditions d'utilisation (QR et événement), procédure de sauvegarde. Paramètres de configuration nommés (durée d'archive, préavis, revue du gel, seuils de dormance). |
| **Impacts techniques** | Documentation ; **CLAUDE.md règle 8** reformulée ; DM-120. Aucun code. |
| **Migrations** | Aucune. |
| **Tests** | `verify-docs.sh` ; relecture croisée DM-114, DM-115, DM-117, DM-119, DM-120. |
| **Critères d'acceptation** | Politique et registre rédigés ; toute durée marquée « hypothèse » ; liste des motifs de gel admissibles proposée ; avis DPO et archives **demandés** (date, destinataire) ou report explicite ; règle 8 reportée ; **aucune** durée présentée comme obligation légale. |

### 05.6.2 — Clôture étendue : fermeture des accès opérationnels

| | |
|---|---|
| **Objectif** | À la clôture, plus **aucun** accès opérationnel aux données personnelles, pour tous les rôles ; statistiques anonymes ; fermeture des accès propres à l'événement. |
| **Périmètre fonctionnel** | Clôture **globale** (dans la même transaction) : (1) statistiques anonymes (demandes reçues, approuvées, refusées, **non décidées**, autorisations, remplacements de QR, révocations ; seuil) ; (2) **prédicat unique** « événement ouvert pour l'exploitation » appliqué aux lecteurs et contrôleurs opérationnels ; message clair à l'agent ; (3) **liens bénéficiaires fermés** (404) ; liens d'invitation et de réinitialisation liés à l'événement révoqués ; (4) intentions de notification **en attente annulées** (code dédié, avec trace), envoyées conservées ; secrets chiffrés et clés d'idempotence **supprimés** ; (5) **demandes non décidées conservées avec leur statut** ; (6) fiche événement avec statistiques ; (7) page publique **inchangée** (« Événement terminé », 410, 404). |
| **Impacts techniques** | `EventLifecycleService::close` ; `MunicipalRequestReader`, `DoctrineMunicipalRequestReadModel`, `MunicipalRequestController`, `MunicipalRequestAttachmentController`, `MunicipalRequestDecisionController`, `RequestIdentityController`, `AgentRequestController`, `DirectIssuanceController`, `PermitRights/Zones/Vehicles/Period/Qr/AccessController` ; `BeneficiaryPassReader`, `PermitAccessService` ; `NotificationDispatcher` ; invitations ; administration Sonata (**à inspecter** : lecture seule mais exposition éventuelle de demandes) ; dépôt public sur événement clos (**à vérifier**). |
| **Migrations** | **M1** : `event_event.closure_statistics JSON NULL` (présentation Annexe B). |
| **Tests** | Parcours de **tous** les écrans listés × rôles (`INSTRUCTOR`, `EVENT_MANAGER`, `MUNICIPALITY_ADMIN`, CWS) après clôture : aucune donnée personnelle servie ; réponses publiques **inchangées** (tests des LOT 05.4 et 05.5 conservés) ; lien bénéficiaire fermé ; notifications annulées ou conservées ; statistiques exactes (zéro demande, non décidées, multicommunal, seuil) ; clôture idempotente ; **concurrence** clôture contre opération ; non-régression de la migration. |
| **Critères d'acceptation** | Aucune route opérationnelle ne sert nom, plaque, justificatif ou motif libre après clôture, quel que soit le rôle ; QR 410 / 404 / « Événement terminé » identiques ; liens fermés ; statistiques anonymes présentes ; non décidées présentes en base avec leur statut ; `bin/qa full` vert. |

### 05.6.3 — Archive cloisonnée, désignation des organisateurs et gel contentieux

| | |
|---|---|
| **Objectif** | Donner accès aux archives, par dossier communal, aux seuls `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` **désignés**, avec justification, journal et gel motivé. |
| **Périmètre fonctionnel** | Module `Archive` (écrans **séparés** des écrans opérationnels, **lecture seule**) : liste des événements clos de la commune ; liste et détail des **dossiers communaux** (données communes, données propres à la commune, lignes neutres des autres) ; téléchargement des justificatifs **journalisé** ; **justification** obligatoire à chaque consultation (code et commentaire court) ; **désignation et révocation** des organisateurs par `MUNICIPALITY_ADMIN` (règles du §6.2) ; **gel** : pose (motif codé, commentaire, date de revue), revue, levée, **alerte** de revue en retard ; fermeture à l'échéance ; `INSTRUCTOR` exclu. |
| **Impacts techniques** | Nouveau module ; `ArchiveAccessPolicy` ; entité, dépôt et service `EventManagerDesignation` ; gel sur `RequestMunicipality` ; lecteurs bornés par commune et dossier ; gabarits ; `AuditAction` (désignation, révocation, accès, gel, revue, levée) ; `MembershipManager` (révocation des désignations au retrait d'un rôle) ; `MunicipalityContext` ; **permissions opérationnelles inchangées**. |
| **Migrations** | **M2** : table `event_manager_designation` ; **M3** : colonnes de gel sur `request_permit_request_municipality` (présentation Annexe B pour chacune, avant exécution). |
| **Tests** | Matrice d'accès exhaustive avec **identifiants forgés** : rôle × commune × événement × dossier × état (actif, clos, accès fermé, gel) ; `INSTRUCTOR` refusé ; `EVENT_MANAGER` non désigné refusé ; désigné pour `(E, M)` refusé pour `(E, M')` et `(E', M)` ; désignation par un non-administrateur refusée ; rôle retiré : accès fermé et désignations révoquées, **sans réactivation** ; **un gel de `A` n'ouvre rien de `B`** (accès, fichiers, lignes neutres) ; journal sans donnée personnelle ; fermeture à l'échéance ; concurrence désignation / retrait de rôle ; **non-régression des permissions opérationnelles**. |
| **Critères d'acceptation** | Chaque cas de la matrice d'accès est testé et conforme ; aucun contenu personnel dans le journal ; gel motivé, limité à un dossier, réexaminé et levable ; migrations M2 et M3 validées puis exécutées **sur accord** ; `bin/qa full` vert. |

### 05.6.4 — Purge à deux niveaux, suppression de l'événement et 404 après purge

| | |
|---|---|
| **Objectif** | Supprimer de façon **contrôlée, traçable, idempotente et reprenable** les données d'un dossier communal, puis les données partagées, puis l'événement. |
| **Périmètre fonctionnel** | **Purge niveau commune** (données propres à `M`) puis **niveau données partagées** (quand plus aucun dossier ne les justifie) selon le §3.3 ; **fichiers** justificatifs supprimés ; audit et notifications du dossier supprimés (sauf une ligne de purge) ; **préavis de 30 jours** aux administrateurs ; **simulation** ; **bordereau de purge** et **registre des purges** sans donnée personnelle ; **suppression de l'événement** (garde : événement purgé ou sans donnée personnelle, saisie de la référence, `MUNICIPALITY_ADMIN` de l'organisatrice) ; **404 neutre** pour tout jeton après la purge (O1). Commande `app:retention:purge` : **simulation par défaut**, `--execute` explicite. |
| **Impacts techniques** | Service de purge ordonné (≈ 20 tables) ; `LocalFileStorage` ; suppression dans `audit_log` par identifiants collectés avant effacement ; `notification_outbox` ; `PublicVerificationReader` (jeton supprimé = inconnu) ; décision **C14** ; `exploitation-notifications.md`. |
| **Migrations** | **M4** : `event_event.purged_at` ; **M5** seulement si l'option 2 de C14 est retenue (présentation Annexe B). |
| **Tests** | Simulation **sans écriture** ; idempotence ; **reprise après échec à chaque étape** ; **concurrence réelle MySQL** (purge contre opération, gel, clôture) ; **multicommunal (D3)** : `A` purgé / `B` gelé (copie partagée conservée, accès de `B` seul, lignes neutres de `A` disparues), purge de `B` ensuite (tout supprimé), gel de `A` sans effet sur `B` ; **références partagées** (identité, plaques, justificatifs, laissez-passer, décision C14) ; **ressources partagées intactes** (communes, comptes, types, référentiels) ; fichiers supprimés et cohérence disque ; audit global intact ; bordereau sans donnée personnelle ; **aucun orphelin** (comptages avant et après) ; **404 identique** (page, code, en-têtes) pour jeton actif, retiré, inconnu après purge ; tests des LOT 05.4 et 05.5 conservés **avant** la purge. |
| **Critères d'acceptation** | Après purge complète d'un dossier : **aucune donnée personnelle résiduelle** (requêtes de contrôle par table) ; aucune donnée partagée supprimée tant qu'un dossier la justifie ; reprise démontrée ; bordereau et registre produits ; règle 8 reformulée respectée (aucune suppression avant l'échéance, suppression tracée) ; migrations validées puis exécutées **sur accord** ; `bin/qa full` vert. |

### 05.6.5 — Comptes candidats et dormance des événements

| | |
|---|---|
| **Objectif** | Rapport de comptes candidats à la suppression (jamais de suppression automatique) ; détection et alerte des événements sans fin ou jamais clôturés. |
| **Périmètre fonctionnel** | Commande de **rapport** `app:accounts:candidates` (règles du §5.2, décision CWS) ; nettoyage des accès événementiels non encore couverts ; **détection** de dormance (fin planifiée dépassée de 30 jours ; pas de fin planifiée et aucune activité depuis 90 jours ; autorisations toutes expirées) ; **alertes** : bandeau pour les administrateurs et organisateurs de la commune organisatrice, rapport console `app:event:dormancy-report` (sans donnée personnelle), e-mail de rappel renouvelé tous les 30 jours, **report motivé** ; **aucune clôture automatique, aucun changement de droit**. |
| **Impacts techniques** | `UserManager`, `MembershipManager` (requêtes en lecture) ; `EventListReader`, `EventDetailReader` (bandeau) ; notificateur d'e-mails ; configuration des seuils. |
| **Migrations** | **M6 seulement si le besoin est démontré** (sinon lecture de l'audit). |
| **Tests** | Candidats exacts (compte permanent, partagé, CWS, référencé par une clé conservée, commune avec autre événement : **non candidats**) ; **jamais de suppression** ; trois règles de dormance ; **aucun changement de statut ni de droit** ; renouvellement et report ; événement avec activité récente non signalé. |
| **Critères d'acceptation** | Le rapport ne supprime rien ; les trois règles détectent les cas attendus ; aucun droit modifié ; alertes reçues par les bons destinataires uniquement ; `bin/qa full` vert. |

### 05.6.6 — Droits des personnes dans le modèle d'archive

| | |
|---|---|
| **Objectif** | Traiter les demandes d'accès et d'effacement d'une personne sans casser le cloisonnement ni la conservation justifiée. |
| **Périmètre fonctionnel** | **Export d'accès** borné au dossier de la commune (par `MUNICIPALITY_ADMIN`) ; **effacement sur demande** : refusé avec motif codé tant qu'une finalité subsiste (gel, obligation), accordé sinon par **purge anticipée du dossier communal** (règles de partage du §3.3) ; trace de la demande, du délai (un mois) et de la réponse ; procédure de vérification de l'identité documentée. |
| **Impacts techniques** | Module d'archive ; service de purge (purge anticipée) ; audit ; documents. |
| **Migrations** | Aucune sauf besoin démontré d'un journal dédié (sinon audit). |
| **Tests** | Export borné à la commune, sans donnée d'une autre ; effacement refusé sous gel ; effacement d'un dossier **sans** supprimer la copie partagée tant qu'un autre dossier la justifie ; trace sans donnée personnelle ; idempotence. |
| **Critères d'acceptation** | Aucune fuite entre communes ; décisions de refus motivées et tracées ; effacement partiel conforme au §4.2 ; `bin/qa full` vert. |

### 05.6.7 — Exploitation, sauvegardes et recette du LOT 05.6

| | |
|---|---|
| **Objectif** | Rendre le cycle exploitable et démontrer qu'il fonctionne de bout en bout. |
| **Périmètre fonctionnel** | Runbooks (purge, rapport de comptes, dormance) ; **rejeu de la purge après restauration** à partir du registre des purges conservé **hors de la base** ; durées de journaux ; recette de bout en bout (création → demandes → clôture → archive → gel → purge → suppression) avec deux communes ; checklist des recettes manuelles ; bilan RGPD et liste des **conditions préalables à la production** (confirmation DPO et archives). **Aucune planification créée** (hors lot). |
| **Impacts techniques** | Documentation, commandes existantes ; **aucune infrastructure**. |
| **Migrations** | Aucune. |
| **Tests** | Scénario automatisé de bout en bout (communes `A` et `B`) ; rejeu de purge idempotent après restauration simulée ; `bin/qa full`, `verify-docs.sh`. |
| **Critères d'acceptation** | Cycle complet démontré ; rejeu idempotent ; aucune recette manuelle marquée réussie sans exécution réelle ; conditions préalables à la production consignées. |

---

## Annexe A — DDL préliminaire des migrations (INDICATIF, NON EXÉCUTÉ)

*Esquisse pour apprécier l'ampleur. Le SQL exact sera produit par Doctrine puis présenté selon l'Annexe B, et ne vaut **pas** accord.*

```sql
-- M1 (05.6.2)
ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL;

-- M2 (05.6.3) : désignation explicite d'un EVENT_MANAGER pour (événement, commune) via la participation
CREATE TABLE event_manager_designation (
  id BINARY(16) NOT NULL,
  participation_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  designated_by_user_id BINARY(16) NOT NULL,
  designated_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  revoked_by_user_id BINARY(16) DEFAULT NULL,
  active_marker TINYINT(1) DEFAULT NULL,           -- 1 tant que la désignation est active, NULL une fois révoquée
  UNIQUE INDEX uniq_designation_active (participation_id, user_id, active_marker),
  INDEX idx_designation_user (user_id),
  PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;
ALTER TABLE event_manager_designation ADD CONSTRAINT fk_designation_participation FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT;
ALTER TABLE event_manager_designation ADD CONSTRAINT fk_designation_user FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE event_manager_designation ADD CONSTRAINT fk_designation_by FOREIGN KEY (designated_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;
ALTER TABLE event_manager_designation ADD CONSTRAINT fk_designation_revoked_by FOREIGN KEY (revoked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT;

-- M3 (05.6.3) : gel contentieux du dossier communal
ALTER TABLE request_permit_request_municipality
  ADD legal_hold_reason VARCHAR(30) DEFAULT NULL,
  ADD legal_hold_comment VARCHAR(200) DEFAULT NULL,
  ADD legal_hold_review_at DATETIME DEFAULT NULL,
  ADD INDEX idx_request_municipality_hold_review (legal_hold_review_at);

-- M4 (05.6.4)
ALTER TABLE event_event ADD purged_at DATETIME DEFAULT NULL;

-- M5 (05.6.4, option 2 de C14 seulement)
-- ALTER TABLE permit_permit MODIFY first_decision_id BINARY(16) DEFAULT NULL;
```

Remarques : la suppression d'un événement doit supprimer les désignations **avant** la participation (clés `RESTRICT`) ; le marqueur `active_marker` reprend le procédé du jeton de vérification actif (MySQL n'a pas d'index unique partiel) ; aucune ligne existante n'est modifiée (colonnes nullables, table vide).

## Annexe B — Gabarit de présentation d'une migration (décision D4)

Avant **chaque** sous-lot concerné, présenter : (1) le **SQL exact** généré ; (2) les **entités Doctrine** concernées (mapping, relations, tests de schéma) ; (3) les **contraintes et index** (clés, unicité, `RESTRICT`, noms) ; (4) le **traitement des données existantes** (valeurs par défaut, rattrapage éventuel, événements déjà clos) ; (5) les **risques et la stratégie de retour arrière** (sauvegarde préalable, `down()` testé, fenêtre, effet sur les tests) ; (6) les **tests de non-régression** (schéma synchronisé, suite complète). **Aucune exécution sans validation explicite**, base par base (test, puis développement), comme pour les migrations des LOT 05.5.1 et 05.5.2.

Aucune implémentation, aucune migration, aucun commit poussé, avant votre accord explicite.
