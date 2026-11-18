# LOT 08 — Écarts fonctionnels de l'audit : synthèse de cadrage

**Date** : 17 novembre 2026 · **Statut** : V1 (synthèse du 17 novembre) puis **V2 (arbitrages validés, schémas proposés)** ci-dessous, qui **prévaut** sur la V1 ; **aucun développement ; aucune migration créée ni exécutée.** Aucune migration, aucune infrastructure distante ; développement local ; interrupteurs inchangés (`PUBLIC_VERIFICATION_ENABLED`, `RIGHTS_ERASURE_EXECUTION_ENABLED`, `RETENTION_PURGE_ENABLED`, `OFFLINE_CONTROL_ENABLED` à 0).
**Sources** : [audit-couverture-fonctionnelle.md](audit-couverture-fonctionnelle.md), [arbitrages-pilote-fiches.md](arbitrages-pilote-fiches.md), décisions de principe du 17 novembre 2026, LOT 09 (DM-129 à DM-132).

**Légende** : ✅ validé par le commanditaire · ◐ validé dans son principe, à préciser · ❓ recommandation de l'agent **non encore validée**.

## Vue d'ensemble

| Lot | Écart (audit) | Validé | Arbitrages restants | Migration |
|---|---|---|---|---|
| **08.1** | E3 — accueil et tableau de bord communal | ◐ principe | contenu exact des indicateurs | non |
| **08.2** | E6 — entrée « Contrôle » et consignes | ◐ principe (contrôle public jamais activé par ce lot) | texte des consignes ; visibilité | non |
| **08.3** | E5 — sessions | ✅ valeurs **provisoires** | modalités d'avertissement ; exclusions | non |
| **08.4** | E1 — doublons potentiels | ✅ informatif, sans refus | règle de rapprochement ; index | **à éviter** (voir ci-dessous) |
| **08.5** | E2 — information du demandeur refusé | ◐ principe | **voie technique (migration ou non)** ; contenu et libellé | **dépend de l'arbitrage** |
| **08.6** | E4 — clôture exceptionnelle CWS | ◐ principe, « à cadrer strictement » | **stockage de la permission** ; contrôle d'identité | **dépend de l'arbitrage** |

Ordre proposé : **08.2 → 08.1 → 08.3 → 08.6 → 08.4 → 08.5** (inchangé). 08.2 est court et doit désormais exposer le LOT 09.

---

## 08.1 — Accueil et tableau de bord communal (E3)

**Décisions** : ◐ « prévoir un accueil et un tableau de bord communal » ; ✅ pas de tableau agrégé multi-communes (CDC §10.1) ; ✅ contexte obligatoire commune puis événement ; ✅ un organisateur ne voit que des **agrégats sans donnée personnelle** des autres communes (SFD SCR-010) ; ✅ menu à regrouper et libellés à harmoniser (audit §4.1).

**Périmètre proposé** : (1) page d'accueil de la commune : demandes à traiter, invitations (intercommunales et d'équipe) en attente, événements actifs, raccourcis selon les rôles ; (2) message clair pour un utilisateur **sans rôle ou sans commune** (aujourd'hui : rien) ; (3) état vide explicite sans événement actif ; (4) menu regroupé (écrans de travail / écrans juridiques) ; (5) bannières de suspension et de lecture seule déjà existantes conservées. Aucun SQL nouveau : lectures bornées sur l'existant.

**Arbitrages restants** : ❓ indicateurs retenus — recommandation : demandes **en attente / approuvées / refusées** (dossiers distincts comptés une fois), laissez-passer **émis** et **révoqués** ; le nombre de laissez-passer « valides » est un état calculé (DM-112) et coûteux : **à ne pas afficher en V1** (ou « émis » seulement). ❓ L'accueil remplace-t-il la liste des événements comme atterrissage ? (recommandation : oui, avec lien direct).

## 08.2 — Entrée « Contrôle » et consignes (E6)

**Décisions** : ✅ **aucune activation** du contrôle public en local ni en production (interrupteur à 0) ; ✅ le conseil de navigation privée sur appareil partagé (DM-125, historique du navigateur) ; ✅ rappel de rapprochement nom/plaques et « le QR ne prouve pas l'identité » (DM-125) ; ✅ messages nationaux fixes (DM-128) ; ✅ **LOT 09** : contrôle hors connexion par PWA, verdict orange qualifié, mode STRICT, risque R-OFF-01 (DM-129 à DM-132).

**Périmètre proposé** : une page « Contrôle » dans l'espace communal (rôles habilités : administrateur, gestionnaire d'événement, instructeur — **sans** donnée de laissez-passer) qui contient la **consigne de terrain** : lire un résultat en ligne ; lire un résultat hors ligne (orange, âge des données, indéterminé = ni refus ni validation) ; quand exiger le contrôle en ligne ; que faire si le service est indisponible (**pas de mode papier**, fiche 1 ; procédure des autorités) ; perte ou vol d'un téléphone ; lien vers la PWA et, pour l'administrateur, vers « Appareils ». Si le contrôle public est désactivé, la page l'indique sans l'activer.

