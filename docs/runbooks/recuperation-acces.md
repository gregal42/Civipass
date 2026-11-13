# Récupération d'accès (LOT 07.1)

Étapes **techniques** réalisables par un super-administrateur CWS depuis Sonata (`/admin/cws/…`). Aucune décision juridique n'est prise ici ; l'identité de la personne qui demande la récupération est vérifiée **hors Civipass** (appel, courriel de la mairie) avant d'agir.

| Situation | Action | Garde-fou |
|---|---|---|
| Mot de passe oublié | la personne utilise « Mot de passe oublié » ; sinon fiche du compte › **Envoyer un lien de réinitialisation** (valable 30 min, envoyé à l'adresse du compte seulement, jamais visible de CWS) | réponse sans fuite d'existence du compte |
| Invitation non reçue ou expirée | fiche commune › Invitations › **Renvoyer** (l'ancien lien cesse de fonctionner) ; vérifier l'indicateur « Messagerie » de la fiche | un seul lien utilisable par adresse |
| Administrateur parti ou injoignable | **Inviter** le remplaçant ; après son acceptation, **Retirer** l'ancien | le dernier administrateur ne peut être ni retiré ni désactivé |
| Compte compromis | fiche du compte › **Désactiver** (effet à la prochaine requête), puis inviter le remplaçant | refusé s'il est le dernier administrateur : inviter d'abord |
| Commune sans administrateur | la liste des communes affiche « En attente d'administrateur » ; **Inviter** | une invitation n'est pas un administrateur |
| Aucun super-administrateur actif | console : `app:super-admin:create` (hors Sonata) | refusé dès qu'un super-administrateur actif existe |

Toute opération est tracée (historique administratif de la fiche, `audit_log`). Aucune donnée n'est supprimée.
