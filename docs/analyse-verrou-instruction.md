# Analyse — verrou d'instruction visible de 15 minutes

**Date** : 17 novembre 2026 · **Statut** : analyse pour décision ([DM-137](decisions/metier/DM-137-pas-de-verrou-visible-d-instruction-option-a.md), **validée le 18 novembre 2026 : option A**). Analyse conservée comme justification.

## 1. Spécification initiale (exacte)
- **SFD §3** (agent instructeur) : « Verrou 15 min ; pas d'accès aux dossiers étrangers ». **§4.3** : « verrouillage du dossier » avant l'instruction.
- **SFD §6.1** : « Le verrou d'instruction est **communal, exclusif**, expirant après **15 min d'inactivité** ; **reprise forcée possible par administrateur habilité** ». Correction d'identité : **verrouillage global** des champs partagés (organisatrice et communes ayant approuvé).
- **SCR-028** : verrou détenu par autrui = **lecture seule** ; l'administrateur peut le reprendre. **SCR-029** : erreur « conflit de verrou ». **REC-011** : désactiver un agent coupe session **et verrous**.
- Intention : **éviter le travail perdu** (deux agents sur le même dossier) et les décisions contradictoires.

## 2. Décision ultérieure applicable
**DT-041** (validée le 8 novembre 2026) : « **Aucun verrou fonctionnel de 15 minutes** » ; ordre de verrouillage en base événement → participation → zones → part relue sous verrou ; conflit = `OperationConflict(StaleState)`. Elle abandonne le verrou **fonctionnel** mais n'a pas été répercutée dans le SFD ni arbitrée comme **décision métier** (d'où la décision ouverte).

## 3. Protections actuellement en place (vérifiées)
| Opération | Protection | Comportement en cas de collision |
|---|---|---|
| Approuver / refuser une part | verrou exclusif de **participation** (sérialise les décisions d'une commune pour un événement) + part relue sous verrou + contrainte unique `uniq_request_decision_part` | une seule décision ; l'autre agent reçoit « Cette demande vient d'être traitée par ailleurs. Actualisez la fiche… » et la décision enregistrée s'affiche |
| Délivrance directe | même moteur, atomique ; confirmation relue, agent émetteur seul | refus uniforme pour un autre agent |
| Correction d'identité | verrou pessimiste de la demande + **version optimiste** (`identity_version`) + participation ACTIVE relue | 409, **saisie conservée**, valeurs enregistrées affichées, nouvelle attestation |
| Modifier / prolonger / révoquer des droits, plaques, zones | révisions immuables sous le verrou du dossier (ordre DT-024), garde de révision | une seule révision gagne ; la seconde est refusée ou constate l'état courant |
| Désactivation d'un agent | session coupée à la requête suivante, droits relus en base à chaque requête | aucun verrou à libérer (il n'y en a pas) |
**Intégrité des données : garantie** (testée : `MunicipalRequestDecisionTest`, concurrence réelle sur MySQL).

## 4. Ce que le verrou fonctionnel apporterait en plus (et ne remplace pas)
Uniquement le **confort** : savoir **avant** de saisir qu'un collègue travaille sur le dossier, et éviter de **perdre une saisie** de décision (zones, durée) quand l'autre a déjà décidé. Il **ne remplacerait aucune** protection de la base de données : un verrou à durée limitée n'est jamais une garantie d'exclusion.

## 5. Risques de concurrence résiduels
- **Travail perdu** (faible) : deux agents instruisent la même demande ; le perdant l'apprend à la validation. Impact limité (saisie courte).
- **Débit** : le verrou de participation sérialise toutes les décisions d'une commune pour un événement ; sur une crise avec de nombreux instructeurs simultanés, les décisions s'exécutent l'une après l'autre (« risque de débit accepté en V1 », DT-041). **À mesurer** pendant la recette, non supposé.
- **Messages de conflit** : compréhensibles ? à valider avec de vrais agents.

## 6. Options
| | Option | Migration | Coût | Effet |
|---|---|---|---|---|
| **A** | Conserver l'état actuel, **acter** l'abandon du verrou visible en V1 | non | nul | aucun indicateur |
| **B'** | **Indicateur de présence informatif**, sans blocage : « en cours de consultation par *Prénom N.* depuis N min », conservé dans le **cache applicatif** (clé par part, durée de vie 15 min, renouvelée à l'ouverture de la fiche) | **non** (même cache que les limiteurs) | faible | prévient avant la saisie ; **non fiable** (perdu au vidage du cache, mono-instance) ; **n'empêche rien** |
| **B** | Indicateur persistant (table de présence) | **oui** | moyen | idem B', fiable mais plus lourd (écritures à chaque ouverture) |
| **C** | **Verrou fort** de 15 min avec reprise forcée par l'administrateur, libération à la désactivation (SFD §6.1) | **oui** (colonnes ou table) | élevé : expiration, reprise, libération, écrans, tests de concurrence | exclusion fonctionnelle ; risque d'**incident opérationnel** (dossier bloqué par un agent parti, reprise forcée à gérer en pleine crise) |

## 7. Recommandation
**Option A pour le pilote**, avec **B' en amélioration optionnelle à faible coût** si la recette montre des collisions : indicateur informatif **sans migration** et **sans blocage**. **Ne pas retenir C** : il ajoute de la complexité et un mode de défaillance (verrou orphelin) en situation de crise, pour un gain de confort que B' procure sans exclusion ; l'intégrité reste garantie par la base. À tout moment, B ou C pourront être décidés après observation du pilote.
- **À mesurer pendant la recette** : fréquence des conflits de décision, débit des décisions simultanées d'une même commune, compréhension du message de conflit.
- **Arbitrage demandé** : acter A (aligner le SFD sur DT-041) **ou** A + B' (indicateur informatif, hors migration, à inscrire dans le lot 08.1/08.7).

## 8. Impact sur le pilote
Aucun sur l'intégrité. Perte d'efficacité possible mais bornée. Aucun développement requis pour A ; B' représenterait un petit incrément (service de présence en cache, une ligne sur la fiche, tests).
