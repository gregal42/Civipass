# Correctif B — SEC-016 et SEC-020 : analyse et options pour arbitrage (19 novembre 2026)

**Statut : analyse seule, aucun code ni aucune migration.** Les deux constats touchent un parcours métier validé (invitation intercommunale, QR stable régénérable) : la consigne du 19 novembre 2026 est de revenir vers le commanditaire avant de poursuivre.

---

## 1. SEC-016 — L'invitation « contact » ne doit jamais conférer automatiquement un rôle d'administration

### 1.1 Ce que fait le parcours aujourd'hui ([invitations-intercommunales.md](invitations-intercommunales.md) §3)

1. La commune organisatrice saisit une **adresse de contact** libre (aucun domaine imposé) ; le contact reçoit un lien valable **14 jours**.
2. Si la commune invitée a déjà un administrateur, il se connecte et l'acceptation existante s'applique.
3. **Sinon**, le contact indique **l'adresse de la personne qui sera administrateur** ; elle reçoit un lien personnel (3 jours, usage unique), crée son compte, **atteste** représenter la commune et **devient immédiatement administrateur communal** (`MembershipManager::enrollFirstAdministrator`).

### 1.2 Le risque

Quiconque contrôle la boîte du contact (adresse tapée par une autre commune, éventuellement erronée ou malveillante) peut désigner **n'importe quelle adresse** qui devient administrateur de la commune, **sans aucune vérification d'habilitation** (l'audit conserve `officialHabilitationVerified = false`). Le contrôle porte sur la **possession d'une boîte**, pas sur la qualité d'agent de la commune. La condition « aucun administrateur effectif » vaut aussi pour une commune **ouverte par CWS qui attend encore son premier administrateur** : le lien de contact peut passer **avant** l'invitation de CWS (course).

### 1.3 Options

| Option | Principe | Parcours intercommunal | Coût |
|---|---|---|---|
| **A. Validation CWS avant tout droit** (recommandée) | À l'inscription par lien personnel, le compte est créé et **l'attestation enregistrée**, mais le rattachement reste **en attente de validation** (aucun droit). Un super-administrateur CWS valide (Sonata ou console, audit), après vérification **hors Civipass** (appel à la mairie, site officiel). La participation à l'événement reste en attente (l'invitation n'expire pas, DM-086) | **Préservé**, avec une étape de validation | 1 migration (indicateur ou table de demandes de validation), 1 action CWS, 1 notification à CWS, textes ; environ 3 à 4 jours |
| **B. Pas d'inscription par lien de contact** | Le lien de contact n'ouvre que l'**acceptation** pour une commune qui a déjà un administrateur ; une commune sans administrateur doit d'abord être **ouverte par CWS** (flux 07.1 existant) | **Modifié** : la participation gratuite d'une commune jamais ouverte passe par CWS | Suppression d'un chemin de code, textes ; environ 1 à 2 jours, aucune migration |
| **C. Contrôle de domaine** | N'accepter que des adresses « institutionnelles » (liste de domaines) | Préservé, mais **non fiable** (domaines de messagerie hétérogènes, faux négatifs en crise) | Non recommandée |

**Recommandation : A.** Elle satisfait la consigne (aucun rôle automatique) et **préserve** le parcours. Le prix est un **délai de validation** : en situation de crise, une commune invitée ne participe qu'après validation CWS. À accompagner d'un engagement de délai et d'un canal d'urgence (validation par console).

### 1.4 Questions pour l'arbitrage

1. A ou B ?
2. Qui valide (tout super-administrateur CWS) et sous quel délai visé en crise (par exemple 2 h ouvrées) ?
3. Comment la légitimité est-elle vérifiée (rappel au standard de la mairie, courrier officiel, justificatif) et faut-il en garder une trace dans l'outil ?
4. Que voit la personne en attente (message neutre « votre demande est en cours de vérification ») ?
5. Les communes **ouvertes par CWS** (invitation 07.1 en cours) sont-elles exclues de ce chemin (recommandé : oui, le contact ne peut jamais se substituer à l'invitation CWS) ?

---

## 2. SEC-020 — Jeton de contrôle stocké sous forme de hachage

### 2.1 Pourquoi le jeton est stocké en clair aujourd'hui

`permit_verification_token.token` (160 bits aléatoires) est **relu** pour : afficher le QR sur la page du bénéficiaire, **générer le PDF à la demande** (jamais stocké, DM-118), renvoyer le lien, **bâtir le jeu de données hors connexion** (`DatasetBuilder`) et retrouver un laissez-passer à partir du jeton scanné. DM-113 exige un **QR stable** que le bénéficiaire peut réafficher et réimprimer.

### 1.2 L'incompatibilité

Un **hachage seul** ne peut pas être relu : le QR ne pourrait plus être **réaffiché ni réimprimé** après l'émission. Cela supprimerait une fonction validée (DM-113 « QR stable », DM-118 « PDF à la demande ») : seule la **régénération par remplacement du QR** resterait possible.

### 2.3 Options

| Option | Principe | Effet métier | Migrations |
|---|---|---|---|
| **A. Empreinte pour la recherche + copie chiffrée pour le réaffichage** (recommandée) | Deux colonnes : `token_sha256` (recherche unique, jeu de données hors connexion : l'application calcule le SHA-256 du QR scanné) et `token_encrypted` (XChaCha20-Poly1305, clé du trousseau, lié au laissez-passer). Le clair disparaît de la base ; une fuite de la base **seule** ne livre aucun jeton ; réaffichage et PDF inchangés | **Aucun changement** : QR identiques, **aucun QR existant n'est invalidé** | 2 : ajout des colonnes et empreinte calculée par MySQL (`SHA2`) ; puis, après la commande de chiffrement de l'existant (`app:permit:protect-tokens`), **suppression de la colonne en clair** (suppression à valider séparément) |
| **B. Hachage seul** | Seule l'empreinte est conservée ; le QR n'est montré qu'**une fois** à l'émission | **Change** DM-113 et DM-118 (plus de réimpression ; perte de QR = remplacement du QR) | 2, plus décision métier |
| **C. Statu quo** + mesures | Jeton en clair, mais sauvegardes chiffrées (SEC-004), accès à la base restreint | Aucun | Aucune |

**Éléments de jugement.** Un jeton ne donne accès qu'à la page de contrôle (nom, prénom, plaques, zones : données que l'attaquant d'une base possède déjà) ; le risque résiduel d'une fuite est la **fabrication de faux QR** de laissez-passer réels, atténuée par le rapprochement d'identité (DM-125). Le risque est donc réel mais **modéré**.

**Recommandation : A.** Elle remplit l'objectif (plus de jeton en clair en base) sans toucher au parcours ni invalider un seul QR. Elle exige que le trousseau de chiffrement existe (déjà obligatoire pour les notifications, contrôlé par `check-deployment.sh`) et rend son **séquestre** encore plus important (perte du trousseau = QR non réaffichables, mais le contrôle continue de fonctionner par l'empreinte).

### 2.4 Comportement des jetons existants avec A

Aucun jeton ne change : la migration calcule l'empreinte du clair existant ; la commande chiffre ensuite chaque jeton ; **les QR déjà imprimés ou envoyés continuent de fonctionner**. Retour arrière : tant que la colonne en clair n'est pas supprimée, la migration est réversible sans perte.

### 2.5 Questions pour l'arbitrage

1. A, B ou C ?
2. Avec A : acceptez-vous une **seconde migration** de suppression de la colonne en clair, à valider après la phase de chiffrement ?
3. Le trousseau de chiffrement doit-il être celui des notifications (`NOTIFICATION_KEYRING`) ou un trousseau dédié ?
