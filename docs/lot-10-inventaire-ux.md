# LOT 10 — Inventaire UX priorisé (observations O-1 à O-7)

Date : 18 novembre 2026. Origine : recette locale du LOT 10 (captures `var/lot10/preuves/`, non versionnées, et mesures à 390 px avec les feuilles de style chargées). **Aucune correction n'est lancée** ; aucune refonte générale n'est proposée. Gravité : Mineure (gêne) · Moyenne (compréhension, accessibilité ou information légale) · Majeure (perte de service). Effort : XS < ½ j · S ≈ 1 j · M 2-3 j.

> **Correction d'une mesure du bilan intermédiaire** : l'observation « liste d'événements qui déborde » (585 px) venait d'une capture sans CSS (serveur de test sans assets) ; remesuré à 390 px avec CSS, **seule la page « Droits des personnes » déborde**. O-1 est donc limitée à cette page.

| # | Observation | Écran | Impact utilisateur | Gravité | Proposition de correction | Effort | Bloquant pilote ? |
|---|---|---|---|---|---|---|---|
| O-1 | Le tableau « Demandes de droits » force une largeur de 701 px sur un écran de 390 px : défilement horizontal | Droits des personnes (`/communes/{id}/droits`) | Un administrateur sur téléphone doit faire défiler à l'horizontale pour lire échéance et état ; page rarement ouverte | Mineure | Envelopper le tableau dans le conteneur responsive existant (défilement interne) ou le présenter en cartes sous 600 px | XS | Non |
| O-2 | Le dépôt public pose un cookie de session anonyme `PHPSESSID` dès la première étape (voir analyse ci-dessous), sans mention à l'écran ; le plan disait « aucune session créée » | Dépôt public (`/demandes/{référence}/…`) | Le citoyen n'est pas informé d'un cookie technique ; son brouillon (nom, e-mail, téléphone) vit côté serveur jusqu'à la fin ou au nettoyage de session | Moyenne (information / conformité) | (a) Phrase d'information dans le bloc « Vos données » (à valider) ; (b) documenter le cookie dans `docs/security.md` et dans la politique de confidentialité ; (c) cookie dédié au dépôt (nom et chemin `/demandes`, durée bornée) ; (d) vérifier la purge des brouillons et des pièces temporaires abandonnés | (a)(b) XS ; (c)(d) S-M | **Oui, conditionnel** : l'information (a)(b) est à valider par le juridique avant d'ouvrir à de vrais citoyens ; (c)(d) non bloquants |
| O-3 | L'écran d'invitation dit « n'expire pas », l'e-mail dit « valable jusqu'au… » | Page « Inviter une commune », fiche de l'événement (commune invitée), e-mail | Doute sur la validité de l'invitation ; crainte d'une invitation perdue | Mineure | Textes T1, T2, T3 de [lot-10-textes-a-valider.md](lot-10-textes-a-valider.md) | XS | Non |
| O-4 | Les pages de vérification publique n'ont pas de titre de niveau 1 pour les états « inconnu », « clôturé » et « remplacé » (présent pour « valide ») | `/v/{jeton}` | Lecteurs d'écran : pas de repère de page ; incohérence entre états | Mineure (accessibilité) | Ajouter le même titre « Vérification » aux états sans titre, sans changer le contenu ni les statuts HTTP | XS | Non (à regrouper avant l'ouverture au public) |
| O-5 | La barre de navigation mobile occupe 3 lignes (229 px sur 844) et masque une part du contenu ; un « · » reste après le nom de la commune dans l'en-tête | Toutes les pages communales en mobile | Moins de place pour le contenu, navigation lourde | Moyenne (confort mobile) | Garder 4 entrées principales (Accueil, Événements, Demandes, Menu) ; ranger les autres dans « Menu » ; supprimer le séparateur orphelin | S | Non (usage principal des agents : poste de bureau) |
| O-6 | Réponses hétérogènes pour les liens à usage unique : lien de réinitialisation déjà utilisé = page « Lien invalide » en 200 ; invitation déjà utilisée = 410 ; lien inconnu = 404 | `/reset-password/…`, `/invitation/…` | Aucun risque de fuite constaté (jetons de 256 bits) ; messages et codes incohérents pour qui dépanne | Mineure | Aligner les statuts et les pages (404 inconnu, 410 expiré ou utilisé) en gardant des textes neutres | S | Non |
| O-7 | Après clôture d'un événement, le message « les données de ses dossiers ne sont plus consultables dans l'espace de gestion » n'indique pas où retrouver les dossiers | Fiche demande / laissez-passer d'un événement clos | L'agent se croit privé de ses dossiers ; ils sont aux Archives | Mineure | Ajouter un lien « Aller aux Archives » dans le message (comportement inchangé) ; confirmer par le métier que la fermeture de la consultation courante est voulue (DM-091, DM-120) | XS | Non |

## Analyse du cookie anonyme du dépôt public (O-2)

**Constat (mesuré sur la recette)**
- Le cookie `PHPSESSID` est posé à la première étape du formulaire (`/identite`), pas sur la page d'entrée ni sur `/v` ni sur la page bénéficiaire (aucun cookie constaté).
- Attributs : `HttpOnly`, `SameSite=Lax`, `Secure` automatique en HTTPS, **sans durée** (disparaît à la fermeture du navigateur), nom générique, `path=/`.
- Contenu côté serveur (session par fichiers, durée de vie de session PHP 1 440 s) : brouillon de la demande par événement (`public_request.<périmètre>`), c'est-à-dire identité, contacts, zones, plaques, références de pièces temporaires, plus le jeton CSRF. Après envoi, le brouillon est remis à zéro ; un brouillon abandonné reste lisible côté serveur jusqu'au nettoyage de session ; les pièces temporaires sont supprimées par `app:request-uploads:purge` au bout de 24 h.
- Le même nom de cookie sert pour la connexion des agents : un agent connecté qui teste le dépôt public utilise sa propre session.
- Aucun écran ni document ne décrit ce cookie : DT-051 et `docs/security.md` posent « aucun cookie » pour la page bénéficiaire et DT-056 pour l'application de contrôle, mais rien pour le dépôt public.

**Qualification** : cookie technique strictement nécessaire au fonctionnement du formulaire (état de saisie et protection CSRF), a priori dispensé de consentement ; l'**information** reste recommandée et la qualification relève du juridique / DPO (à confirmer, je ne la tranche pas).

**Risques résiduels** : (1) données personnelles d'un brouillon abandonné conservées jusqu'au nettoyage ; (2) cookie générique partagé avec la connexion des agents ; (3) absence d'information.

**Pas de refonte** : le comportement fonctionnel est correct (aucun compte, aucune session authentifiée). Les actions O-2 (a) à (d) sont ciblées.

## Priorisation proposée

1. **Avant ouverture à de vrais citoyens** : O-2 (a)(b) (information et documentation, après validation juridique).
2. **Avant ouverture au public de la vérification** : O-4 (titres).
3. **Lot de stabilisation UX court (≈ 3-4 jours au total)** : O-1, O-3, O-5, O-6, O-7, O-2 (c)(d).
