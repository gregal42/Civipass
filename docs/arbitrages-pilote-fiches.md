# Fiches d'arbitrage — contradictions métier avant le LOT 08

**Date** : 17 novembre 2026 · **Statut** : **proposition, aucune décision validée par fiche** · **Nature** : documentaire. Aucun développement, aucune migration, aucun déploiement ; interrupteurs inchangés (`PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`). Travail exclusivement local.

Ces fiches font suite à [`audit-couverture-fonctionnelle.md`](audit-couverture-fonctionnelle.md) (accepté comme base de travail le 17 novembre 2026).

> **Mise à jour du 17 novembre 2026 — décisions formalisées** : fiche 1 → [DM-133](decisions/metier/DM-133-papier-48-h-reporte-hors-v1.md) (papier 48 h reporté hors V1) ; fiche 3 → [DM-134](decisions/metier/DM-134-pas-de-revocation-globale-par-l-organisatrice.md) (pas de révocation globale) ; fiche 4 → [DM-135](decisions/metier/DM-135-etat-indetermine-du-controle.md) (indéterminé retenu) ; fiche 5 → [DM-136](decisions/metier/DM-136-notification-des-refus-obligatoire.md) (notification obligatoire, option A) ; fiche 2 → [DM-137](decisions/metier/DM-137-pas-de-verrou-visible-d-instruction-option-a.md) (**décision ouverte**, [analyse](analyse-verrou-instruction.md)).

## 0. Décisions de principe reçues (à transcrire en fiches DM après arbitrage)

| Réf. | Principe validé | Précision |
|---|---|---|
| E1 | Détection **informative** des doublons, sans refus automatique | conforme au SFD §6.3 et lot-03 inv. 13-14 |
| E2 | Information du demandeur en cas de refus | modalités : fiche 5 |
| E3 | Accueil et tableau de bord communal | — |
| E4 | Clôture exceptionnelle CWS, cadrée strictement | à cadrer au LOT 08 |
| E5 | **Provisoire** : inactivité 30 min (agents) / 15 min (CWS) ; durées absolues 12 h / 8 h ; « Se souvenir de moi » **désactivé en V1** | valeurs provisoires, configurables ; ne s'applique jamais au parcours public |
| E6 | Entrée « Contrôle » ; **le contrôle public n'est activé ni en local ni en production** | `PUBLIC_VERIFICATION_ENABLED` reste à 0 |
| I3 | Inscription autonome des communes **reportée en V2** ; ouverture manuelle par CWS confirmée pour le pilote | marquer SCR-002…005 « V2 » |

## Effet de la décision du 17 novembre sur ces fiches

Le **contrôle hors connexion étant obligatoire en V1** ([cadrage lot 09](lot-09-controle-hors-connexion-cadrage.md)) :
- **Fiche 4 (état indéterminé)** : l'état « indéterminé » hors ligne **revient** ; la recommandation « conserver » reste valable pour le contrôle **en ligne**, et le vocabulaire hors ligne est défini au cadrage (§3.3).
- **Fiche 1 (papier 48 h)** : le report reste recommandé ; le besoin « sans réseau » est désormais couvert par la PWA.
- Fiches 2, 3 et 5 : inchangées.

## Corrections de l'audit du 17 novembre

La préparation de ces fiches a révélé **deux inexactitudes** dans mon audit, corrigées dans `audit-couverture-fonctionnelle.md` :
1. **Verrou de 15 min** : je l'avais classé « non abandonné explicitement ». C'est faux : **DT-041** (validée le 8 nov.) écrit « Aucun verrou fonctionnel de 15 minutes ». Il manque seulement l'alignement du SFD (fiche 2).
2. **État orange** : je l'avais écrit « absent ». Un ton « incertain » (orange) **existe** pour « droits suspendus » et « seules certaines zones sont autorisées » ; ce qui a disparu est l'orange « indéterminé / hors ligne » (fiche 4).

---

## Fiche 1 — Mode papier dégradé de 48 heures

