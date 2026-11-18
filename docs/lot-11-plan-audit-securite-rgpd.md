# LOT 11 — Audit sécurité et RGPD : plan (cadrage à valider)

Statut : **proposé, non validé**. Aucun développement avant validation. Date : 18 novembre 2026.
Le lot est un **audit** : constats, preuves, registre. Les corrections sont des sous-lots séparés, cadrés ensuite. Le registre de référence est `docs/registre-securite.md`.

Légende. **Mode** : A = automatisable (test rejouable), M = manuel (revue / essai à la main), J = DPO / juriste. **Crit.** : C critique, H haute, M moyenne, B basse.

## 1. Sécurité applicative

| # | Sujet | Risque | Protection existante | Vérification | Mode | Crit. | Preuve attendue | Décision |
|---|---|---|---|---|---|---|---|---|
| S1 | Isolation inter-communes | lecture ou écriture d'une autre commune | filtrage de périmètre serveur, tests à identifiants forgés | matrice exhaustive routes × rôles × identifiants forgés (`municipalityId`, `eventId`, `zoneId`, `permitId`) ; recherche de requêtes sans filtre | A+M | C | rapport de matrice, 0 écart | non |
| S2 | Autorisation (voters, rôles cumulables, CWS sans droit communal implicite) | élévation de privilèges | voters, tests de permissions | inventaire des routes et de leur contrôle d'accès ; test d'une route sans voter | A | C | liste routes ↔ contrôle, exceptions justifiées | non |
| S3 | Authentification, mots de passe, invitations, réinitialisation | prise de compte, énumération | hash `auto`, limiteur 5/15 min, correctif SEC-001, jetons à usage unique | tests d'énumération (message, durée, e-mail), expiration et rejeu des jetons | A+M | H | rapport + mesures | politique de mot de passe (longueur, 2FA) |
| S4 | Sessions | fixation, durée excessive, partage | durée d'inactivité 30 min, absolue 12 h/8 h, M6 (`gc_maxlifetime`) | essai de fixation, d'expiration, de déconnexion ; vérification Linux cible | A+M | H | traces + relevé de configuration cible | durée absolue 12 h acceptable ? |
| S5 | CSRF, XSS, en-têtes, CSP | injection, détournement d'action | jetons CSRF, Twig auto-échappé, CSP, en-têtes de sécurité | formulaires sans jeton, balayage des `|raw`, scan des en-têtes (vhost gestion et contrôle) | A | H | sortie du scan, liste des `|raw` justifiés | non |
| S6 | Dépôt public (sans compte) | abus, envoi massif, fichiers piégés | limiteurs, brouillon 30 min, purges, contrôle des pièces | essai de gros fichiers, faux types MIME, volume, rejeu de jeton | A+M | H | rapport d'essais | seuils de volume (arbitrage) |
| S7 | QR et vérification publique | prédiction, fuite de données | QR opaque stable, vérification derrière interrupteur (0) | entropie et unicité des jetons, réponses génériques, limiteur, aucune donnée personnelle dans le QR | A | C | tests + analyse | non |
| S8 | Contrôle hors connexion (PWA) | appareil perdu, jeton volé, données périmées | confiance bornée, mode `STRICT`, suppression locale, origine dédiée G1–G3 | revue du modèle de menace, essai de perte d'appareil, de révocation, de veille | M | C | note de menace + essais (voir 09.7) | **R-OFF-01** (acceptation écrite) |
| S9 | Séparation des origines (gestion / contrôle) | confusion de contexte | G1–G3, garde-fou Nginx, 421 | rejouer `verify-control-vhost.sh` et `e2e-control-origins` sur la cible | A | H | CONFORME sur serveur cible | non |
| S10 | Sonata Admin | exposition en écriture, fuite | lecture seule, accès restreint | inventaire des admins, essai d'écriture, périmètre par rôle | A+M | H | rapport | non |
| S11 | Dépendances et configuration | CVE, secrets | Composer, `.env` hors dépôt | `composer audit`, audit des paquets JS, recherche de secrets dans l'historique, `APP_DEBUG`, en-têtes serveur | A | H | sorties d'audit, 0 vulnérabilité haute non traitée | politique de mise à jour |
| S12 | Journalisation et audit | données personnelles dans les journaux, absence de trace | `AuditTrail` en transaction, journaux Nginx sans secrets | échantillonnage des journaux, vérification de la traçabilité des modifications | A+M | M | échantillons expurgés | durée de conservation des journaux |
| S13 | Sauvegardes, restauration, exploitation | perte, fuite de sauvegarde | procédures partielles | essai de restauration sur base vierge, chiffrement et droits des sauvegardes | M | H | procès-verbal d'essai | RPO / RTO visés |

## 2. Protection des données (RGPD)

