# Séquestre des trousseaux et clés : conservation hors serveur, perte, récupération

**Obligation (GO / NO-GO, ligne 3.4)** : une copie **sécurisée et restaurable**, **hors serveur**, de chacun des trousseaux ci-dessous doit exister AVANT la mise en service, et sa restauration doit avoir été **répétée** (exercice §4) sans manipuler de véritables secrets de production tant que la recette n'a pas commencé : l'exercice se fait avec des valeurs d'essai.

## 1. Ce qui est à mettre sous séquestre

| Secret | Variables | Protège | Conséquence de la **perte** | Récupération |
|---|---|---|---|---|
| Trousseau des notifications | `NOTIFICATION_KEYRING`, `NOTIFICATION_ACTIVE_KEY_ID` | secrets bénéficiaires chiffrés (liens d'accès en attente d'envoi) | les secrets en attente deviennent illisibles : les liens non encore remis ne peuvent plus être envoyés ; les liens déjà remis fonctionnent (seule l'empreinte sert à les vérifier) | restaurer le trousseau depuis le séquestre ; à défaut, **réémettre les liens** concernés (nouveaux secrets) |
| Trousseau du second facteur CWS (SEC-006) | `TWO_FACTOR_KEYRING`, `TWO_FACTOR_ACTIVE_KEY_ID` | secrets TOTP des super-administrateurs (chiffrés) | les secrets TOTP deviennent illisibles : **aucun super-administrateur ne peut passer le second facteur** (les codes de secours, eux, restent valides tant que `APP_SECRET` est inchangé) | restaurer le trousseau ; à défaut, codes de secours, ou **réinitialisation console** `app:super-admin:reset-two-factor` (accès au serveur, motif, audit) puis nouvelle inscription |
| Trousseau des jetons QR (SEC-020) | `PERMIT_TOKEN_KEYRING`, `PERMIT_TOKEN_ACTIVE_KEY_ID` | copie scellée des jetons de contrôle | les QR ne peuvent plus être **réaffichés ni réimprimés** ; **le contrôle (en ligne et hors connexion) continue de fonctionner** (il repose sur l'empreinte) ; aucun laissez-passer n'est invalidé | restaurer le trousseau ; à défaut, **remplacement du QR** au cas par cas (LOT 05.5.5) |
| Clé(s) privée(s) `age` des sauvegardes (SEC-004) | hors serveur uniquement | déchiffrement des sauvegardes | si l'autre destinataire subsiste : ajouter une nouvelle clé publique ; si **toutes** sont perdues : **les sauvegardes sont définitivement illisibles** | voir [sauvegarde.md](sauvegarde.md) |
| `APP_SECRET` | fichier d'environnement | sessions, CSRF, poivre des codes de secours TOTP, repli historique des clés dédiées | sessions invalidées ; codes de secours TOTP invalides ; copies chiffrées créées **sans** trousseau dédié (développement) illisibles | restaurer la valeur ; en production avec trousseaux dédiés, seuls les codes de secours sont perdus |

## 2. Règles de conservation
- Copie **hors serveur**, dans un gestionnaire de secrets ou un coffre **distinct** de l'accès au serveur et **séparé de la clé privée des sauvegardes** : l'attaquant du serveur n'obtient ni les données, ni les clés qui les protègent, ni la sauvegarde.
- **Au moins deux personnes** habilitées à restaurer (continuité) ; accès journalisé par l'outil de coffre ; aucune copie dans le dépôt, un ticket, un e-mail ou un message.
- À chaque **rotation** : le séquestre reçoit la nouvelle version AVANT d'activer la clé ; l'ancienne clé reste au séquestre tant qu'une donnée peut l'utiliser (`app:permit:protect-tokens --rewrap` pour les jetons ; secrets TOTP : jusqu'à réinitialisation ou réinscription) ; trace datée de chaque version.
- Les trousseaux **ne se sauvegardent pas avec les données** (la sauvegarde des bases est chiffrée par `age`, pas par ces trousseaux).

## 3. Procédure de récupération (perte ou corruption d'un trousseau sur le serveur)
1. Constater : `check-deployment.sh` (variables absentes), `bin/console app:notifications:check-crypto` (notifications), échec de connexion CWS au second facteur, QR non réaffichables.
2. **Ne pas régénérer** un trousseau « à la place » : une nouvelle clé ne rouvre pas les anciennes copies.
3. Récupérer la version datée du séquestre auprès d'une des personnes habilitées ; la poser dans le fichier d'environnement (`/etc/civipass/civipass.env`, mode 600), en conservant l'identifiant de clé actif.
4. Recharger (PHP-FPM) et vérifier : connexion d'un super-administrateur (second facteur), réaffichage d'un QR de test, `check-crypto`.
5. Si le séquestre est lui aussi perdu : appliquer la colonne « récupération à défaut » du tableau §1 (réinitialisations, remplacements de QR, réémission de liens) ; ouvrir un incident ([incident-securite.md](incident-securite.md)) et tracer la décision.

## 4. Exercice périodique (sans secret réel)
Avant la mise en service puis à chaque rotation : avec un trousseau **d'essai** (généré pour l'occasion), (a) le sceller au séquestre, (b) le restaurer sur une machine d'exercice par une seconde personne, (c) vérifier le déchiffrement d'une valeur d'essai (`app:permit:protect-tokens` sur une base de recette, connexion d'un compte CWS de test). Consigner date, personnes, résultat.

## 5. Limites actuelles
Aucun coffre n'est choisi ni installé (prérequis d'exploitation) ; aucune restauration n'a été exercée sur un vrai serveur ; la procédure ci-dessus est vérifiée uniquement par les tests automatisés de rotation et de lecture (`TwoFactorSecretCipherTest`, `PermitTokenSealerTest`, `app:permit:protect-tokens` sur la base locale).
