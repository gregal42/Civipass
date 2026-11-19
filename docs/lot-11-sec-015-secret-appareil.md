# SEC-015 — Secret d'appareil de contrôle : analyse pour arbitrage (19 novembre 2026)

**Statut : analyse seule. Aucune valeur ni aucun comportement n'a été modifié** (la durée de 72 h reste en vigueur, décision du commanditaire du 19 novembre 2026). Sources : `src/Control/Domain/ControlDevice.php`, `ControlSettings.php`, `DeviceApprovalService.php`, `docs/lot-09-controle-hors-connexion-cadrage.md` (§5, A3), DM-130 à DM-132, constat 11.2-F6.

## 1. Quatre notions à ne pas confondre

| Notion | Ce que c'est aujourd'hui | Valeur et réglage | Qui la fait respecter |
|---|---|---|---|
| **Validité du secret d'appareil** | `control_device.expires_at` : fin de vie de l'appareil approuvé ; au-delà, le serveur refuse l'appareil (synchronisation et état) et l'application purge à l'ouverture | `CONTROL_DEVICE_LIFETIME_HOURS` = **72 h** par défaut (1 à 336 h), **plafonnée à la fin planifiée de l'événement** ; renouvelable **à la main** par un administrateur communal (`renouveler`) | Serveur |
| **Durée de fonctionnement hors connexion** | Ancienneté maximale du jeu de données local : avertissement **6 h**, verdict « indéterminé » au-delà de **12 h** ; plafond de configuration 12 h, plafond technique 24 h | `CONTROL_TRUST_WARN_SECONDS`, `CONTROL_TRUST_HARD_MAX_SECONDS` ; raccourcissable par commune (2 h au plus bas) | Application (poste du contrôleur) |
| **Synchronisation** | Appel authentifié par le secret : automatique toutes les 15 min et à la demande quand le réseau est là ; limité à 60 / 10 min par appareil | non configurable | Serveur |
| **Révocation** | Désactivation par un administrateur communal (téléphone perdu ou volé) ; **effective à la prochaine connexion de l'appareil**, jamais avant (risque R-OFF-01) | — | Serveur ; l'application purge à l'ouverture |

Ces quatre notions sont **indépendantes** : un appareil dont le secret est valide 72 h devient « indéterminé » après 12 h sans synchronisation ; inversement un secret expiré ou révoqué n'est détecté **que** lors d'une connexion.

## 2. Ce que couvre le risque F6

Le secret est un **jeton porteur** : seule son empreinte est stockée côté serveur, il n'est lié à aucune machine. Si le secret est extrait d'un téléphone volé et déverrouillé, il peut être rejoué **depuis n'importe quelle machine** pour demander le jeu de données (noms, prénoms, plaques, zones de la commune activatrice) **jusqu'à la désactivation ou l'expiration**. La fenêtre d'exposition à distance est donc la **validité du secret (72 h)**, et non les 12 h de confiance des données locales. Les données déjà présentes sur l'appareil restent lisibles sur ce téléphone indépendamment de ces durées (suppression « à l'ouverture » seulement : DT-C3, DT-C6).

À l'inverse, raccourcir la validité alourdit l'exploitation : tant que le renouvellement est **manuel** (un administrateur communal doit approuver à nouveau), un événement de plusieurs jours impose un renouvellement par appareil toutes les 72 h (24 h : chaque jour).

## 3. Options

| Option | Effet sur le risque | Effet sur le terrain | Changement |
|---|---|---|---|
| **A. Conserver 72 h** et compenser | Inchangé, détection et réaction plus rapides : journaliser l'adresse et l'agent utilisateur à chaque synchronisation, alerter l'administrateur si l'un change brutalement ; consigne : désactiver **immédiatement** un appareil perdu | Aucun | Configuration et consigne ; détection = petit code |
| **B. Validité glissante plafonnée** (ex. 24 h renouvelées à chaque synchronisation réussie, plafond = fin de l'événement ou 14 jours) | Un secret volé et **inutilisé** meurt en 24 h ; un appareil qui synchronise vit tant qu'il sert | Plus aucun renouvellement manuel ; un appareil resté éteint plus de 24 h doit être ré-approuvé | Changement de comportement (`markRenewed` automatique) ; décision métier |
| **C. Lier le secret à l'appareil** (paire de clés générée dans le navigateur, clé publique enregistrée à l'activation, requêtes signées) | Un secret extrait seul ne suffit plus ; le vol de la clé exige l'appareil | Transparent pour le contrôleur ; support limité de certains navigateurs ; Service Worker et `crypto.js` concernés | Changement important (schéma, protocole, tests e2e) ; à cadrer comme un lot |
| **D. Réduire sans changer le mécanisme** (24 h + renouvellement manuel) | Fenêtre de 24 h | Renouvellement quotidien par un administrateur : lourd en crise | Configuration seule |

**Recommandation** : A tout de suite (aucune modification de valeur, détection et consigne), puis **arbitrer entre B et C** pour la version suivante. B réduit la fenêtre sans alourdir le terrain ; C est plus robuste mais plus coûteux. D n'est pas recommandée (charge d'exploitation en situation de crise).

## 4. Questions pour l'arbitrage

1. Accepte-t-on, pour le pilote, la fenêtre de 72 h avec les compensations de l'option A ?
2. La validité glissante (B) est-elle acceptable sur le plan métier (un appareil éteint plus de 24 h doit être ré-approuvé) ? Quel plafond absolu (fin d'événement, 14 jours) ?
3. Faut-il engager un lot spécifique pour la liaison à l'appareil (C), avant ou après le pilote ?
4. Qui a l'autorité et la consigne pour désactiver un appareil perdu **sans attendre** (runbook `controle-hors-connexion.md`) ?

## 5. Constat annexe (aucun correctif engagé)

`check-deployment.sh` ne contrôle pas `CONTROL_DEVICE_LIFETIME_HOURS` : une valeur de 336 h (14 jours, maximum accepté) serait acceptée sans avertissement. Un simple contrôle informatif « valeur supérieure à 72 h » pourrait être ajouté si l'option A est retenue.
