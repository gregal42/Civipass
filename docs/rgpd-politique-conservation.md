# Civipass — Politique RGPD et de conservation (LOT 05.6.1)

**Statut : PROPOSITION soumise à validation.** Document exclusivement documentaire (aucun code, aucune migration, aucune infrastructure). Rédigé le 11 novembre 2026 en application de [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) et du [cadrage LOT 05.6 v2.2](lot-05-6-cadrage.md).

> **Avertissement.** Ce document n'est **pas un avis juridique**. Les qualifications (responsable de traitement, sous-traitant, responsables conjoints), les bases légales et **toutes les durées** y figurent comme **hypothèses de travail** à faire valider par les collectivités, leur délégué à la protection des données (DPO) et le service d'archives compétent. **Aucune obligation légale n'est affirmée** ici. La durée de 12 mois est une **hypothèse**, jamais une durée validée. Les mentions « à confirmer » sont volontaires.

Plan : 1. Responsabilités · 2. Registre des traitements · 3. Cycle de vie · 4. Politique de conservation · 5. Droits des personnes · 6. Sécurité et cloisonnement · 7. AIPD · 8. Points à faire valider · 9. Contradictions · 10. Paramètres de configuration.

---

## 1. Répartition des responsabilités (hypothèses, à valider juridiquement)

### 1.1 Acteurs