**Arbitrages restants** : ❓ **texte** de la consigne (à valider par le métier avant toute diffusion, comme les bandeaux du LOT 09) ; ❓ visibilité : tous les rôles communaux ou les seuls administrateurs et instructeurs (recommandation : tous, en lecture) ; ❓ afficher le QR d'installation de la PWA sur cette page (recommandation : oui, réservé aux administrateurs, sans code d'activation).

## 08.3 — Sessions (E5)

**Décisions** ✅ (provisoires, configurables, à ne pas figer) : inactivité **30 min** (agents) et **15 min** (CWS) ; durée absolue **12 h** (agents) et **8 h** (CWS) ; **« Se souvenir de moi » désactivé en V1**. Étude du 17 novembre : le brouillon de demande **publique** vit dans la session (≈ 24 min aujourd'hui) : **l'expiration ne vise que les utilisateurs authentifiés**.

**Périmètre proposé** : écouteur d'inactivité et de durée absolue pour les comptes authentifiés (communaux et CWS/Sonata), valeurs par variables d'environnement bornées ; avertissement avant expiration ; retour à la page demandée après reconnexion ; message explicite, **aucune perte silencieuse** ; retrait de la case et de la configuration « se souvenir de moi » ; exclusion du parcours public (`/v`, `/demandes`, `/laissez-passer`, `/invitation`, mot de passe oublié) ; test qu'un cookie ancien ne survit pas à un changement de mot de passe. Aucun SQL.

**Arbitrages restants** : ❓ délai d'avertissement (recommandation : 2 min) et comportement des formulaires agents en cours (recommandation : message + saisie conservée côté serveur **non requise** en V1) ; ❓ session simultanée sur plusieurs postes : conservée (aucune liste de sessions en V1).

## 08.4 — Doublons potentiels (E1)

**Décisions** : ✅ détection **informative**, jamais de refus, fusion ni suppression automatiques (SFD §6.3, lot-03 invariants 13-14, DM-101, DM-102) ; ✅ calcul **à la lecture**, limité aux dossiers **visibles pour la commune qui consulte** (DM-101) ; ✅ critères : même événement ; e-mail, téléphone (E.164), nom + prénom normalisés, plaque (SFD) ; ✅ badge non bloquant dans la liste unique des demandes (DM-102) et indication sur la fiche.

**Arbitrages restants** : ❓ **migration** — l'optimisation naturelle est un index sur e-mail/téléphone/plaque ; **aucune migration n'étant autorisée sans approbation**, le lot doit d'abord **mesurer** (liste de 10 000 demandes) et ne demander un index que si la mesure l'exige ; ❓ règle d'affichage : montrer **quels critères** concordent (« même téléphone », « même plaque ») sans afficher de donnée d'un dossier d'une autre commune ; un téléphone ou une adresse partagés par une famille (CDC §6.2) = « potentiel » seulement ; ❓ le motif de refus « Demande en doublon » (DM-110) reste inchangé.

## 08.5 — Information des demandeurs refusés (E2)

**Décisions** : ◐ « prévoir une information du demandeur en cas de refus » ; ✅ pas de suivi public (SFD §1.3) ; ✅ le commentaire libre d'un refus reste **interne** (DM-110) ; ✅ SMS hors V1 ; ✅ e-mail seul canal disponible, adresse **non vérifiée**.

**Recommandation de la fiche 5 (non validée)** : outbox généralisée avec un type `REQUEST_REFUSED` (réessais, `NO_RECIPIENT`, audit), message minimal (référence, événement, commune, libellé du motif du référentiel, contact de la mairie ; **jamais** le commentaire, de nom, de lien ni de plaque), repli « aucun e-mail : informer le demandeur » dans la fiche de l'agent, mention à l'écran de confirmation du dépôt.

**Arbitrage bloquant** : ❓ **la voie technique** — l'outbox est liée à un laissez-passer (`permit_id` non nul) : l'option recommandée **exige une migration** (ou une table dédiée) ; vous avez demandé qu'**aucune migration supplémentaire** ne soit faite sans approbation. Deux voies : **(A)** migration approuvée (robuste : réessais, trace, absence de perte silencieuse) ; **(B)** envoi direct sans migration (comme l'accusé de réception : **sans réessai, sans trace, perte silencieuse possible** si la messagerie est instable) + repli agent. **Recommandation : (A)**, avec un schéma minimal présenté à l'approbation avant tout code. ❓ Contenu et **libellé juridique** du message ; ❓ motif « Demande en doublon » dans un e-mail à une adresse non vérifiée (révèle l'existence d'une autre demande) : recommandation de le remplacer par une formulation générique ; ❓ refus partiel (approuvé par une commune, refusé par une autre) : un message par commune refusante, sans révéler la décision de l'autre.

