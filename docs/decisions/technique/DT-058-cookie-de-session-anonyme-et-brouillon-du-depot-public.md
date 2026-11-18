# DT-058 — Cookie de session anonyme et brouillon du dépôt public

- **Identifiant** : DT-058
- **Thème** : LOT 10 — Stabilisation UX (O-2) : minimisation et vie privée du dépôt public
- **Statut** : **Proposée** (à valider ; qualification juridique du cookie : LOT 11)
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

## Conséquences
- `PublicRequestDraft` (expiration, suppression), `PublicDraftKeeper`, `AbandonedDraftPurger`, route `app_public_request_clear`, gabarit du formulaire public ; aucun changement de schéma.
- Les sessions authentifiées des agents ne sont pas affectées (tests dédiés).
- Hors périmètre : réglage du nettoyage général des sessions PHP (proposition M6 dans le runbook), qualification juridique du cookie (LOT 11), séparation des sessions.

## Documents concernés
[security.md](../../security.md), [runbook](../../runbooks/donnees-temporaires-depot-public.md), [lot-10-stabilisation-ux.md](../../lot-10-stabilisation-ux.md).