| Acteur | Rôle dans Civipass | Qualification RGPD **envisagée** (non acquise) |
|---|---|---|
| **Éditeur et exploitant technique** (CWS) | Édite le SaaS, l'héberge ou le fait héberger, administre la plateforme, agit sur instruction des communes. Accès aux données des communes limité à l'exploitation et au support, **sans droit communal implicite** (CLAUDE.md, `security.md`). | **Sous-traitant** des communes pour les données des demandes ; **responsable de traitement** pour ses propres données (comptes de la plateforme, contrats, facturation, journaux d'exploitation). |
| **Commune organisatrice** | Crée l'événement, définit les zones, reçoit et instruit les demandes de son périmètre, émet les laissez-passer, désigne les organisateurs habilités, gère l'archive de son dossier. | **Responsable de traitement** pour son dossier ; **éventuellement conjoint** avec les communes invitées pour les données partagées (voir 1.2). |
| **Communes invitées et participantes** | Instruisent les demandes qui touchent leur territoire (décision, autorisation propres à la commune). | **Responsable de traitement** pour leur dossier communal ; éventuellement conjoint (1.2). |
| **Organisateur habilité** (`EVENT_MANAGER` désigné) | Agent de la commune ; n'est **pas** un tiers. | Personne agissant sous l'autorité du responsable. |
| **Contrôleurs externes, citoyens, bénéficiaires** | Aucun compte (CLAUDE.md règle 6). Le contrôleur voit nom, prénom et plaques via un QR (risque assumé, DM-115). | Personnes concernées ou destinataires, selon le cas. |
| **Prestataires techniques** (hébergeur, messagerie) | Hébergement, envoi d'e-mails. | **Sous-traitants ultérieurs** (à lister, contrats à vérifier). |

### 1.2 Points de qualification à trancher (juridique, DPO)

1. **Responsabilité conjointe** : une demande est **partagée** entre communes (identité, plaques, justificatifs, laissez-passer), la décision étant propre à chaque commune. Les communes sont-elles chacune responsables de leur dossier, ou **conjointes** pour les données partagées (art. 26 RGPD : accord, point de contact, information des personnes) ? **Aucune qualification n'est retenue ici.**
2. **Rôle de CWS** : sous-traitant (art. 28) pour les données des demandes ; contrat ou clauses à produire. Responsable pour ses données propres (comptes, facturation).
3. **Convention entre communes** (invitation intercommunale) : contient-elle déjà la répartition des responsabilités ? (à vérifier avec `invitations-intercommunales.md`)
4. **Hébergement** : localisation, sous-traitants ultérieurs, transferts hors Union européenne (à documenter avant la production).

Conséquence de conception, **indépendante** de la qualification : le modèle cloisonne déjà par commune, ce qui sert tous les scénarios. Aucune commune n'a accès au dossier confidentiel d'une autre (DM-120 D3).

---

## 2. Registre des traitements (matrice)

Légende : **Pendant** = événement actif ; **Archive** = après clôture ; la **purge** est par dossier communal (DM-120 D3). Base légale : **à confirmer** pour toutes les lignes (la mission d'intérêt public est la piste habituelle des collectivités, sans qu'elle soit retenue ici). Les durées sont des **hypothèses** (voir 4).

| # | Traitement | Finalité | Catégories de données | Acteurs et accès | Base légale (à confirmer) | Conservation proposée | Suppression | Risques principaux |
|---|---|---|---|---|---|---|---|---|
| T1 | **Demandes et bénéficiaires** | Recevoir, instruire et décider une demande de laissez-passer | Identité (nom, prénom), contact (e-mail, téléphone), adresse, motif et **textes libres** (peuvent contenir des données inattendues, par exemple de santé) | Pendant : `INSTRUCTOR`, `EVENT_MANAGER`, `MUNICIPALITY_ADMIN` des communes concernées. Archive : `MUNICIPALITY_ADMIN` et organisateur **désigné** de la commune. | Mission d'intérêt public / obligation (à confirmer) | Pendant l'événement, puis archive (hypothèse 12 mois), **tout statut** (y compris non décidée) | Purge du dossier communal ; donnée partagée supprimée quand plus aucun dossier ne la justifie | Textes libres non maîtrisés ; données sensibles saisies par le citoyen ; fuite entre communes |
| T2 | **Autorisations et QR codes** | Délivrer la preuve d'autorisation et permettre le contrôle sur le terrain | Laissez-passer, autorisations, périodes, zones, jetons QR (opaques, sans donnée en clair) ; la page publique expose nom, prénom, plaques à qui détient le QR | Agents pendant ; **public** via le QR (sans compte) ; archive restreinte | Mission d'intérêt public (à confirmer) | Pendant l'événement ; après clôture : « Événement terminé » seul ; jetons conservés avec le dossier (410) | Jeton supprimé à la purge : **404** (option O1) | Partage ou photographie du QR ; **risque résiduel assumé** (DM-115) ; énumération (jetons ≥ 160 bits) |
| T3 | **Véhicules et plaques** | Identifier les véhicules autorisés | Plaques déclarées, autorisées, historiques de révisions | Idem T1 | Idem | Idem T1 | Purge du dossier | Plaque = donnée indirectement identifiante ; historique des remplacements |
| T4 | **Justificatifs** | Appuyer l'instruction | Pièces téléversées (**fichiers hors base**) : contenu libre, potentiellement identité, santé, professionnel | Agents des communes concernées pendant ; archive restreinte ; téléchargements journalisés | Idem | Pendant l'événement puis archive ; **limiter** au nécessaire | Suppression du **fichier** et de la ligne à la purge ; copies en sauvegarde (voir 4.5) | Données sensibles non anticipées ; fichiers hors cascade SQL ; copies de sauvegarde |
| T5 | **Décisions et historiques** | Tracer et motiver les décisions et les modifications de droits | Décision, motif de refus (**texte libre**), révisions, commentaires, ligne neutre pour les autres communes | Commune décisionnaire ; autres communes : ligne neutre seulement | Obligation de motivation / intérêt public (à confirmer) | Archive ; ne pas supprimer avant la fin de la conservation justifiée (règle 8) | Purge du dossier communal | Motifs et commentaires contenant des données personnelles ; **dépendance `permit_permit.first_decision_id`** (L5-32) |
| T6 | **Notifications** | Informer le bénéficiaire (mise à disposition, révocation, remplacement du QR) | Adresse e-mail, type de message, secret du lien **chiffré** (48 h), état d'envoi | Agents (état) ; prestataire de messagerie | Intérêt public / information (à confirmer) | Secrets 48 h ; intentions **en attente annulées** à la clôture ; envoyées conservées pendant l'archive (preuve) ; **outbox : 90 jours provisoires** (contradiction L5-33) | Purge du dossier ; secrets supprimés | E-mail mal adressé ; contenu du message ; sous-traitant de messagerie |
| T7 | **Comptes et habilitations** | Authentifier les agents, attribuer les rôles | Identité professionnelle, e-mail, empreinte du mot de passe, appartenances, rôles, désignations d'organisateurs | CWS, `MUNICIPALITY_ADMIN` de la commune ; lecture seule Sonata | Exécution du contrat / intérêt public (à confirmer) | Durée de la relation ; **aucune suppression automatique en V1** | **Jamais supprimés à cause d'un événement** ; rapport de comptes candidats (décision CWS) | Comptes dormants ; confusion compte permanent / accès événementiel |
| T8 | **Journaux de sécurité et d'audit** | Sécurité, preuve, imputabilité | Identifiant de l'agent, action, objet (**sans valeur personnelle**, DT-037), date ; journaux web (adresses IP, nginx) | CWS, administrateurs habilités | Intérêt légitime / obligation de sécurité (à confirmer) | Audit lié à l'événement : pendant l'archive **pour la preuve** ; journaux techniques : **à définir** (ops/nginx) | Audit du dossier supprimé à la purge, **sauf une ligne de purge** ; audit global inchangé | **Données personnelles dans les commentaires et les identifiants** ; IP dans les journaux web ; conservation excessive |
| T9 | **Archives et gels contentieux** | Conserver la preuve pendant le délai de contestation ; défendre des droits en justice | Dossier communal complet (identifiable : **jamais appelé « anonymisé »**), motif du gel, commentaire, date de revue | `MUNICIPALITY_ADMIN` de la commune ; `EVENT_MANAGER` **désigné** pour (événement, commune) ; jamais `INSTRUCTOR` | Défense de droits / obligation (à confirmer) | Archive : **hypothèse 12 mois** ; gel : motivé, limité, revu | Purge à l'échéance ou à la levée du gel | Archive trop longue ; gel sans motif ; accès croisé entre communes |
| T10 | **Administration du SaaS** (communes, abonnements, droits d'utilisation, contacts, facturation) | Gérer la relation commerciale et l'exploitation | Données de communes et de leurs contacts, abonnements, droits d'utilisation | CWS ; Sonata lecture seule | Exécution du contrat / obligations comptables (à confirmer) | **Hors politique événementielle** : règles comptables et contractuelles propres, **à définir** | **Jamais purgées par le cycle de l'événement** | Application par erreur de la purge événementielle ; absence de politique propre |
| T11 | **Statistiques de clôture** | Bilan de l'événement | Compteurs agrégés, avec seuil de secret statistique, sans plaque ni nom | Organisateur, communes | Intérêt public (à confirmer) | Sans limite **si** la non-réidentification est confirmée | Supprimées avec l'événement | Réidentification sur de petits effectifs |
| T12 | **Sauvegardes** | Reprise après incident | Copie de l'ensemble (T1 à T11) et des fichiers | Exploitation | — | **À valider** avec l'exploitation (rotation) | Rotation ; **rejeu de la purge après restauration** | Résurgence de données purgées après restauration |

Lignes non couvertes à ajouter avant la production : formulaire public de dépôt (données saisies avant authentification), mesure d'audience éventuelle, support (e-mails échangés avec CWS).

---

## 3. Cycle de vie des données (quatre phases validées)

| Phase | Déclencheur | État des données | Accès | Contrat public (QR) |
|---|---|---|---|---|
| **1. Événement actif** | Création | Données opérationnelles complètes | Agents des communes concernées selon leurs rôles ; contrôleurs via le QR ; bénéficiaire par son lien | Jeton actif : nom, prénom, plaques ; retiré : 410 ; inconnu : 404 |
| **2. Clos et archivé** | **Clôture** (globale à l'événement) | Données **identifiantes** conservées par dossier communal, **tout statut** ; liens bénéficiaires **fermés** ; notifications en attente annulées ; secrets et clés d'idempotence supprimés ; statistiques anonymes calculées | **Accès opérationnels fermés immédiatement** ; archive réservée à `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` désigné, **dans sa commune**, avec justification et journal | « Événement terminé » seul (200) ; retiré : 410 |
| **3. Purgé** | Échéance du dossier communal sans gel (ou gel levé) | **Par dossier communal** : données propres à la commune supprimées ; données partagées supprimées quand **aucun** dossier ne les justifie ; fichiers supprimés ; ligne de purge et bordereau conservés | Plus aucun accès aux données supprimées | **404** (O1) |
| **4. Supprimé** | Demande **explicite** d'un `MUNICIPALITY_ADMIN` de l'organisatrice, événement purgé ou sans donnée personnelle | Événement, participations, zones supprimés ; **comptes, communes et référentiels intacts** | — | 404 |

Règles transversales : **aucune suppression avant la fin de la conservation justifiée ; à l'issue, suppression contrôlée, traçable et conforme aux obligations applicables** (règle 8, D1) ; la clôture reste globale, la purge et le gel sont **par dossier communal** (D3) ; **un gel de la commune A n'ouvre jamais les dossiers de B** ; la purge est un service **explicite, idempotent et reprenable** (pas de `CASCADE`) ; **aucune clôture automatique** (détection et alertes seulement) ; **aucun compte supprimé automatiquement**.

---

## 4. Politique de conservation

### 4.1 Cinq régimes distincts

| Régime | Définition | Règle proposée | Statut |
|---|---|---|---|
| **Conservation opérationnelle** | Données utiles à l'instruction et au contrôle | Jusqu'à la clôture de l'événement | Principe validé |
| **Archivage intermédiaire** | Conservation restreinte, par dossier communal, pour la preuve et les contestations | **Hypothèse : 12 mois** après la clôture (paramètre `retention.archive_months`) | **Non validé** : DPO et service d'archives |
| **Gel contentieux** | Suspension de la purge d'**un dossier communal** pour défendre ou exercer un droit | Motivé (code et commentaire court), **limité au dossier**, **revue périodique** (hypothèse 6 mois), levé dès que le motif disparaît ; alerte si revue en retard | Principe validé ; motifs admissibles et durées **à valider** |
| **Élimination définitive** | Purge puis suppression | Par service explicite, avec **préavis** (hypothèse 30 jours), **simulation**, **bordereau** et **registre** sans donnée personnelle | Principe validé ; sort final (élimination, versement) **à définir avec le service d'archives** |
| **Sauvegardes et restauration** | Copies de l'exploitation | Rotation alignée **ou** rejeu de la purge après restauration à partir d'un registre conservé **hors base** | **À valider** avec l'exploitation |

### 4.2 Garde-fous (applicables quelles que soient les durées validées)

1. Aucune suppression avant la fin de la conservation justifiée (règle 8).
2. Une durée est un **paramètre de configuration** nommé, jamais une constante dans le code ; modification tracée.
3. Une durée plancher et un préavis empêchent une purge accidentelle (valeur de configuration trop basse refusée au démarrage du service de purge : à concevoir au 05.6.4).
4. La purge est précédée d'une **simulation** et n'est **jamais** exécutée par défaut (`--execute` explicite).
5. Un dossier **gelé** n'est jamais purgé ; un gel est motivé, journalisé, revu.
6. Les archives **identifiantes ne sont jamais qualifiées d'« anonymisées »** (interface, documents, notices).
7. La politique événementielle **ne s'applique pas** aux données d'administration et de facturation du SaaS (T10) ni aux comptes (T7).
8. Les **textes libres, commentaires, motifs, audits et justificatifs** sont traités comme des données personnelles potentielles (limiter leur conservation, limiter leur diffusion, aucune valeur personnelle dans l'audit).
9. Les durées **provisoires déjà en place** (outbox 90 jours, secrets 48 h) restent signalées comme telles tant qu'elles ne sont pas validées.

### 4.3 Paramètres de configuration proposés (noms indicatifs, **non implémentés**)

| Paramètre | Valeur de départ | Nature | Sous-lot |
|---|---|---|---|
| `retention.archive_months` | 12 | hypothèse | 05.6.4 |
| `retention.purge_notice_days` | 30 | hypothèse | 05.6.4 |
| `retention.legal_hold_review_months` | 6 | hypothèse | 05.6.3 |
| `retention.dormancy_grace_days` | 30 | hypothèse | 05.6.5 |
| `retention.dormancy_inactivity_days` | 90 | hypothèse | 05.6.5 |
| `retention.statistics_min_count` | à fixer | seuil de secret statistique | 05.6.2 |
| `retention.min_archive_months` | plancher | garde-fou | 05.6.4 |

### 4.4 Décisions nécessitant le DPO ou le service d'archives

Liste complète en section 8.

### 4.5 Sauvegardes et restauration

- La suppression logique est effective à l'instant de la purge ; la **copie en sauvegarde subsiste** jusqu'à rotation.
- Politique à choisir (exploitation) : **(a)** rotation courte alignée sur la durée de purge, ou **(b)** conservation plus longue avec **rejeu systématique de la purge** après toute restauration, à partir d'un **registre des purges tenu hors de la base** (sans donnée personnelle).
- Interdit : restaurer une sauvegarde sans rejouer le registre.
- À documenter dans la notice aux personnes (la donnée peut subsister dans une copie de sauvegarde pendant un délai borné).

---

## 5. Droits des personnes (principes ; procédures livrées au LOT 05.6.6, voir [lot-05-6-6-cadrage.md](lot-05-6-6-cadrage.md) §10)

| Droit | Principe proposé | Point d'attention |
|---|---|---|
| **Information** | Notice par collectivité (voir [documentation collectivités](rgpd-documentation-collectivites.md)) | Textes juridiques **non rédigés ici** |
| **Accès** | Export **borné au dossier de la commune** saisie, par le `MUNICIPALITY_ADMIN` | Ne jamais exposer un autre dossier communal ; vérifier l'identité du demandeur |
| **Rectification** | Par les agents pendant l'événement ; en archive, par mention tracée | Ne pas altérer la preuve |
| **Effacement** | **Refusé avec motif** tant qu'une finalité subsiste (gel, obligation), **accordé** sinon par purge anticipée du dossier communal | Un dossier partagé n'est pas supprimé tant qu'un autre dossier le justifie |
| **Opposition, limitation** | Examen au cas par cas par la commune responsable | Gel comme mesure de limitation : à qualifier |
| **Délai** | Un mois (à confirmer), trace de la demande et de la réponse | Procédure interne à définir |
| **Point de contact** | La **commune** concernée ; CWS relaie en sous-traitant | Selon la qualification retenue (1.2) |

Le contrôle public par le QR ne permet **aucun** exercice de droit (pas de compte). Les citoyens passent par la commune.

---

## 6. Sécurité et cloisonnement (rappels)

- **Isolation stricte par commune** ; serveur : autorisation + filtrage de périmètre pour toute lecture sensible ; identifiants du client jamais crus sans vérification (CLAUDE.md règles 4 et 5).
- **Archive** : accès par `MUNICIPALITY_ADMIN` de la commune, ou `EVENT_MANAGER` **désigné** pour (événement, commune) ; `INSTRUCTOR` exclu ; aucune extension automatique ; justification et journal.
- **Pas de contrôleur ni de citoyen avec compte.**
- Audit **sans valeur personnelle** (DT-037) ; secrets chiffrés ; QR opaque sans donnée.
- Sonata en lecture seule (à **inspecter** au 05.6.2 : exposition éventuelle de demandes d'un événement clos).

---

## 7. Analyse d'impact (AIPD) : risques à examiner (sans présumer du caractère obligatoire)

Éléments qui peuvent justifier l'examen d'une AIPD par le DPO (à qualifier : le caractère obligatoire **n'est pas affirmé**) :

| Facteur | Pourquoi l'examiner |
|---|---|
| **Situation de crise** | Personnes dans une situation de vulnérabilité possible ; données collectées dans l'urgence |
| **Textes libres et justificatifs** | Possibilité de **données sensibles** (santé, par exemple) non maîtrisées |
| **Exposition publique par QR** | Nom, prénom et plaques visibles de toute personne qui détient le QR (DM-115) |
| **Plaques d'immatriculation** | Association à une identité ; historique |
| **Traitement partagé entre collectivités** | Responsabilité conjointe, cloisonnement |
| **Échelle** | Multi-communes, volumes en crise |
| **Sauvegardes** | Persistance après purge |

Recommandation : soumettre ces facteurs au DPO **avant la production** pour décision (AIPD ou analyse documentée de non-nécessité).

---

## 8. Points à faire valider

### 8.1 Par les collectivités (communes participantes)

1. Qualification de chaque commune (responsable, conjoint) et accord éventuel de répartition (art. 26).
2. **Durée d'archive** applicable (hypothèse 12 mois) et durée de contestation pertinente.
3. **Motifs admissibles de gel** et période de revue.
4. Désignation des **administrateurs** et des **organisateurs habilités** (qui peut désigner).
5. Point de contact pour l'exercice des droits et délai interne.
6. Notice d'information et mentions à publier.

### 8.2 Par le DPO

1. Bases légales par traitement (colonne « à confirmer » de la matrice).
2. **AIPD** : nécessité (section 7).
3. Qualification de CWS (sous-traitant) et **contrat** (art. 28), sous-traitants ultérieurs, localisation de l'hébergement.
4. Registre officiel des traitements de chaque commune (la matrice de ce document **alimente** mais ne remplace pas les registres officiels).
5. Politique de **journaux** (adresses IP, nginx, audit) et leurs durées.
6. Données sensibles dans les textes libres et justificatifs : mesures.
7. Information des personnes ; procédure des droits ; traitement des violations de données.
8. **Sauvegardes** : politique de rotation et de rejeu.

### 8.3 Par le service d'archives

1. Statut des documents (archives publiques) et **durée d'utilité administrative**.
2. **Sort final** : élimination ou versement ; **visa d'élimination** ; contenu du **bordereau**.
3. Obligations de conservation spécifiques aux décisions et autorisations.

### 8.4 Avant la production (bloquants)

Durées, règles d'élimination, motifs de gel, qualification des acteurs et contrat, politique de sauvegarde, notice d'information. **Rien n'est présenté comme légalement acquis tant que ces validations manquent.**

---

## 9. Contradictions et écarts relevés

Reportés dans [CONTRADICTIONS.md](revisions/CONTRADICTIONS.md) (L5-33 à L5-35) :

| Réf. | Écart |
|---|---|
| L5-33 | Purge de l'outbox à **90 jours** (`app:notifications:purge-outbox`, durée provisoire) face à la conservation des intentions envoyées **pendant l'archive** (preuve de notification) : à arbitrer au 05.6.4 |
| L5-34 | `security.md` : lien bénéficiaire valable jusqu'à 30 jours **après la fin** ; décision D6 : fermé **à la clôture** (DM-114 amendé) : mettre `security.md` en cohérence au 05.6.2 |
| L5-35 | Aucune politique de durée pour les **journaux web et techniques** (T8) ni pour les **données d'administration** (T10) : hors du périmètre événementiel, à définir avec le DPO |

---

## 10. État du sous-lot

Documentation livrée : ce document (politique, responsabilités, registre, cycle de vie, conservation, droits, AIPD, points à valider) et la [documentation destinée aux collectivités](rgpd-documentation-collectivites.md). Aucun changement de code, de schéma, d'infrastructure ou de configuration.