## 08.6 — Clôture exceptionnelle par CWS (E4)

**Décisions** : ✅ DM-065 : fonction conservée, **depuis Sonata**, **permission distincte** `CWS_FORCE_CLOSE_EVENT` (jamais attribuée automatiquement), **justification obligatoire**, **confirmation explicite**, **affichage des participations encore ouvertes**, clôture **transactionnelle** de l'événement et des participations restantes, invalidation immédiate des accès opérationnels, **audit complet** (opérateur réel, justification, participations avec leur statut précédent), sans impersonation ; ✅ DM-092 : la clôture normale clôt déjà toutes les participations (même moteur à réutiliser) ; ✅ DM-005 : CWS ne lit aucun dossier. ◐ « à cadrer strictement ».

**Arbitrages bloquants** : ❓ **stockage de la permission** — DM-074 a réservé la persistance des habilitations au « lot Administration CWS » et le code actuel **refuse par défaut** sans stockage : soit **(A)** une migration approuvée (table ou colonne), soit **(B)** une liste blanche de comptes en configuration d'environnement (vide par défaut = fonction indisponible ; changement de configuration tracé, aucune migration). **Recommandation : (B) pour le pilote** (strictement bornée, auditée à l'usage), **(A)** au lot d'administration CWS. ❓ **Contrôle d'identité** : DM-061 note l'absence de MFA ; recommandation : **ressaisie du mot de passe** au moment de la clôture et motif d'au moins une phrase, la MFA restant un prérequis de l'impersonation, non de cette fonction. ❓ L'organisatrice doit-elle être **notifiée** (événement clos par CWS) ? (recommandation : oui, message aux administrateurs communaux concernés, sans motif détaillé).

---

