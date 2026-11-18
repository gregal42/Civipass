# LOT 10 — Textes utilisateurs soumis à validation (18 novembre 2026)

**État au 18 novembre 2026 (après décision du commanditaire)**

| Texte | Décision | État |
|---|---|---|
| T1 page d'invitation | Accepté avec ajustement : « un nouveau lien pourra être envoyé si le précédent a expiré » | Intégré (commit `a65c8da`) |
| T2 fiche événement | Accepté, formulation imposée : « L'invitation reste en attente jusqu'à la clôture de l'événement. La commune invitée peut choisir de ne pas y donner suite. » | Intégré (`a65c8da`) |
| T3 e-mail d'invitation | Accepté ; vérification demandée : la date affichée doit correspondre à l'expiration réelle | Intégré (`a65c8da`) : jour **et heure** réels, heure de Paris (la date seule faisait croire à une fin de journée) |
| T4 message de refus | Accepté dans son principe ; **publication bloquée** jusqu'à validation juridique (voies de recours) ; notifications de refus non activées hors recette fictive | Non modifié |
| T5 consigne de contrôle | Cinq retouches acceptées dans leur principe ; statut « provisoire » conservé jusqu'à validation du texte intégral | Non modifié |

Texte du premier état du document (avant décision) :

**Aucun texte n'a été modifié dans le code ni dans les gabarits.** Pour chacun : formulation actuelle exacte, proposition exacte, raison. Les textes juridiques (refus) et la consigne de terrain restent soumis à la validation du service métier / juridique ; rien n'est publiable ni envoyable en réel avant.

## T1 — Page « Inviter une commune » (sous-titre)

Fichier : `templates/municipal/event/invite.html.twig`.

- **Actuel** : « L’invitation n’expire pas. Un e-mail contenant un lien sécurisé est envoyé à l’adresse de contact indiquée. »
- **Proposé** : « L’invitation reste en attente jusqu’à la clôture de l’événement. Un e-mail contenant un lien sécurisé, valable 14 jours, est envoyé à l’adresse de contact indiquée ; vous pourrez en envoyer un nouveau à tout moment. »
- **Pourquoi** : DM-086 (l’invitation n’expire pas, elle ne peut plus être acceptée après la clôture) ; le lien, lui, dure 14 jours (`InvitationLink::CONTACT_LIFETIME`). La phrase actuelle laisse croire que tout est sans limite.

## T2 — Fiche de l’événement, commune invitée

Fichier : `templates/municipal/event/show.html.twig`.

- **Actuel** : « L’invitation n’expire pas. Le refus d’une invitation n’est pas prévu en V1. »
- **Proposé** : « L’invitation reste en attente jusqu’à la clôture de l’événement. Elle ne peut pas être refusée dans cette version. »
- **Pourquoi** : même raison ; « V1 » est un terme interne, peu parlant pour un agent communal. Décision : DM-037 (UI-02 D7).

## T3 — E-mail d’invitation d’une commune (contact)

Fichiers : `templates/email/event_invitation.txt.twig` et `.html.twig` (ligne du lien ; le reste est inchangé).

