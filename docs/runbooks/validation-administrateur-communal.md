# Validation d'un administrateur communal désigné par un lien (SEC-016)

**Principe** : une adresse saisie par une commune organisatrice ne confère **jamais** automatiquement de droits d'administration. Le lien personnel permet de **créer le compte et d'attester** représenter la commune ; les droits ne sont attribués qu'après **validation par un super-administrateur CWS**, qui vérifie la légitimité du demandeur **hors Civipass**. Le parcours d'invitation intercommunale est préservé (contact → administrateur désigné → lien personnel → inscription), avec cette étape de validation en plus. L'invitation de l'événement n'expire pas (DM-086) : elle attend la validation.

**Délai** : aucun délai de traitement n'est garanti ni promis par l'application ni dans les messages. Il sera défini par la procédure opérationnelle de CWS (à fixer avant le pilote ; canal d'urgence en crise : la même commande, exécutée par un super-administrateur).

## 1. Ce que voit le demandeur
Après l'inscription : « Votre demande est en cours de vérification », sans délai annoncé, **aucun droit** attribué. Il pourra se connecter une fois la vérification faite. (Aucune notification automatique n'est envoyée : voir §5.)

## 2. Qui valide
Uniquement un **super-administrateur CWS actif**, identifié par son adresse (`--operator`) : le valideur, la date et le motif sont conservés dans la demande et dans l'audit (`ADMINISTRATOR_VALIDATION_REQUESTED / APPROVED / REJECTED`). Un compte non super-administrateur est refusé.

## 3. Procédure de vérification de légitimité (à appliquer avant toute approbation)
1. Lister les demandes en attente :
   `bin/console app:onboarding:validate-administrator --operator=<votre adresse CWS>`
2. Vérifier **hors Civipass** que le demandeur est habilité à représenter la commune : rappel au **standard officiel de la mairie** (numéro issu d'une source publique, jamais celui fourni dans la demande), ou courrier/e-mail officiel depuis une adresse institutionnelle connue, ou justificatif de fonction (arrêté de délégation, organigramme officiel). Ne jamais se fier à l'adresse e-mail seule.
3. Contrôler la cohérence : la commune de la demande est bien celle qui a été invitée ; la personne est connue de l'organisatrice si possible.
4. Décider :
   - approuver : `bin/console app:onboarding:validate-administrator <id> approve --operator=<adresse> --reason="<comment la légitimité a été vérifiée>" --yes`
   - refuser : même commande avec `reject`.
5. Le **motif est obligatoire** (10 à 500 caractères) : qui a été contacté, par quel canal, quand. Ne pas y consigner de données personnelles inutiles.

## 4. Garde-fous techniques
- L'approbation reprend les gardes d'attribution : refusée si la commune a **déjà un administrateur** (autre voie) ou n'est plus active ; elle crée uniquement le rôle administrateur communal (aucun abonnement, aucun droit commercial).
- Une demande ne se traite qu'une fois ; un refus n'attribue rien et laisse la trace.
- Les communes ouvertes par CWS (invitation d'administrateur 7 jours, parcours 07.1) ne passent pas par cette validation : CWS en est l'émetteur.

## 5. Limites connues (à arbitrer)
- Pas de notification automatique à CWS d'une demande en attente ni au demandeur d'une décision : à intégrer à la procédure opérationnelle (consultation régulière de la liste) ou à ajouter ultérieurement.
- Pas d'écran Sonata de validation : console uniquement (même choix que la réinitialisation du second facteur).
- Le lien contact peut encore concurrencer l'invitation CWS d'une commune qui attend son premier administrateur ; la validation CWS couvre ce cas (la demande est examinée par CWS, qui connaît l'invitation en cours).
