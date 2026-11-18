# LOT 10 — Stabilisation UX : diagnostic O-2 et séquence d'incréments

Date : 18 novembre 2026. Périmètre : locale, par incréments, sans refonte. Chaque incrément : fichiers modifiés, tests, non-régression, impacts métier et sécurité, **commit local distinct**, aucun push sans autorisation. Inventaire d'origine : [lot-10-inventaire-ux.md](lot-10-inventaire-ux.md).

## 1. Diagnostic approfondi O-2 (cookie du dépôt public)

### 1.1 Faits constatés (base et serveur de recette, 18 novembre 2026)

| Point | Constat | Preuve |
|---|---|---|
| Quand le cookie apparaît | À la première étape du formulaire (`/identite`) : jamais sur l'entrée `/demandes/{réf}`, ni sur `/v/…`, ni sur la page bénéficiaire, ni sur `/controle/` | `Set-Cookie` relevé page par page |
| Attributs | `PHPSESSID`, `path=/`, `HttpOnly`, `SameSite=Lax`, `Secure` automatique en HTTPS, **cookie de session** (sans date d'expiration : supprimé à la fermeture du navigateur, selon son réglage) | `framework.yaml` (`cookie_secure: auto`, `httponly`, `samesite: lax`), en-tête relevé |
| Contenu | Brouillon par événement (clé `public_request.<référence>`) : nom, prénom, adresse, e-mail, téléphone, catégorie, motif, plaques, zones, références de pièces temporaires, plus le jeton CSRF | `PublicRequestDraft` ; fichiers de session inspectés (clés seulement, valeurs masquées) |
| Où | Fichiers de session du serveur (`session.save_handler=files`) ; données **en clair** dans le fichier (droits 0600) | 11 brouillons avec e-mail et téléphone en clair dans l'échantillon |
| Durée réelle | La durée de vie annoncée est 1 440 s (24 min), mais le nettoyage est **probabiliste** : `gc_probability=1` pour `gc_divisor=1000` sur l'image PHP du projet. Sur 123 fichiers de session de la recette, **40 portent un brouillon public et les 40 ont plus de 24 min** (le plus ancien : plus de 2 h 40 min) | Comptage dans le conteneur de recette |
| Production | Non mesurable ici : le pool PHP-FPM du kit (`ops/nginx/php-fpm/civipass-pool.conf.example`) ne fixe rien sur les sessions ; sur Debian/Ubuntu, le nettoyage dépend de la minuterie du paquet PHP (`phpsessionclean`, durée `gc_maxlifetime`). **À vérifier sur le serveur cible** | Lecture de la configuration du kit |
| Pièces jointes | Les justificatifs sont posés dans une zone temporaire ; `app:request-uploads:purge` les supprime après 24 h, mais **aucune unité systemd ni aucun runbook ne la planifie** (seuls le code et un test la mentionnent) | Recherche dans `ops/`, `docs/`, `src/` |
| Terminal partagé | Si un citoyen abandonne à l'étape 2 et qu'une autre personne ouvre le formulaire **dans le même navigateur**, les champs sont **préremplis avec la saisie du premier** (nom, prénom, e-mail) | Reproduit : « Abandon / Brouillon / abandon@… » réaffichés à l'étape 1 |
| Citoyen / agent | Un seul cookie `PHPSESSID` pour les deux usages : un agent connecté qui ouvre le formulaire public crée son brouillon **dans sa propre session authentifiée** ; la navigation publique ne la perturbe pas (agent toujours authentifié) ; la déconnexion détruit la session et donc le brouillon | Reproduit avec `a-admin` |
| Information | Aucun écran ni document ne décrit ce cookie (DT-051 et `docs/security.md` posent « aucun cookie » pour la page bénéficiaire, DT-056 pour le contrôle ; rien pour le dépôt) | Recherche dans `docs/` |

Une conséquence sans rapport avec le cookie lui-même : l'exécution de `app:request-uploads:purge` sur la recette a supprimé 15 fichiers temporaires expirés. L'origine de ces fichiers (dossier de stockage partagé avec le développement et les tests) n'est pas établie ; la suppression n'a touché que des données fictives.

### 1.2 Analyse

- **Nature** : cookie technique strictement nécessaire (état de saisie, protection CSRF), a priori dispensé de consentement ; l'**information** reste recommandée. Qualification à confirmer par le juridique / DPO.
- **Risque principal** : la **donnée personnelle d'un brouillon abandonné** reste lisible (a) dans le fichier de session plus longtemps que prévu et (b) à l'écran suivant sur un navigateur partagé (mairie, bibliothèque). Ce n'est pas une faille d'isolation entre communes ni de permissions.
- **Séparation citoyen / agent** : elle **n'apporte presque rien** pour la vie privée (le brouillon est déjà cloisonné par événement et par portée ; le vrai risque est le navigateur partagé, que deux cookies ne règlent pas) et **coûterait une modification de l'architecture de session** (nom de cookie ou stockage distinct pour les routes publiques). **Recommandation : ne pas séparer en V1**, documenter, et traiter le navigateur partagé par les correctifs ci-dessous.
- **Brouillon en base** : écarté (règle d'architecture existante : « jamais en base, aucune demande incomplète n'est enregistrée »).

### 1.3 Correction minimale proposée (sans changer l'architecture de session)

| Réf. | Correction | Nature | Validation requise |
|---|---|---|---|
| M1 | Phrase d'information sur le cookie (texte § 2) | Texte utilisateur | **Oui** (texte + juridique) |
| M2 | Documenter le cookie : `docs/security.md`, une décision technique (cookie de session anonyme du dépôt, contenu, durée, finalité) et la politique de confidentialité | Documentation | Texte de la politique : oui |
| M3 | **Durée de vie du brouillon : 30 minutes d'inactivité**. Le brouillon porte l'heure de dernière modification ; au-delà, il est considéré vide et remplacé (la saisie n'est plus affichée, y compris sur un navigateur partagé) | Comportement (applicatif, pas d'architecture) | **Oui** (nouvelle règle : durée à décider) |
| M4 | Bouton **« Effacer ma saisie »** sur les étapes du formulaire : vide le brouillon et les pièces temporaires de la session, puis revient à l'étape 1 | Comportement + texte | **Oui** |
| M5 | Planifier `app:request-uploads:purge` (unité et minuterie systemd dans `ops/systemd/`, toutes les heures) et la citer dans le runbook d'exploitation | Exploitation (fichiers du dépôt, aucun déploiement) | Non (accord de principe de ce message sur « préparer »), mise en service ultérieure |
| M6 | Vérifier le nettoyage des sessions sur le serveur de recette (09.7) : valeur de `gc_maxlifetime` et minuterie ; à défaut, fixer `session.gc_probability`/`gc_divisor` ou un dossier de sessions dédié nettoyé | Exploitation | Selon résultat |

**Rien n'est implémenté pour M1, M3 et M4 avant votre validation.** M2 (documentation, sauf texte de politique) et M5 peuvent démarrer.

## 2. Texte d'information sur le cookie (proposition exacte)

Emplacement : bloc « Vos données » du formulaire public (colonne d'information, étapes 1 à 6) et rappel sous la case de confidentialité du récapitulatif.

**Variante A (M1 seule, sans bouton)**

> **Cookie technique.** Pour conserver votre saisie d'une étape à l'autre, ce site utilise un cookie temporaire. Il ne sert ni au suivi ni à la publicité et il est en principe supprimé à la fermeture de votre navigateur. Sur un ordinateur partagé, fermez le navigateur à la fin de votre demande.

**Variante B (avec M3 et M4)**

> **Cookie technique.** Pour conserver votre saisie d'une étape à l'autre, ce site utilise un cookie temporaire. Il ne sert ni au suivi ni à la publicité. Votre saisie est effacée après 30 minutes d'inactivité, et à tout moment avec « Effacer ma saisie ».

Recommandation : **B**, car elle règle aussi le navigateur partagé ; **A** si M3 et M4 sont différés. Les nombres (30 minutes) suivent la proposition M3.

## 3. Séquence de stabilisation UX priorisée (état au 18 novembre 2026)

| # | Incrément | État | Commit local |
|---|---|---|---|
| 0 | O-3 : textes d'invitation validés | **Fait, poussé** | `a65c8da` |
| 1 | O-2 / M5 : purge des justificatifs temporaires planifiée et supervisée | **Fait** | `11212b5` |
| 1b | O-2 / M3 + M4 : brouillon 30 min, « Effacer ma saisie », purge serveur | **Fait** | `c38fb62` |
| 2 | O-2 / M1 : texte « Cookie technique » (variante B validée) | **Fait** | `3bc7b4c` |
| 1c | O-2 / M2 + M6 : documentation, DT-058 proposée, runbook, constat sessions | **Fait** | `90065fd` |
| 5 | O-7 : lien « Consulter les archives » (rôles habilités) | **Fait** | `1d025e3` |
| 4 | O-4 : titre de niveau 1 sur tous les états de `/v` | **Fait** | `8be6b7a` |
| 6 | O-1 : tableaux communaux dans un conteneur défilant | **Fait** | `e6146dd` |
| 7 | O-6 : statuts et pages cohérents pour les liens invalides | À faire (revue sécurité des réponses) | |
| 8 | O-5 : navigation mobile (4 entrées + « Menu ») | À faire | |

Ordre : 1 → 2/3 (après validation) → 4 → 5 → 6 → 7 → 8. Total estimé : environ 4 à 5 jours avec validations.

Impacts sécurité et métier par incrément : 1, 4, 5, 6, 8 sans impact ; 2 et 3 touchent des textes et un comportement de session applicatif (aucune donnée en base, aucune migration) ; 7 touche des réponses publiques d'erreur (à relire côté sécurité : uniformité, absence d'énumération).

## 4. Points à valider

1. Variante A ou B du texte d'information ?
2. Durée du brouillon (30 min) et bouton « Effacer ma saisie » ?
3. Séparation des sessions citoyen / agent : écartée en V1, d'accord ?
4. M2 et M5 autorisés à démarrer (documentation et fichiers d'exploitation du dépôt) ?
5. O-7 : la fermeture de la consultation courante des dossiers d'un événement clos est-elle voulue ?