### Règle initiale
- **SFD §1.1** (objectif) : « mode dégradé papier limité à 48 heures ».
- **SFD §4.4** : si indisponibilité totale (ni PWA ni serveur), « document papier 48 h comme justificatif de mode dégradé, sous consignes des autorités ».
- **SFD §7.2 et SCR-031** : le PDF porte une « fin de contrôle papier dégradé » ; le papier sert de justificatif visuel **48 h maximum après émission** et **jamais au-delà de la validité** des droits imprimés ; il ne garantit pas l'absence de révocation postérieure ; **réimpression = nouvel instantané, QR inchangé**.
- **SFD §10.2 et Annexe D** : protocole papier à valider avec les autorités ; risque de falsification et de révocation non visible. **REC-007/REC-008** (Annexe C et §10.1).
- Le **CDC** ne mentionne pas les 48 h : il dit seulement (§12) que le résultat indéterminé n'est pas une interdiction et que « les procédures de terrain priment ».

### Décisions ultérieures
- **DM-115** : le scan est public et en temps réel ; le QR n'est pas une preuve d'identité.
- **DM-118 §7** : le PDF est « une photographie des droits à l'instant de génération », **généré à la demande, jamais stocké** ; le QR imprimé renvoie aux droits courants (mention imprimée). **Aucune mention des 48 h.**
- **DM-128 / DT-055** : service indisponible = message neutre « ce n'est pas un refus d'autorisation… appliquez la procédure de terrain de votre commune ».

### Fonctionnement actuel
Le PDF imprime « **Seul le contrôle du QR code fait foi** » et précise qu'un agent qui ne contrôle que ce papier ne peut pas savoir si les droits sont valables. Aucune date de fin de « mode dégradé » n'est imprimée. Sans serveur, rien ne permet de valider : l'agent applique la procédure de sa commune. Le PDF est régénéré à chaque téléchargement avec les droits du moment.

### Options
| | Option | Effet |
|---|---|---|
| A | **Reporter explicitement** (V2) ; consigne de terrain « service indisponible → procédure des autorités, aucune validité présumée » | aucun développement ; cohérent avec DM-118 |
| B | Imprimer « Valable en mode dégradé jusqu'au … » = min(émission + 48 h, fin des droits) | petit changement de PDF **mais** voir risques ci-dessous |
| C | A + page d'aide du contrôle (08.2) mentionnant le papier comme **simple indice** | consigne sans valeur de preuve |

### Implications de sécurité de l'option B
- **Falsification** : un PDF est reproductible ; rien n'authentifie le papier sans réseau.
- **Fraîcheur** : « 48 h après émission » est ambigu, car le PDF est régénéré à la demande ; un bénéficiaire peut **réimprimer indéfiniment** et prolonger la fenêtre ; il faudrait figer une date d'émission, donc **stocker un état** (rupture de DM-118 §7).
- **Révocation invisible** : un laissez-passer révoqué reste « valable » sur papier pendant la fenêtre.
- **Cadre juridique** : le protocole doit être validé par les forces de l'ordre (SFD §10.2), hors de portée du code.

### Recommandation
**Option A + C.** Acter le report du mode papier 48 h en V2, conserver le texte actuel du PDF (qui ne crée aucune fausse sécurité), et ajouter la consigne d'indisponibilité à la page « Contrôle » (08.2). Réexaminer quand les autorités auront défini un protocole.

### Impact sur le pilote
Faible avec un réseau correct. Risque résiduel : coupure de réseau sur le terrain → les contrôleurs ne peuvent pas valider ; à couvrir par la consigne et par la procédure des autorités, **à écrire avec la commune pilote**. SFD à réviser (§1.1, §4.4, §7.2, SCR-031, REC-007) et Annexe C (REC-007 sans objet).

---

## Fiche 2 — Verrou d'instruction de 15 minutes

### Règle initiale
- **SFD §3** : agent instructeur — « verrou 15 min ». **§4.3** : « verrouillage du dossier » avant instruction.
- **SFD §6.1** : verrou d'instruction **communal, exclusif, expirant après 15 min d'inactivité** ; **reprise forcée** possible par un administrateur habilité. **SCR-028** : verrou détenu par autrui = lecture seule ; verrou global pour la correction d'identité. **SCR-029** : « conflit de verrou ». **REC-011** : désactiver un agent coupe session **et verrous**.