## Décisions demandées avant tout développement du LOT 08
1. **08.5** : voie (A) migration approuvée, ou (B) envoi direct sans migration ; contenu et libellé du message.
2. **08.6** : permission par (A) migration ou (B) liste blanche d'environnement ; ressaisie du mot de passe.
3. **08.4** : accord pour **mesurer avant** toute demande d'index.
4. **08.1/08.2/08.3** : indicateurs, visibilité et texte de la consigne, délai d'avertissement (recommandations ci-dessus).
5. Confirmer que les cinq fiches d'arbitrage fonctionnelles (papier 48 h reporté, verrou 15 min abandonné par DT-041, pas de révocation globale par l'organisatrice, état indéterminé, notification de refus) valent **décisions** : elles n'ont fait l'objet que d'une validation de principe.


---
---

# PARTIE II — V2 : arbitrages validés et schémas (17 novembre 2026)

**Décisions formalisées** : [DM-138](decisions/metier/DM-138-decisions-d-interface-du-lot-08.md) (08.1 à 08.4), [DM-136](decisions/metier/DM-136-notification-des-refus-obligatoire.md) (08.5), [DM-139](decisions/metier/DM-139-cloture-exceptionnelle-cws-permission-persistee.md) (08.6), [DM-133](decisions/metier/DM-133-papier-48-h-reporte-hors-v1.md) à [DM-135](decisions/metier/DM-135-etat-indetermine-du-controle.md), [DM-137](decisions/metier/DM-137-pas-de-verrou-visible-d-instruction-option-a.md) (**ouverte**).

## 9. Ce qui est validé, lot par lot
| Lot | Validé | Précisions retenues par défaut (modifiables) |
|---|---|---|
| **08.1 Accueil** | événements en cours et à venir ; demandes en attente ; demandes récemment traitées ; accès rapides ; **aucun calcul de validité coûteux** | « récemment traitées » = 7 derniers jours, 10 lignes ; l'instructeur voit ses demandes, l'administrateur et le gestionnaire les événements ; **compteur « refus à communiquer »** (08.5) ajouté dès que 08.5 existe ; pas de laissez-passer « valides » |
| **08.2 Contrôle** | entrée « Contrôle » : en ligne, hors connexion, STANDARD/STRICT, limites de révocation, perte/vol ; **texte à valider avant publication** | page en lecture pour tous les rôles communaux ; QR d'installation de la PWA et lien « Appareils » pour l'administrateur ; **aucune activation** de contrôle ; si l'interrupteur est à 0, la page le dit |
| **08.3 Sessions** | agents 30 min / 12 h ; CWS 15 min / 8 h ; avertissement 2 min ; « Se souvenir de moi » désactivé ; brouillons publics exclus | valeurs en variables d'environnement bornées ; message et retour à la page après reconnexion |
| **08.4 Doublons** | informatif, aucun refus ; **mesure sur 10 000 demandes avant tout index** ; aucune exposition d'une autre demande dans les messages publics | badge et critères concordants (« même téléphone »…) visibles seulement des agents, limités aux dossiers visibles de leur commune |
| **08.5 Refus** | option A : notification asynchrone fiable ; e-mail si adresse ; sans e-mail : visible des agents + procédure alternative ; **pas de SMS** | schéma : [lot-08-5-schema.md](lot-08-5-schema.md) |
| **08.6 Clôture CWS** | option A : permission persistée ; autorisation explicite ; ressaisie du mot de passe ; motif obligatoire ; confirmation ; audit ; conséquences cohérentes | schéma : [lot-08-6-schema.md](lot-08-6-schema.md) |

