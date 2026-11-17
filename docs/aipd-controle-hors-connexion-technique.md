# Dossier technique pour l'AIPD — contrôle hors connexion (LOT 09)

**Date** : 17 novembre 2026 · **Statut** : **dossier technique de travail**. **Il ne remplace ni l'analyse d'impact elle-même, ni l'avis du DPO, ni la validation juridique** ; il fournit aux responsables de traitement (communes) et à leur DPO les faits techniques, les mesures réelles et leurs limites. Aucune base légale n'y est qualifiée.
**Références** : [lot-09-controle-hors-connexion-cadrage.md](lot-09-controle-hors-connexion-cadrage.md), DM-129 à DM-132, DT-056, DM-115 §7, DM-117, DM-120. **Rien n'est développé ni déployé.**

## 1. Description du traitement
Contrôle de laissez-passer par des agents sur smartphone, **y compris sans réseau**. Une **copie temporaire minimale** des droits d'une commune est synchronisée sur un terminal approuvé ; l'application la consulte localement pour afficher un verdict **qualifié** (jamais temps réel). Le contrôle **en ligne** public existant (DM-115) est inchangé.

## 2. Acteurs et rôles (à qualifier par les responsables)
| Acteur | Hypothèse technique | À valider |
|---|---|---|
| Commune (organisatrice ou participante) | responsable du traitement de ses laissez-passer | rôle juridique, base légale |
| CWS | éditeur, **sous-traitant** probable | contrat, instructions |
| Contrôleurs (forces de l'ordre, police municipale, bénévoles) | utilisateurs des terminaux, sans compte | **statut** (destinataires ? sous-traitants ?), convention d'usage, habilitation |
| Administrateur communal | active et approuve les appareils | — |

## 3. Données traitées (inventaire)
| Donnée | Sur l'appareil | Sur le serveur (tables de contrôle) | Remarque |
|---|---|---|---|
| Nom, prénom du bénéficiaire | oui (valides ou à venir, commune activatrice) | non (lus à la construction du jeu) | donnée personnelle |
| Plaques d'immatriculation | oui | non | donnée personnelle |
| Zones, fenêtres de validité UTC, état | oui | non | |
| Empreinte SHA-256 du jeton du QR | oui | non | pseudonyme ; ne permet pas de retrouver ni de forger le jeton |
| Empreintes de laissez-passer révoqués/remplacés | oui, **sans nom** (bornées) | non | pseudonymes, à qualifier |
| Adresse, e-mail, téléphone, pièces, motif | **jamais** | — | DM-115 §3 |
| Secret d'appareil | oui | **empreinte seulement** | |
| Libellé d'appareil (« Barrage Nord »), plateforme grossière, dates d'activité | — | oui | **aucune donnée sur la personne du contrôleur** ; vigilance sur la saisie libre du libellé |
| Journal de scan | **aucun** | **aucun** | DM-127, DM-132 |

## 4. Finalité, nécessité, proportionnalité (éléments techniques)
- **Nécessité** : permettre le contrôle sans réseau pendant une crise (décision métier du 17 nov. 2026) ; le rapprochement nom/plaques impose la présence de ces données (DM-115).
- **Minimisation** : commune seule ; valides ou à venir ; nom + plaques + fenêtres ; empreinte au lieu du jeton ; horizon de fenêtres = durée de confiance.
- **Alternatives écartées** : QR signés (identité absente ou données personnelles dans le QR, QR instable, réémission) ; papier 48 h (pas de preuve, pas de visibilité des révocations).

## 5. Durées de conservation
| Support | Durée |
|---|---|
| Terminal — données identifiantes | jusqu'à `hard` (pilote 12 h ; plafond 24 h), purge **à la prochaine ouverture** ; à l'expiration de l'appareil, à la clôture ou à la désactivation |
| Terminal — empreintes révoquées | jusqu'à la prochaine synchronisation, l'expiration de l'appareil ou la fin de l'événement |
| Serveur — `control_activation`, `control_device` | avec le dossier de l'événement ; purge d'événement (DM-120) |
| Aucune donnée de contrôle dans les journaux | — |

## 6. Mesures de sécurité : ce qui est garanti et ce qui ne l'est pas
| Mesure | Garantie | Limite |
|---|---|---|
| Authentification d'appareil (secret 256 bits, empreinte serveur) | oui | un secret volé sur un appareil déverrouillé est utilisable jusqu'à désactivation/expiration |
| **Approbation manuelle** avec code d'appariement | oui | dépend de la vigilance de l'administrateur |
| Codes d'activation (60 bits, 30 min, usage atomique, limiteurs) | oui | un code divulgué ouvre seulement l'**enregistrement** d'un appareil en attente, **sans donnée** |
| **Isolation par commune** (jeu construit côté serveur) | oui, testée | — |
| Jeu limité à `[sync, sync + hard]`, empreintes | oui | la donnée personnelle reste lisible sur un appareil déverrouillé |
| Intégrité (condensat racine, version, atomicité) | oui | protège contre troncature et corruption, **pas** contre un terminal compromis |
| Horloge (signaux de §25 du cadrage) | partielle | manipulation **vers l'avant, application fermée** non détectée |
| Désactivation à distance | partielle | **uniquement à la reconnexion** |
| Purge locale | partielle | **exige l'ouverture de l'application** ; aucune garantie sur un appareil qui ne se reconnecte jamais |
| **Chiffrement applicatif** | **non fourni** | **non annoncé** : une clé locale n'apporte pas de protection démontrée contre une extraction du profil |
| Chiffrement du système et verrouillage du terminal | hors contrôle de Civipass | **consigne obligatoire**, non vérifiable par une PWA |
| Origine dédiée (sous-domaine) et CSP stricte | à installer (cible) | tant que l'origine est partagée, une faille de script ailleurs exposerait le stockage |

## 7. Risques pour les personnes (analyse de travail ; qualification à faire par le DPO)
| Événement redouté | Sources / scénarios | Mesures | Gravité (indic.) | Vraisemblance (indic.) | Risque résiduel |
|---|---|---|---|---|---|
| **Accès illégitime** aux noms et plaques d'une commune | perte/vol d'un terminal déverrouillé ou compromis ; script injecté (origine partagée) ; sauvegarde du terminal | minimisation, durée, purge, désactivation, verrouillage, origine dédiée | moyenne (identité + véhicule) | faible à moyenne | **moyen** (non réductible sans connexion) |
| **Décision erronée** (laissez-passer révoqué accepté) | délai de visibilité des révocations | durée courte, temps réel préféré, `STRICT`, consigne | élevée pour un cas sensible | faible à moyenne | **à accepter explicitement (R-OFF-01)** |
| **Décision erronée inverse** (valide jugé « non valide » ou « indéterminé ») | droits prolongés/ajoutés après synchronisation ; horloge ; jeu absent | « indéterminé » ≠ refus, consigne, resynchronisation | moyenne (gêne d'accès) | moyenne | acceptable |
| **Conservation excessive** | appareil jamais rouvert | purge à l'ouverture, expiration, minimisation | moyenne | moyenne | **moyen** |
| **Rectification/effacement non répercutés immédiatement** | droits des personnes | effet à la synchronisation suivante (≤ `hard`) ; information | faible | certaine (≤ `hard`) | à informer |
| **Détournement du code d'activation** | code divulgué | approbation manuelle, limiteurs | faible (aucune donnée sans approbation) | faible | faible |
| **Usage hors finalité** par un contrôleur | consultation non justifiée | absence de recherche libre (scan seul) ; pas de liste consultable | moyenne | faible | à encadrer par la convention d'usage |

*Remarque* : le contrôle **en ligne** public expose déjà nom et plaques à **toute personne détenant le QR** (DM-115 §7). La copie locale élargit l'exposition à **toute personne ayant accès à un terminal approuvé** et à **l'ensemble des laissez-passer valides de la commune** (et non à un seul QR) : c'est le changement de nature du risque à apprécier.

## 8. Droits des personnes
| Droit | Effet |
|---|---|
| Information | le texte de confidentialité du dépôt (DM-099 §4) et l'information de DM-117 doivent mentionner la **copie temporaire sur les terminaux de contrôle** (à rédiger/valider juridiquement) |
| Accès, rectification | prise en compte à la synchronisation suivante |
| Effacement | disparition au jeu suivant (≤ `hard`) ; purge locale à l'ouverture |
| Opposition | limitée par la mission ; à qualifier |
| Violation de données (perte/vol) | runbook : liste des données exposées et période ; **appréciation de la notification par le responsable de traitement** |

## 9. Points à instruire par les responsables et le DPO
1. Base légale et finalité du traitement hors connexion ; articulation avec DM-115 §7 et DM-117.
2. Qualification des contrôleurs (forces de l'ordre, police municipale, bénévoles) ; **convention ou habilitation** ; engagements de confidentialité.
3. Appréciation du **risque résiduel** du §7 ; nécessité d'une consultation préalable de l'autorité de contrôle si le risque résiduel reste élevé.
4. Information des personnes (texte).
5. Qualification des empreintes de laissez-passer révoqués (donnée pseudonymisée ?).
6. Durée de confiance retenue et sa justification (pilote : 12 h).
7. Politique de signalement et de traitement des incidents (perte/vol).
8. Inscription au registre des traitements ; clauses de sous-traitance avec CWS.

## 10. Conditions préalables à l'usage de données réelles (rappel)
(1) AIPD validée avec le DPO ; (2) conditions de sécurité satisfaites (approbation manuelle active, origine dédiée, interrupteur, consigne de verrouillage) ; (3) **acceptation écrite de R-OFF-01** ; (4) textes validés ; (5) recette sur appareils réels (09.7). **Jusque-là : données fictives uniquement.**

## Annexe A — Modèle de constat d'acceptation du risque R-OFF-01

> **Constat d'acceptation du risque résiduel — contrôle hors connexion (mode STANDARD)**
> Commune / autorité : ………… · Événement : ………… · Date : …………
> Nous avons pris connaissance que, en mode STANDARD, un laissez-passer révoqué, suspendu, remplacé ou dont l'événement a été clos par anticipation **après** la dernière synchronisation d'un terminal peut continuer à s'afficher « valide selon la dernière synchronisation » sur ce terminal pendant **au plus … heures** (durée de confiance configurée), et que cette limite **ne peut pas être supprimée sans connexion**.
> Nous avons pris connaissance des mesures de réduction (contrôle en ligne préféré, mode STRICT disponible, consigne d'escalade, procédure de révocation urgente) et de leurs limites.
> ☐ Nous acceptons ce risque pour le périmètre : ………… ☐ Nous choisissons le mode STRICT pour : …………
> Signataires (responsable de traitement / DPO / autorité de contrôle utilisatrice) : …………

## Annexe B — Données exposées en cas de perte ou de vol (liste à joindre au runbook)
Nom, prénom, plaques, libellés de zones, fenêtres de validité, état, empreintes de jetons (non exploitables), pour **les seuls laissez-passer valides ou à venir de la commune activatrice**, à l'instant de la dernière synchronisation (≤ `hard`).
