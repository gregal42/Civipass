# Civipass — Sécurité et isolation V1

## Principes
Base MySQL partagée ; isolation **applicative obligatoire** par commune et événement. Les UUID ne sont pas des contrôles d'accès. Toute requête administrative filtre par périmètre autorisé et tout cas d'usage d'écriture revalide le droit et l'appartenance réelle des ressources.

## Comptes administratifs
`User` unique ; `MunicipalityMembership` unique par (user, commune) avec rôles cumulables. Permissions explicites et non hiérarchiques. Superadmin CWS global, réservé à Sonata ; **Sonata doit être protégé par firewall et `access_control` avant tout déploiement**. Un utilisateur désactivé ou membership inactif ne peut plus agir.

## Matrice indicative
| Action | Superadmin | Admin commune | Gestionnaire événement | Instructeur | Contrôleur sans compte |
|---|---|---|---|---|---|
| Paramétrer une commune | Oui | Oui, sa commune | Non | Non | Non |
| Créer un événement | Supervision | Selon permission explicite | Oui, si abonnement actif | Non | Non |
| Inviter une commune | Supervision | Selon permission | Oui, si organisatrice | Non | Non |
| Instruire et autoriser | Supervision exceptionnelle et auditée | Selon permission explicite | Non par défaut | Oui, sa commune | Non |
| Activer appareils PWA | Supervision | Selon permission | Oui, organisatrice | Non | Non |
| Scanner un QR public | Oui | Oui | Oui | Oui | Oui |
| Synchroniser hors ligne | Si appareil activé | Si appareil activé | Si appareil activé | Si appareil activé | Si appareil activé |

Cette matrice est un point de départ technique ; toute délégation fine devra être explicitée dans les tickets.

## Autorisation serveur
Voters pour les décisions contextuelles, services d'accès pour la politique métier, repositories scindés par contexte communal. Interdire les lectures par identifiant seul lorsque la ressource est sensible. Vérifier les chaînes : Application → ApplicationMunicipality → EventParticipation → Municipality ; PermitAuthorization → EventParticipation ; EventZone → EventParticipation. Tests négatifs inter-communes obligatoires.

## Scan public
QR = URL avec jeton opaque fort, sans données en clair. La page publique affiche seulement statut, événement, zones/communes, validité, plaques autorisées (exigence métier) ; pas de nom, coordonnées, pièces, notes ni historique. `noindex`, cache HTTP contrôlé (`no-store` recommandé pour vérifications), rate limiting, protections contre scraping et jetons devinables. Évaluer explicitement le risque de partage d'un QR et de divulgation des plaques.

## PWA
Activation par événement, courte durée, échange du code contre un secret propre à l'appareil ; ne pas réutiliser le code d'activation comme secret permanent. Stocker côté serveur uniquement empreintes de secrets. Révocation individuelle, rotation, expiration et contrôle de fraîcheur. La sécurité hors ligne ne peut garantir l'état instantané d'une révocation : avertir l'agent et bloquer après 24 h sans synchronisation, avertir après 12 h. Pas de compte personnel pour contrôleur, pas de journal systématique des scans.

## Fichiers et données
Stockage privé hors racine web, validation MIME/taille/extension, contrôle d'accès à chaque téléchargement, protection antivirus à envisager selon risque. Minimisation, chiffrement en transit, gestion des secrets hors Git, sauvegardes sécurisées. Fixer une durée de conservation et une procédure de purge documentées avant production. Audit des décisions, prolongations, modifications de véhicules/zones, invitations et révocations ; aucune géolocalisation.

## Tests bloquants
1. Agent commune A ne lit ni ne modifie notes/justificatifs locaux de B.
2. Agent A ne décide pas pour B, même en forgeant des IDs.
3. Organisatrice ne modifie pas les autorisations des invitées.
4. Commune sans abonnement actif ne crée pas d'événement.
5. Sortie d'une commune invalide seulement ses droits.
6. Clôture événement invalide tous les droits.
7. QR ne change pas après prolongation, changement de zones/véhicules.
8. PWA révoquée ne synchronise plus ; fraîcheur hors ligne appliquée.
9. Scan public n'expose que les champs autorisés.