### Décisions ultérieures
- **DT-041** (validée le 8 nov. 2026) : « **Aucun verrou fonctionnel de 15 minutes** » ; ordre de verrouillage base de données : événement → participation (exclusif) → zones → part relue sous verrou ; une part déjà décidée donne `OperationConflict(StaleState)`.
- **DT-024** (ordre des verrous), **DT-045** (correction d'identité : verrou pessimiste + `identity_version` optimiste), **DM-107** (droits relus sous verrou), **DM-111**.

### Fonctionnement actuel
- Deux agents peuvent ouvrir le même dossier ; **aucun indicateur** ne le montre.
- À l'enregistrement, le verrou exclusif de **participation** sérialise les décisions d'une commune pour un événement ; la contrainte `uniq_request_decision_part` garantit une seule décision par part. Le second agent reçoit : « Cette demande vient d'être traitée par ailleurs. Actualisez la fiche… » (la décision enregistrée est affichée). [C : `MunicipalRequestDecisionController`]
- Correction d'identité : version optimiste ; conflit = 409, **saisie conservée**, nouvelle attestation exigée. Une correction et une décision de la même commune ne s'exécutent jamais en même temps.
- Désactivation d'un agent : sa session est coupée à la requête suivante ; **aucun verrou à libérer** puisqu'il n'y en a pas.

### Risques
- **Intégrité** : aucun (aucune double décision possible) ; testé (`MunicipalRequestDecisionTest`).
- **Efficacité** : travail perdu quand deux agents instruisent la même demande ; le perdant apprend le conflit **à la validation**.
- **Débit** : le verrou de participation sérialise toutes les décisions d'une commune pour un événement (« risque de débit accepté en V1 », DT-041) ; à surveiller sur une grosse crise.

### Options
| | Option | Coût |
|---|---|---|
| A | **Conserver l'état actuel** et acter l'abandon du verrou visible en V1 | aucun |
| B | **Indicateur informatif** « consulté il y a N min par X » (sans blocage) | stockage d'une présence → **migration probable**, ou cache non fiable |
| C | **Verrou fort 15 min** avec reprise forcée et libération à la désactivation | migration (colonnes/table), tâche d'expiration, écrans, tests de concurrence ; **complexité élevée** |

### Recommandation
**Option A pour le pilote**, formalisée par une fiche DM alignant le SFD sur DT-041. Observer pendant le pilote la fréquence des conflits (retours d'agents). Si elle est gênante : **option B avant C**, qui ne bloque rien et suffit en général. L'option C est disproportionnée pour une petite équipe.

### Impact sur le pilote
Nul sur l'intégrité des décisions ; légère perte d'efficacité possible. Aucun développement requis. À valider en recette ergonomique : le message de conflit est-il compréhensible ?

---

## Fiche 3 — Révocation globale par l'organisatrice

### Règle initiale
- **SFD §2.1** : « la commune organisatrice peut révoquer **globalement** un laissez-passer accessible dans son périmètre ; elle ne consulte pas les dossiers qui concernent exclusivement d'autres communes » ; elle « ne modifie pas les droits délivrés par les autres communes ».
- **SCR-033** : périmètre local/global ; « globale : organisateur si dossier accessible ; locale : commune concernée ». **REC-010** (Annexe C) : l'organisatrice révoque un dossier visible ; « tous droits de ce passe invalides ».
- **CDC §4.1** : l'organisatrice ne peut ni suspendre ni clôturer la participation d'une autre commune ; **CDC §7.2** : « révocation individuelle définitive ; suspension de zone et de participation réversibles ».
- **Contradiction interne du SFD** : §2.1 interdit à l'organisatrice de modifier les droits des autres communes, tandis que SCR-033/REC-010 lui permettent de tous les invalider.

### Décisions ultérieures
- **DM-002** : autonomie de chaque commune participante. **DM-005** : aucun droit implicite de CWS.
- **DM-116 §2** : révocation **communale** (INSTRUCTOR ou MUNICIPALITY_ADMIN), de **sa propre** autorisation ; motif obligatoire ; irréversible ; **les autorisations des autres communes subsistent**.
- **DM-101 §5** : l'organisatrice ne voit que les dossiers de son propre territoire. **DM-056/DM-057** : suspension globale d'événement par l'organisatrice ; **DM-066** : zones suspendues non opérationnelles.
- **DM-113 §4** : remplacement exceptionnel du QR (MUNICIPALITY_ADMIN d'une commune ayant une autorisation) ; **DM-118** : la « révocation globale » des **liens** devient individuelle.

### Distinctions (les quatre outils existants)
| Outil | Qui | Portée | Réversible | État |
|---|---|---|---|---|
| **Révocation d'autorisation** | commune titulaire | **ses** droits seulement | non | livrée (DM-116) |
| **Suspension de zone** | commune propriétaire de la zone | tous les droits sur **cette zone**, même sous « Commune entière » | oui | livrée |
| **Suspension globale d'événement** | organisatrice (motif obligatoire) | **tous** les droits de l'événement, décisions locales intactes | oui | livrée (`EventLifecyclePageTest`) |
| **Clôture d'événement** | organisatrice, après clôture des participations ; CWS en exception (E4) | tout, définitif | non | livrée (normale) ; exceptionnelle à venir |
| *Remplacement du QR* | admin d'une commune titulaire | invalide l'ancien QR **pour tous** (compromission) | non | livré (DM-113) |

### Fonctionnement actuel
L'organisatrice **n'a pas** de révocation globale. Face à une fraude, elle peut : révoquer sa propre autorisation, suspendre ses zones, **suspendre l'événement entier** (frein d'urgence, réversible), ou demander à un administrateur d'une commune titulaire de remplacer le QR. Elle ne peut pas retirer le droit d'une autre commune.

### Options
| | Option | Effet |
|---|---|---|
| A | **Confirmer l'absence** de révocation globale ; l'organisatrice dispose de la suspension d'événement | cohérent avec DM-002/DM-116 ; aucun développement |
| B | Révocation globale par l'organisatrice sur les dossiers « accessibles » | **brise l'autonomie** (DM-002) et le périmètre de visibilité (DM-101 §5) ; exige de nouvelles règles de visibilité et d'audit multi-communes |
| C | A + **signalement** : l'organisatrice peut demander aux autres communes titulaires de réviser un droit (message informatif) | pas de pouvoir sur autrui ; nouveau flux de notification (V2) |

### Recommandation
**Option A.** Le frein d'urgence légitime d'une organisatrice est la **suspension globale réversible**, déjà livrée et testée. Retirer le droit d'une autre commune contredit son autonomie décisionnelle, principe fondateur du produit. Acter par fiche DM : « pas de révocation globale par l'organisatrice en V1 » ; supprimer **REC-010** et réviser SFD §2.1/SCR-033. Option C à considérer en V2.

### Impact sur le pilote
Aucun développement. Dans une situation multicommune, une fraude touchant plusieurs communes se traite par appel entre administrateurs (à écrire dans la consigne de la commune pilote) ou par suspension de l'événement.

---

## Fiche 4 — L'état « indéterminé » du contrôle

### Règle initiale
- **CDC §9.1** : « valide (vert) s'il existe au moins un droit actuellement actif, invalide (rouge), ou **indéterminé / impossible à vérifier (orange)** ». **CDC §9.2** : hors ligne, « valide selon dernière synchronisation » ; titre inconnu localement = **indéterminé, jamais refus automatique**.
- **SFD §7.3 / SCR-035 / SCR-037** : trois couleurs vert / rouge / orange ; PWA hors ligne **toujours orange** (« vérification non actualisée »), alerte renforcée au-delà de 12 h, interdite au-delà de 24 h ; « sans PWA ni serveur : *Vérification impossible — service indisponible*. Aucun droit présumé valide » ; **SCR-034** : serveur indisponible = jamais de feu vert implicite.
- **Situations d'origine de l'orange** : (a) PWA hors ligne ; (b) titre inconnu localement ; (c) vérification impossible (serveur ou réseau) ; (d) droits à confirmer par le contrôleur (zone à rapprocher).

### Décisions ultérieures
- **DM-115** : contrôle public en ligne, **état recalculé à chaque consultation**, plus de PWA ni d'appareils ; un laissez-passer n'est jamais présenté comme entièrement valide si seules certaines zones le sont.
- **DM-128** (07.3) : messages nationaux fixes pour les erreurs ; **DT-055** : échec de stockage du limiteur = 503 ; **DM-113** : jeton remplacé = 410.

### Fonctionnement actuel (par situation) [C : `PublicVerdict`, `PublicVerificationExtension`, gabarits]
| Situation | Traitement | Ton / message |
|---|---|---|
| Au moins une zone valide, toutes valides | `ACTIVE_RIGHTS` | **vert** « Droits actifs » |
| Droits actifs mais **certaines zones seulement** | `ACTIVE_RIGHTS` | **orange** : « vérifiez la zone concernée » |
| Zone, participation, commune ou événement **suspendu** | `SUSPENDED` | **orange** « Droits suspendus… peuvent redevenir actifs » |
| Droits à venir / hors créneau | `UPCOMING` | gris/impossible « Aucun droit actif · droits à venir » |
| Expiré / révoqué / aucun droit | `EXPIRED` / `REVOKED` / `NO_ACTIVE_RIGHTS` | gris ou **rouge** |
| Événement clôturé | `EVENT_CLOSED` | « Événement terminé » (200, sans donnée personnelle) |
| QR remplacé | 410 | « Il n'est plus valable… » |
| Jeton inconnu, mal formé, contrôle désactivé, purgé | 404 uniforme | avertissement « QR non reconnu… **ce n'est pas une validation** » ; ne pas conclure à une fraude |
| Trop de demandes depuis le réseau | 429 | « temporairement indisponible… **n'est pas un refus d'autorisation** » |
| Serveur ou stockage indisponible | 503 | idem ; « appliquez la procédure de terrain de votre commune » |
| Hors ligne côté téléphone (pas de réseau) | **la page ne se charge pas** | aucune page ; le navigateur affiche son erreur — aucun faux vert possible |

**Ce qui a disparu** : tout ce qui relevait de la PWA — « valide selon dernière synchronisation », seuils 12 h/24 h, « titre inconnu localement ». Le « jamais de faux vert » est préservé : aucune situation d'incertitude n'affiche de ton vert.

### Options
| | Option | Effet |
|---|---|---|
| A | **Conserver** ; réviser SFD/CDC : l'« indéterminé » devient « **pas de verdict** » (404/429/503/410 : pages d'avertissement sans verdict) et « droits partiels/suspendus » (orange) | aucun développement |
| B | Ajouter un message dédié « Vérification impossible » côté navigateur hors ligne (script/Service Worker) | **PWA/JS réintroduits**, contraire à DM-115 §5 et à la CSP `default-src 'none'` |
| C | Introduire un 3ᵉ état affiché « Indéterminé » pour les erreurs serveur | trompeur : une page d'erreur 5xx n'est pas un verdict ; **aucune donnée** à afficher |

### Recommandation
**Option A.** Le comportement actuel couvre toutes les situations d'incertitude, sans risque de faux vert. À réviser dans le SFD/CDC : définir deux familles — *verdict* (vert/orange/gris/rouge) et *absence de verdict* (pages d'avertissement). Ajouter à la consigne de terrain (08.2) : « aucune page = aucune validation ». Recette sur smartphone réel ultérieure pour le cas hors-ligne.