- **Actuel** : « Pour répondre à cette invitation, ouvrez ce lien (valable jusqu'au JJ/MM/AAAA) : »
- **Proposé** : « Pour répondre à cette invitation, ouvrez ce lien (valable jusqu'au JJ/MM/AAAA). Passé cette date, demandez un nouveau lien à {organizerName} : l'invitation, elle, reste en attente. »
- **Pourquoi** : le destinataire sait quoi faire quand le lien expire ; il ne croit pas que l’invitation est perdue. Même ajout à l’e-mail destiné à l’administrateur (`event_invitation_administrator.*`, lien de 3 jours) : « …l'invitation, elle, reste en attente. »

## T4 — Message de refus envoyé au demandeur (texte juridique)

Fichier : `src/Notification/Application/RefusalNoticeComposer.php`. Objet : « Votre demande {référence} : décision de la commune de {commune} ».

**Actuel** (le motif n’apparaît pas pour les motifs « doublon » et « autre ») :

```
Bonjour,

Votre demande {référence} pour l’événement « {événement} » n’a pas pu être acceptée par la commune de {commune}.
Motif : {motif}.

Pour toute question, contactez la mairie de {commune} en indiquant la référence {référence} ({téléphone} · {e-mail}).

Ce message est envoyé automatiquement : merci de ne pas y répondre. Il ne contient ni lien ni laissez-passer.
```

**Proposé** :

```
Bonjour,

La commune de {commune} a examiné votre demande {référence} pour l’événement « {événement} » et n’a pas pu l’accepter.
Motif : {motif}.

Cette décision ne concerne que le territoire de la commune de {commune} ; les autres communes que vous avez sollicitées décident chacune pour le leur.

Pour toute question, contactez la mairie de {commune} en indiquant la référence {référence} ({téléphone} · {e-mail}).

[Mention des voies et délais de recours : à fournir par le service juridique ; non rédigée ici.]

Ce message est envoyé automatiquement : merci de ne pas y répondre. Il ne contient ni lien ni laissez-passer.
```

- **Pourquoi** : la formulation reste neutre et sans promesse ; l’ajout « chaque commune décide pour le sien » reprend ce que dit déjà le formulaire de dépôt. Les voies de recours ne sont pas inventées : à fournir ou à écarter par le juridique. Règles inchangées : motifs silencieux, aucun lien, aucun laissez-passer.

## T5 — Consigne de contrôle terrain (texte provisoire)

Fichier : `templates/municipal/control/guide.html.twig`, page « Contrôle sur le terrain » de chaque commune. La page affiche aujourd’hui un bandeau « **Texte provisoire** — Cette consigne doit être validée par le service métier avant d’être diffusée aux agents de terrain. »

**Actuel** (texte rendu, valeurs en heures issues des paramètres de la plateforme) :

```
Commune de Aubenas
 Contrôle sur le terrainComment lire et interpréter un laissez-passer

Texte provisoireCette consigne doit être validée par le service métier avant d’être diffusée aux agents de terrain.

## Deux façons de contrôler

## Contrôle en ligne

 Le contrôleur scanne le QR code du laissez-passer avec l’appareil photo de son téléphone ; la page affiche la situation à l’instant du scan. C’est la source la plus fiable : un laissez-passer révoqué ou remplacé est immédiatement signalé.

 Le contrôle en ligne est activé sur cette plateforme.

## Contrôle hors connexion

 Quand le réseau est insuffisant, l’application de contrôle installée sur un téléphone approuvé par la commune lit les données de la dernière synchronisation. Elle n’affiche jamais un résultat « en direct » :

- Orange « valide selon la dernière synchronisation » : le laissez-passer était valide à la dernière synchronisation ; l’âge des données est indiqué.
 
- « Données anciennes » : la synchronisation date de plus de 6 h ; resynchronisez dès que possible.
 
- « Indéterminé » : l’application ne peut pas conclure (laissez-passer inconnu de l’appareil, données trop anciennes). Ce n’est ni un refus ni une validation : contrôlez en ligne ou appliquez la procédure de votre autorité.
 
- Non valide, révoqué, remplacé : selon la dernière synchronisation.
 
 Au-delà de 12 h sans synchronisation, l’application cesse de donner un verdict et demande de se reconnecter.

 Le contrôle hors connexion est activé sur cette plateforme.

## Deux niveaux d’exigence

- Standard : le verdict hors connexion est utilisable avec ses réserves (orange).
 
- Strict : un verdict hors connexion n’est jamais suffisant ; le contrôle en ligne est exigé quand c’est possible.

## Limites à connaître

- Révocation : un laissez-passer révoqué après la dernière synchronisation reste affiché comme valide sur un téléphone hors connexion jusqu’à sa prochaine synchronisation (au plus 12 h ici). C’est la raison d’être du mode Strict.
 
- Le QR ne prouve pas l’identité : rapprochez toujours le nom et les plaques affichés de la personne et du véhicule contrôlés.
 
- Service indisponible : il n’existe pas de mode papier ; appliquez la procédure de votre autorité.
 
- Appareil partagé : privilégiez la navigation privée et ne partagez jamais un QR code.

## Perte ou vol d’un téléphone

 Prévenez immédiatement l’administrateur de la commune : il désactive l’appareil depuis l’écran « Appareils » de l’événement. L’appareil ne se synchronise alors plus et efface ses données dès qu’il retrouve le réseau ; tant qu’il reste hors réseau, ses données demeurent lisibles dans la limite de 12 h. Le verrouillage du téléphone est indispensable.

## Administration

 Les appareils se gèrent par événement : ouvrez l’événement, puis « Contrôle hors connexion ».
 Adresse de l’application de contrôle : [adresse de la plateforme]/controle/ (le code d’activation est remis à part, jamais ici).
```

**Modifications proposées** (le reste est conservé) :

1. « Orange « valide selon la dernière synchronisation » » → « **Bandeau orange** « valide selon la dernière synchronisation » » (ne pas reposer sur la seule couleur).
2. « (au plus 12 h ici) » → « (au plus 12 h) ».
3. Ajouter en fin de « Deux façons de contrôler » : « **En cas de doute, contrôlez en ligne.** »
4. « Service indisponible : il n’existe pas de mode papier ; appliquez la procédure de votre autorité. » → « Service indisponible : Civipass ne fournit pas de procédure papier ; appliquez la procédure de votre autorité. »
5. Retirer le bandeau « Texte provisoire » **uniquement après** validation métier.

- **Pourquoi** : lisibilité sur le terrain (accessibilité de la couleur, consigne simple en cas de doute) ; aucune règle modifiée.
