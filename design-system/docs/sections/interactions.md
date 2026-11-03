# Interactions, contexte et permissions

## Contexte commune / événement / rôle

- La commune active est toujours visible. En largeur desktop et en rail, elle apparaît dans le sélecteur de la barre supérieure ; en compact, dans ce sélecteur et dans le bandeau de contexte, avec le rôle.
- L’événement actif est visible et sélectionnable dans l’espace de la commune. Sur le tableau de bord communal, le sélecteur indique « Tous les événements ».
- Le rôle exercé dans la commune active est affiché à trois endroits : barre supérieure, bandeau compact et menu du compte.
- Un utilisateur peut appartenir à plusieurs communes, chacune avec ses propres rôles. Le menu de commune affiche ces rôles sous chaque nom.
- **Changer de commune recharge l’espace** (navigation complète, Turbo Drive). Rubriques, compteurs, données et actions sont recalculés côté serveur, et une bannière `info` confirme la bascule : « Vous êtes maintenant dans l’espace de Saumos (rôle : Instructrice)… ». Si un formulaire est modifié, la sortie est d’abord confirmée.

## Permissions dans l’interface

- La barre latérale, la barre basse et le menu complémentaire n’affichent que les rubriques autorisées. Le serveur ne transmet jamais aux gabarits une entrée interdite.
- Une action non permise n’est pas affichée : par exemple « Créer un événement », « Gérer les zones », ou les actions d’un menu contextuel. Une action permise mais momentanément impossible est grisée et accompagnée de sa raison.
- Une URL directe vers une rubrique interdite affiche un écran 403 dans la coquille : une alerte `info` qui explique le rôle manquant et à qui s’adresser.
- Une ressource d’une autre commune peut renvoyer un 404 pour en masquer l’existence.

Le prototype illustre ces règles : Le Temple (administratrice + responsable d’événement + instructrice) voit les 7 rubriques ; Saumos (instructrice) n’en voit que 3, et sa page Utilisateurs renvoie un 403.

## Navigation

| Largeur | Navigation principale | Rubriques secondaires |
|---|---|---|
| ≥ 1024 px | Barre latérale (7 rubriques selon permissions) | — |
| 640–1023 px | Rail (icône + libellé, compteur) | — |
| < 640 px | Barre basse : Accueil / Demandes / Pass / Menu | Feuille « Menu » : Événements, Zones, Utilisateurs, Paramètres (selon permissions), changer de commune, se déconnecter |

## Parcours démontrés

1. **Ouvrir le tableau de bord**, puis :
   - lire la situation et ouvrir un événement ;
   - utiliser une action rapide : rechercher, instruire les demandes en attente, créer un laissez-passer, gérer les zones.
2. **Liste des demandes** :
   - rechercher par nom, téléphone, e-mail, plaque ou référence ;
   - filtrer par statut, événement et date de dépôt ;
   - trier en cliquant sur l’en-tête « Reçue » ou avec le menu de tri ;
   - retirer un filtre avec sa puce, ou les effacer tous ;
   - paginer par 20 ;
   - ouvrir une fiche.
3. **Fiche d’une demande** :
   - lire le dossier : identité, communes et décisions, zones, véhicules, justificatifs, historique ;
   - consulter un justificatif dans une modale ;
   - approuver dans la page : période, zones (ajout d’une zone non demandée tracé), modes, plaques, récapitulatif, confirmation ;
   - ou refuser, avec un motif facultatif ;
   - après la décision, rester sur le dossier : message focalisé, statut et historique à jour.
4. **Changer de commune** : Le Temple → Saumos, avec rubriques, compteurs, rôle et données mis à jour.
5. **Mobile** : barre basse, feuille Menu, barre d’actions Refuser / Approuver fixée en bas d’écran.

## Comportements

| Situation | Comportement |
|---|---|
| Ouverture du formulaire d’approbation | Déploiement dans la page (220 ms), défilement jusqu’au formulaire, focus sur le premier champ ; la barre d’actions mobile disparaît |
| Changement de décision avec saisie en cours | Confirmation « Changer de décision ? » |
| Annuler avec saisie en cours | Confirmation « Abandonner la saisie ? » |
| Quitter la page avec saisie en cours | Confirmation « Quitter sans enregistrer ? » (Turbo) ou alerte du navigateur |
| Envoi | Bouton bloqué, « Émission en cours… », autres boutons du formulaire désactivés |
| Erreur de saisie | Résumé d’erreurs focalisé, liens vers les champs, valeurs conservées |
| Erreur réseau | Alerte dans le formulaire, valeurs conservées, nouvel envoi possible |
| Succès | Alerte de succès focalisée en haut de la fiche ; statut, décisions et historique mis à jour ; pas de passage automatique au dossier suivant |
| Écart à la demande | Note « sera tracé dans l’historique » sur l’option, puis une ligne d’historique par écart |
| Véhicule non accordé | Plaques désactivées, avec un texte qui l’explique |
| Filtres en compact | Repliés derrière « Filtres (n) » ; la recherche reste visible |
| Lien vers un écran d’un autre lot | Prototype : toast « écran prévu dans un lot ultérieur » |

## États illustrés

| Écran | États |
|---|---|
| A — Tableau de bord | Initial ; chargement (squelettes) ; vide (onglets Suspendus, Participations externes) ; changement de commune (bannière) |
| B — Liste | Initial ; chargement ; aucun résultat (état vide de recherche) ; erreur de chargement avec « Réessayer » ; filtres actifs |
| C — Fiche | En attente, approuvée, refusée ; formulaire ouvert ; erreurs de saisie ; envoi en cours ; erreur réseau ; succès ; sans justificatif ; plusieurs communes |
| Transverse | Accès refusé (403) |

## LOT UI-02

Les écrans d’événements, de zones (avec leur validité) et de participation, leurs états et leurs interactions sont décrits dans `docs/lots/LOT-UI-02.md`. Règles ajoutées :

- une action permise mais impossible maintenant reste visible et focalisable, avec sa raison (Button `unavailable_reason`) ;
- une action non permise au rôle, ou interdite par le statut de la commune (non abonnée, suspendue), n’est pas affichée ;
- les actions groupées ne portent que sur les éléments de la commune de l’utilisateur ; elles demandent une confirmation nominative avant tout remplacement.