## 10. Les deux schémas proposés (résumé ; détail dans les documents dédiés)
**`request_refusal_notice`** (08.5) — une ligne par décision de refus : `decision_id` (unique, FK), `municipality_id`, `status` (`PENDING`, `SENT`, `NO_RECIPIENT`, `ABANDONED`, `MANUAL`), `attempts`, `available_at`, `leased_until`, `first_attempt_at`, `processed_at`, `last_error_code`, `created_at`, `manual_channel`, `manual_by`, `manual_at` ; index de distribution et de liste « à communiquer » ; **aucune donnée personnelle** ; table dédiée, `notification_outbox` inchangée.
**`identity_cws_permission`** (08.6) — historique des habilitations : `user_id`, `permission`, `granted_by` (≠ bénéficiaire), `granted_at`, `reason`, `revoked_at`, `revoked_by`, `active_key` (unicité d'une attribution active par compte et permission) ; attribution par **commandes console**, sans écran en V1.
Chaque schéma est **une table, en ajout seul, sans reprise de données, avec retour arrière** (suppression de la table). **Aucune migration n'est créée ni exécutée** avant votre approbation.

## 11. Verrou visible de 15 minutes
Étude : [analyse-verrou-instruction.md](analyse-verrou-instruction.md). Recommandation : **A** (état actuel, SFD aligné sur DT-041) pour le pilote, **A + B'** (indicateur de présence informatif en cache, **sans migration ni blocage**) si la recette montre des collisions ; pas de verrou fort. Décision **ouverte**.

## 12. Arbitrages encore indispensables
| # | Arbitrage | Bloque | Recommandation |
|---|---|---|---|
| 1 | **Approbation du schéma 08.5** (et ses règles : motifs « doublon » et « autre » non communiqués ; clôture ne neutralise pas l'information de refus ; aucun envoi rétroactif ; liste « à communiquer » pour instructeurs et administrateurs) | création et exécution de la migration 08.5 | approuver |
| 2 | **Approbation du schéma 08.6** (et ses règles : attribution par console, jamais à soi-même ; motif d'attribution obligatoire ; référence de l'événement saisie en confirmation ; notification aux administrateurs communaux sans le motif) | création et exécution de la migration 08.6 | approuver |
| 3 | **Verrou 15 min** : A ou A + B' | rien (n'empêche pas le pilote) | A + B' en option ultérieure |
| 4 | **Texte de la consigne « Contrôle »** | **publication** de 08.2, pas son développement | validation métier avant diffusion |
| 5 | **Libellé juridique du message de refus** | **activation** de 08.5 en réel, pas son développement | validation avant tout envoi réel |
| 6 | Valeurs par défaut de 08.1 (7 jours, 10 lignes, visibilité par rôle) | confort uniquement | accepter les défauts |

## 13. Enchaînement proposé pour terminer rapidement le développement local
Principe : **commencer par ce qui n'exige aucune migration**, pendant que les deux schémas sont approuvés ; regrouper les migrations en fin de chaîne pour ne pas bloquer.
| Étape | Lot | Contenu | Prérequis | Taille |
|---|---|---|---|---|
| 1 | **08.3** | sessions (inactivité, durée absolue, avertissement, retrait de « se souvenir de moi », exclusion du public) | aucun | XS-S |
| 2 | **08.2** | entrée « Contrôle » et consigne (texte provisoire marqué « à valider ») | aucun (texte à valider **avant publication**) | XS |
| 3 | **08.1** | accueil et tableau de bord (hors compteur « refus à communiquer ») | aucun | S |
| 4 | **08.4** | doublons : calcul à la lecture, badge ; **mesure 10 000** ; index **seulement si la mesure l'exige** (alors demande d'approbation séparée) | aucun | S-M |
| 5 | **08.6** | clôture CWS : migration `identity_cws_permission`, commandes, page CWS, tests | **approbation du schéma 08.6** | S-M |
| 6 | **08.5** | refus : migration `request_refusal_notice`, distributeur, gabarit, liste et fiche agents, compteur d'accueil | **approbation du schéma 08.5** | M |
| 7 | **10** | recette pilote locale (commune vierge, sans fixtures), ergonomie, accessibilité | 08.x | M |
| 8 | **11** | revue sécurité et RGPD ; AIPD (LOT 09) | 10 | M |
| — | **09.7** | recette smartphones réels (HTTPS, autorisation d'infrastructure) | après le développement local | M |
Les étapes 1 à 4 ne dépendent d'aucune approbation et peuvent démarrer **dès votre validation de la V2** ; les étapes 5 et 6 s'enchaînent dès que les schémas sont approuvés. Le développement du LOT 08 reste **local**, sans déploiement ni push sans autorisation ; les quatre interrupteurs restent à 0.