### Impact sur le pilote
Nul sur le code ; le contrôle reste désactivé (E6). Le point à surveiller est **humain** : former à la différence entre « droits suspendus » (orange, peut revenir) et « révoqués » (rouge).

---

## Fiche 5 — Notification de refus

### Règle initiale
- **SFD §7.1** (tableau) : refus → e-mail « motif public transmis » ; SMS « selon configuration ». **SCR-030** : « motif obligatoire **transmis au citoyen** » ; « refus horodaté, **notifié selon canaux actifs** ». **SFD §4.2/SCR-026** : aucun suivi public ; confirmation à l'écran avec référence ; **CDC §8.1, §6.1** : décision sur le dossier disponible, échange hors plateforme.
- **Notifications du CDC** : e-mail ; SMS prévu au modèle mais non connecté en V1.

### Décisions ultérieures
- **DM-110** : motifs du référentiel ; **le commentaire libre est interne** à la commune ; **« Aucune notification au citoyen avant le LOT 07 »**. Le commentaire n'entre jamais dans l'audit (DT-037).
- **DM-117** : notifications **du bénéficiaire** (laissez-passer) ; sans adresse e-mail, aucun envoi, cas tracé « non distribuable », remise en main propre ; « Le SMS relève du LOT 07 » (jamais livré).
- **DT-049** : outbox transactionnelle ; **DM-114/DM-118** : lien bénéficiaire distinct du QR. **DM-099 §4** : attestation de confidentialité à la saisie agent.