| # | Sujet | Risque | Protection existante | Vérification | Mode | Crit. | Preuve attendue | Décision |
|---|---|---|---|---|---|---|---|---|
| D1 | Registre des traitements, rôles (responsable / sous-traitant) | obligation non satisfaite | documentation partielle | établir le registre par traitement, confirmer le rôle de la commune et de CWS | J | H | registre signé | **qualification des rôles** |
| D2 | Inventaire des données et finalités | collecte excessive | modèle de données, minimisation | tableau champ → finalité → base légale → durée | M+J | H | tableau validé | bases légales |
| D3 | Durées de conservation et règle 8 | conservation excessive ou suppression prématurée | règle 8, DM-120 | relier chaque donnée à une durée justifiée ; vérifier la suppression contrôlée et tracée | M+J | C | tableau de durées + essai de purge | durées définitives |
| D4 | AIPD (contrôle hors connexion, plaques, pièces d'identité éventuelles) | traitement à risque non évalué | `docs/aipd-controle-hors-connexion-technique.md` (volet technique) | compléter et faire valider l'AIPD | J | C | AIPD validée | **validation DPO** |
| D5 | Cookies et brouillon public | qualification erronée | cookie temporaire, texte variante B, effacement | qualifier juridiquement (exemption ou consentement) et décider de la publication du texte | J | H | avis écrit | **qualification du cookie** |
| D6 | Droits des personnes (accès, rectification, effacement, opposition) | non-réponse dans les délais | partiel | procédure et essai d'exercice d'un droit de bout en bout | M+J | H | procédure + essai | circuit de traitement |
| D7 | Information des personnes | transparence insuffisante | textes provisoires | relire les mentions (dépôt public, e-mails, refus) | J | H | mentions validées | **texte de refus, voies de recours** |
| D8 | Sous-traitants et hébergement | transfert hors UE, absence de contrat | non défini | lister hébergeur, e-mail, supervision ; clauses | J | H | contrats, localisation | choix d'hébergeur |
| D9 | Violations de données | délai de notification manqué | aucune procédure formalisée | rédiger et essayer la procédure (détection, 72 h) | M+J | H | procédure + exercice | responsable désigné |
| D10 | Pièces justificatives et données sensibles | accès excessif | accès par rôle, suppression à l'échéance | vérifier le stockage, l'accès, la purge des pièces | A+M | C | essais | durée de conservation |
| D11 | Notifications et e-mails | fuite par message, envoi non voulu | interrupteur à 0 | relire le contenu et les destinataires, essai en environnement fictif | M | M | échantillons | texte validé |

## 3. Contrôle hors connexion

Reprise du 09.7 : le protocole sur smartphones réels reste inchangé.

| # | Sujet | Vérification | Mode | Crit. | Décision |
|---|---|---|---|---|---|
| H1 | Révocation après dernière synchro | essai de délai réel, texte de la consigne | M | C | R-OFF-01 |
| H2 | Perte ou vol de l'appareil | verrouillage, suppression locale, durée de vie du jeton | M | C | durée de confiance |
| H3 | Données locales (contenu, chiffrement, effacement) | inspection du stockage navigateur, effacement vérifié | M | H | non |
| H4 | Veille, retour réseau, horloge faussée | essais sur Android et iPhone | M | H | non |
| H5 | Consignes et messages de contrôle | validation par le terrain | M+J | H | validation des consignes |

## 4. Répartition

- **Automatisable** (rejouable dans `bin/qa`) : S1, S2, S5, S7, S9, S11, une partie de S3, S4, S6, S10, S12.
- **Manuel** (essais, revue) : S8, S13, H1–H4, essais de D6, D9, D10.
- **DPO / juriste** : D1, D4, D5, D7, D8, part de D2, D3, D6, D9, H5.

## 5. Découpage proposé

1. **11.1** Automatisable : matrices d'isolation et d'autorisation, scans d'en-têtes, audit des dépendances, recherche de secrets. Sortie : registre enrichi.
2. **11.2** Revue manuelle : sessions, dépôt public, Sonata, journaux, modèle de menace du contrôle hors connexion.
3. **11.3** Dossier RGPD : registre, tableau champ → finalité → durée, AIPD complétée, procédure de violation, pour validation DPO.
4. **11.4** Vérifications sur serveur cible (SEC-001, M6, Nginx 421, e2e deux origines), après autorisation d'infrastructure.
5. **11.5** Synthèse : registre arrêté, risques résiduels, décisions d'acceptation. Les corrections font l'objet de sous-lots cadrés à part.

Chaque sous-lot : commit local distinct, `bin/qa full`, aucune migration, aucun push sans autorisation.

## 6. Décisions nécessaires avant exécution

1. Périmètre : audit seul, corrections en sous-lots séparés (recommandé) ?
2. Personne ou cabinet en charge du volet DPO / juriste, et calendrier.
3. Qualification juridique du cookie du brouillon public (D5) et publication du texte variante B.
4. Texte du message de refus et voies de recours (D7).
5. Acceptation écrite du risque R-OFF-01 (H1) et durée de confiance (12 h au pilote).
6. Durées de conservation définitives (D3, D10, S12).
7. Rôles responsable / sous-traitant (D1) et choix de l'hébergeur (D8).
8. Seuils du dépôt public (S6) et politique de mot de passe / 2FA (S3).
9. Autorisation d'un audit externe (test d'intrusion), ou audit interne seul.
10. Autorisation d'infrastructure pour 11.4 (serveur HTTPS cible), distincte du présent lot.

## 7. 09.7 — prérequis avant autorisation d'infrastructure

Aucun serveur n'est créé. À valider d'abord :

1. Sous-domaines : gestion et `controle.<domaine>`, certificat par sous-domaine, DNS sous contrôle.
2. Hébergeur et localisation (UE) ; choix lié à D8.
3. Linux cible : PHP-FPM, Nginx, sessions (M6), unités systemd des purges (non installées à ce jour).
4. SMTP réel pour les invitations (non testé).
5. AIPD validée et R-OFF-01 accepté par écrit.
6. Consignes de terrain et messages validés.
7. Appareils réels Android et iPhone, comptes et données fictifs, protocole inchangé.
8. Sauvegarde, restauration et retour arrière (runbooks existants).
9. Interrupteurs à 0 par défaut ; activation limitée à la recette.
10. Vérification de l'absence de déploiement automatique avant tout push.
