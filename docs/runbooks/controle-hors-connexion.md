# Runbook — contrôle hors connexion (LOT 09)

**Statut** : prêt pour la recette locale ; **aucune donnée réelle** avant validation de l'AIPD ([aipd-controle-hors-connexion-technique.md](../aipd-controle-hors-connexion-technique.md)) et acceptation écrite du risque R-OFF-01. `OFFLINE_CONTROL_ENABLED` est à `0` par défaut ; l'activer est une décision explicite, jamais un effet de bord.

## 1. Préparer un événement (administrateur communal)
1. Événement actif, participation de la commune active (sinon le contrôle ne peut pas être préparé).
2. Page de l'événement › **Contrôle hors connexion** › **Créer un code d'activation** : mode (`Standard` / `Strict`), nombre de téléphones (1 à 10), durée de confiance maximale (pilote : **12 h** ; une commune peut la **raccourcir**, jamais la rallonger), avertissement (pilote : 6 h), validité du code (30 min).
3. **Le code ne s'affiche qu'une fois.** Ne pas le photographier, ne pas le transférer. Au plus 3 codes utilisables à la fois.
4. Le contrôleur ouvre l'application, scanne le QR d'activation (ou saisit le code), nomme l'appareil.
5. Le téléphone affiche un **code d'appariement à 6 chiffres**. L'administrateur le fait lire **devant lui**, l'entre dans « Approuver… ». **Sans approbation, aucune donnée n'est envoyée.**
6. Après approbation, l'appareil se synchronise et fonctionne hors connexion pendant sa durée de confiance.

## 2. Pendant l'événement
- Avec réseau, l'application ouvre le **contrôle en ligne** (temps réel) : c'est le mode préféré.
- Hors réseau : verdict **orange « valide selon la dernière synchronisation »**, daté, jamais présenté comme une vérification en temps réel. Au-delà de l'avertissement (6 h) : « données anciennes » ; au-delà de la durée de confiance (12 h) : **indéterminé** (ce n'est pas un refus : procédure de terrain).
- Synchroniser dès que le réseau revient (automatique toutes les 15 min application ouverte ; bouton **Synchroniser**).
- Mode **Strict** : aucun verdict positif hors ligne (« confirmation en ligne requise »).

## 3. Révocation urgente
1. L'agent révoque dans Civipass (effet **immédiat en ligne**).
2. L'administrateur alerte les postes **par radio / téléphone** (hors outil) ; les appareils connectés se resynchronisent ; les autres le feront à la reconnexion.
3. **Délai maximal d'invisibilité** d'une révocation sur un appareil hors réseau : `min(durée de confiance, prochaine synchronisation)`, soit **12 h au plus au pilote**. Au besoin : désactiver les appareils concernés, ou raccourcir la durée de confiance pour les nouveaux codes.
4. QR compromis : remplacement du QR (DM-113 §4) ; l'ancien reste « valide selon synchronisation » hors ligne jusqu'à la synchronisation suivante.

## 4. Perte, vol ou compromission d'un téléphone
| Étape | Action |
|---|---|
| 1. Signalement | le contrôleur prévient son poste, qui prévient l'administrateur communal |
| 2. Désactivation | page « Contrôle hors connexion » › appareil › **Désactiver…** › motif *Perdu ou volé* / *Compromis* — **effective à la prochaine connexion de l'appareil** ; **inefficace tant que le téléphone reste hors réseau** |
| 3. Contention | annuler les codes encore utilisables ; si un code a pu être divulgué, vérifier la liste des appareils (libellés, heures) et désactiver tout appareil inattendu |
| 4. Exposition à documenter | données présentes : nom, prénom, plaques, zones et fenêtres de validité de **la seule commune activatrice**, pour les laissez-passer **valides ou à venir**, à l'instant de la dernière synchronisation (≤ durée de confiance) ; **aucun** jeton en clair, aucun contact, aucune pièce. Les données identifiantes sont supprimées à la prochaine ouverture de l'application après la durée de confiance ; **une application jamais rouverte les conserve** (limite reconnue) |
| 5. Violation de données | l'appréciation (notification de l'autorité, information des personnes) relève du **responsable de traitement** avec son DPO ; fournir la liste ci-dessus et la période |
| 6. Suite | une nouvelle activation avec un nouveau code remplace l'ancien profil du téléphone remis ; l'ancien enregistrement est désactivé |

## 4 bis. Renouvellement et fin
- **Renouveler** : page « Contrôle hors connexion » › appareil › *Renouveler* (nouvelle échéance, limitée par la fin de l'événement ; le secret ne change pas).
- Un appareil expiré, désactivé, ou dont l'événement est clos : **les données locales sont supprimées à l'ouverture suivante** ; à la reconnexion le serveur l'informe (désactivé / expiré / événement terminé).
- À la **purge d'un événement** (DM-120), appareils, codes et leur audit sont supprimés avec lui.

## 5. Limites à connaître (honnêtes)
- Un laissez-passer **révoqué ou suspendu après la dernière synchronisation** reste « valide selon la dernière synchronisation » sur l'appareil jusqu'à sa prochaine synchronisation.
- **Aucun chiffrement applicatif** n'est annoncé : la protection repose sur la minimisation, la durée, la suppression à l'ouverture, l'isolation par commune et le **verrouillage du téléphone** (obligatoire).
- Une horloge **avancée** application fermée n'est pas détectable ; elle est bornée par l'horizon du jeu (≤ 12 h). Reculée, ou décalée à la synchronisation : l'appareil répond « indéterminé ».
- La suppression à distance n'agit qu'à la reconnexion. Sur iPhone : application **installée** requise (sinon stockage purgé après ~7 jours sans usage), pas de synchronisation d'arrière-plan.
- Aucun journal de scan (DM-127) : pas de statistiques de contrôle, pas d'attribution d'un scan à une personne.

## 6. Exploitation
- Interrupteur `OFFLINE_CONTROL_ENABLED` (404 uniforme à 0). Plafond de configuration de la durée de confiance : `CONTROL_TRUST_HARD_MAX_SECONDS` (**43 200 au pilote ; le relever exige une décision explicite**, jamais au-delà de 86 400).
- Limiteurs : 10 codes/heure/commune, 60 enregistrements/10 min/IP, 10 échecs/15 min/IP, 60 synchronisations/10 min/appareil. Un 503 signale des compteurs indisponibles (jamais une limitation perdue en silence).
- Journaux : aucun code, secret, code d'appariement, jeton ou nom. Audit : `CONTROL_ACTIVATION_*`, `CONTROL_DEVICE_*`, `CONTROL_SYNC_REFUSED` (au plus un par heure et par appareil).
- Service Worker : version liée au contenu ; en cas de défaut, mettre l'interrupteur à `0` (les routes répondent 404 ; les appareils n'obtiennent plus de données) puis publier la correction.
- Tests : `bin/qa js` (node:test), `bin/qa run Control`, `bin/qa e2e` (Chrome local, base de test, données fictives).