### Fonctionnement actuel
- **Canal disponible : e-mail uniquement.** SMS : aucun code.
- **Accusé de réception** (`ReceiptNotifier`) : référence + nom d'événement, envoyé à l'adresse fournie, **sans lien, sans QR, sans donnée du dossier** ; échec ignoré (n'annule pas le dépôt).
- **Notifications de laissez-passer** : 11 types (`NotificationType`), via l'**outbox** (`notification_outbox`), **ligne liée à `permitId` non nul** ; états en attente/envoyée/abandonnée/`NO_RECIPIENT` ; distributeur `app:notifications:dispatch` (unité systemd livrée, non installée).
- **Refus** : aucun type, aucun modèle ; un refus n'a **pas de laissez-passer** (donc pas de `permitId`). Le demandeur n'a **aucun** moyen de connaître sa décision, hors contact avec la mairie.
- Le demandeur fournit **e-mail ou téléphone, au moins un** : une part des demandeurs n'a **aucun canal** disponible (téléphone seul).

### Données accessibles et confidentialité
- L'adresse e-mail est **déclarative et non vérifiée** : elle peut appartenir à un tiers, être erronée ou partagée (famille). Tout contenu envoyé est donc **exposé à une personne non vérifiée**.
- **Contenu minimal proposé** : référence `DEM-…`, nom de l'événement, **commune**, **libellé du motif du référentiel** (jamais le commentaire libre), invitation à contacter la mairie. **Pas** de nom, d'adresse, de plaque, de lien, de QR, de pièce.
- Mécanismes existants à reprendre : adresse relue à l'envoi, absence de pixel de suivi, audit sans adresse (DT-037/DT-049), état `NO_RECIPIENT`, non-envoi si e-mail absent, **dédoublonnage** (une notification par part refusée).
- Mécanisme à décider : **un refus partiel** (approuvé par B, refusé par A) — le message de A ne doit pas révéler la décision de B ; l'approbation de B a déjà son propre e-mail avec lien (DM-117).
- Cohérence du motif « Demande en doublon » : il révèle qu'une autre demande existe ; à confirmer qu'il reste acceptable dans un e-mail à une adresse non vérifiée.

### Options
| | Option | Avantages / limites |
|---|---|---|
| A | **Envoi direct** à la manière de `ReceiptNotifier` (best-effort, hors transaction) | aucun schéma ; **pas de relance, pas de trace, perte silencieuse** si SMTP en échec |
| B | **Outbox généralisée** : nouveau type `REQUEST_REFUSED`, clé `REQUEST_REFUSED:{part}`, rattachée à la **part de demande** (et non au laissez-passer) ; mêmes réessais, `NO_RECIPIENT`, audit | cohérent avec DM-117/DT-049 ; **migration probable** (lien non lié à `permit_id`) → cadrage et approbation |
| C | Aucun message ; **consigne** : l'agent informe le demandeur par téléphone/guichet ; **indicateur** « refus à communiquer » dans la fiche | aucun développement ; dépend de l'agent ; charge en crise |
| D | Message à l'écran à la confirmation « vous serez informé par e-mail ; sinon contactez la mairie » | utile en complément, jamais seul |

### Recommandation
**B + D**, avec **C comme repli explicite pour les demandeurs sans e-mail** : afficher dans la fiche de l'agent « Aucun e-mail : informer le demandeur » (comme la remise manuelle de lien existante). Raison : le refus est aussi important pour le citoyen que l'approbation ; l'option A perdrait silencieusement des messages exactement quand la messagerie est instable (crise). Le contenu reste **minimal** (liste ci-dessus). Prérequis : une **fiche DM** remplaçant la mention « aucune notification avant le LOT 07 » (DM-110), l'approbation de la migration au cadrage de 08.5, et la validation du **libellé** (juridique).

### Impact sur le pilote
Élevé sans correction : un refusé ne reçoit rien, ce qui génère des rappels en mairie. Avec la solution recommandée : un lot **S/M** (un type, un gabarit, une table ou une colonne), mais **dépendant du SMTP réel** (installation finale) pour être effectif. Sans SMTP, seul le repli C fonctionne.

---

## Synthèse des recommandations

| Fiche | Recommandation | Développement | Migration |
|---|---|---|---|
| 1 Papier 48 h | **Reporter** (V2) ; consigne d'indisponibilité en 08.2 | non | non |
| 2 Verrou 15 min | **Abandon acté** (DT-041) ; observer ; indicateur informatif en V2 si besoin | non | non |
| 3 Révocation globale | **Pas de révocation globale** ; frein = suspension globale d'événement | non | non |
| 4 État indéterminé | **Conserver** ; réviser SFD/CDC (verdict / absence de verdict) | non | non |
| 5 Refus | **Outbox généralisée + repli agent** ; contenu minimal | 08.5 | **à cadrer** |

**Décisions attendues** : valider chaque recommandation (ou en choisir une autre) ; confirmer que le libellé et le contenu du message de refus (fiche 5) peuvent être proposés au conseil juridique ; autoriser la rédaction des fiches DM correspondantes et le bandeau de révision SFD/CDC.
