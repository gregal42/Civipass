# DT-058 — Cookie de session anonyme et brouillon du dépôt public

- **Identifiant** : DT-058
- **Thème** : LOT 10 — Stabilisation UX (O-2) : minimisation et vie privée du dépôt public
- **Statut** : **Validée** pour la règle de 30 minutes et les purges techniques (18 novembre 2026) ; qualification juridique du cookie : LOT 11
- **Validation** : principe M1 à M6 et texte d'information validés par le commanditaire le 18 novembre 2026 ; la présente fiche consigne l'implémentation et attend sa validation formelle.
- **Décisions remplacées** : Aucune.

## Contexte
Recette LOT 10 : le dépôt public pose un cookie de session anonyme qui porte un brouillon (identité, e-mail, téléphone, plaques) dans un fichier de session. Mesures : nettoyage PHP probabiliste (1 / 1 000 pour 24 min) ; 40 sessions sur 123 portaient un brouillon, toutes de plus de 24 min, la plus ancienne de plus de 2 h 40 ; saisie du premier citoyen réaffichée à la personne suivante sur un navigateur partagé ; aucune information du citoyen. Règle existante conservée : le brouillon n'est jamais enregistré en base.

## Décision
1. **Cookie de session anonyme conservé** pour le dépôt public (aucun autre cookie) ; l'architecture de session est inchangée ; la séparation citoyen / agent est **reportée**.
2. **Expiration du brouillon : 30 minutes d'inactivité**, chaque requête du parcours la prolonge ; l'expiration **supprime** la clé de session du brouillon et les justificatifs temporaires associés (aucun masquage seul).
3. **Effacement volontaire** : « Effacer ma saisie » (POST, CSRF), même suppression.
4. **Purge serveur** des brouillons de sessions anonymes inactives (`app:request-drafts:purge`, toutes les 10 minutes), qui ne touche jamais une session authentifiée ; purge des justificatifs temporaires abandonnés à 24 h (`app:request-uploads:purge`, horaire). Les deux sont planifiées par des unités systemd supervisées, livrées et jamais installées par le dépôt.
5. **Information** : bloc « Cookie technique » sur chaque étape du formulaire public.

## Trois niveaux de suppression (à ne pas confondre)

| Niveau | Déclencheur | Effet | Moment |
|---|---|---|---|
| **Expiration logique** | 30 minutes sans aucune requête du parcours (`PublicRequestDraft::TTL_SECONDS`) | Le brouillon n'existe plus pour l'application : il n'est jamais réaffiché (formulaire vide, message au citoyen). Au même accès, ses clés sont retirées de la session et ses justificatifs temporaires supprimés du stockage | À la première requête suivante de ce navigateur |
| **Effacement volontaire** | Bouton « Effacer ma saisie » (POST, CSRF) | Même suppression, immédiate, puis retour à la première étape | Sur action du citoyen |
| **Suppression physique par la purge périodique** | `app:request-drafts:purge` (toutes les 10 min) pour les sessions **anonymes** inactives depuis plus de 30 min qui portent un brouillon ; `app:request-uploads:purge` (horaire) pour les justificatifs temporaires non rattachés de plus de 24 h | Suppression du **fichier de session** et des pièces temporaires, même si le citoyen ne revient jamais | Au plus environ 40 minutes après la dernière activité (30 min + période de la minuterie), si les unités sont installées |

Sans les unités installées, seule la collecte des sessions PHP (probabiliste) supprime physiquement un brouillon jamais revu : voir M6 dans le [runbook](../../runbooks/donnees-temporaires-depot-public.md).

**Périmètre strict de l'exception « purges techniques »** (validée le 18 novembre 2026) : uniquement (a) les brouillons anonymes abandonnés et (b) les justificatifs **temporaires non rattachés à un dossier**. Aucune donnée métier ni aucun justificatif rattaché à un dossier n'est supprimé automatiquement par ces tâches ; les sessions authentifiées ne sont jamais touchées (tests dédiés conservés). **Journalisation** : les commandes n'écrivent que des compteurs (brouillons, pièces, sessions authentifiées ignorées, sessions lues), jamais de donnée personnelle ; leur exécution est supervisée par les battements de cœur (`app:ops:status`).

## Conséquences
- `PublicRequestDraft` (expiration, suppression), `PublicDraftKeeper`, `AbandonedDraftPurger`, route `app_public_request_clear`, gabarit du formulaire public ; aucun changement de schéma.
- Les sessions authentifiées des agents ne sont pas affectées (tests dédiés).
- Hors périmètre : réglage du nettoyage général des sessions PHP (proposition M6 dans le runbook), qualification juridique du cookie (LOT 11), séparation des sessions.

## Documents concernés
[security.md](../../security.md), [runbook](../../runbooks/donnees-temporaires-depot-public.md), [lot-10-stabilisation-ux.md](../../lot-10-stabilisation-ux.md).
