# Récupération d'accès (LOT 07.1)

Étapes **techniques** réalisables par un super-administrateur CWS depuis Sonata (`/admin/cws/…`). Aucune décision juridique n'est prise ici ; l'identité de la personne qui demande la récupération est vérifiée **hors Civipass** (appel, courriel de la mairie) avant d'agir.

| Situation | Action | Garde-fou |
|---|---|---|
| Mot de passe oublié | la personne utilise « Mot de passe oublié » ; sinon fiche du compte › **Envoyer un lien de réinitialisation** (valable 30 min, envoyé à l'adresse du compte seulement, jamais visible de CWS) | réponse sans fuite d'existence du compte |
| Invitation non reçue ou expirée | fiche commune › Invitations › **Renvoyer** (l'ancien lien cesse de fonctionner) ; vérifier l'indicateur « Messagerie » de la fiche | un seul lien utilisable par adresse |
| Administrateur parti ou injoignable | **Inviter** le remplaçant ; après son acceptation, **Retirer** l'ancien | le dernier administrateur ne peut être ni retiré ni désactivé |
| Compte compromis | fiche du compte › **Désactiver** (effet à la prochaine requête), puis inviter le remplaçant | refusé s'il est le dernier administrateur : inviter d'abord |
| Commune sans administrateur | la liste des communes affiche « En attente d'administrateur » ; **Inviter** | une invitation n'est pas un administrateur |
| Second facteur (téléphone perdu, codes de secours perdus ou épuisés) d'un compte CWS | un **autre** super-administrateur, en console : `php bin/console app:super-admin:reset-two-factor <courriel> --operator=<id du super-administrateur> --reason="…" --yes` ; **dernier recours** (aucun autre super-administrateur) : la même commande **sans `--operator`**, depuis le serveur. Le compte se réinscrit à sa prochaine connexion (nouveau secret, nouveaux codes) | identité vérifiée **hors Civipass** avant ; motif obligatoire ; audit `TWO_FACTOR_RESET` (acteur : l'opérateur, ou « console ») ; jamais pour un compte communal |
| Aucun super-administrateur actif | console : `app:super-admin:create` (hors Sonata) | refusé dès qu'un super-administrateur actif existe |

Toute opération est tracée (historique administratif de la fiche, `audit_log`). Aucune donnée n'est supprimée.
