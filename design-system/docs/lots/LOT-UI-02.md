# LOT UI-02 — Livraison : événements, zones, validité, participation intercommunale

- Architecture et décisions : `LOT-UI-02-architecture.md`.
- Modifications à reporter dans le SFD et les STD : `LOT-UI-02-modifications-SFD-STD.md`.
- Journal des changements : `CHANGELOG.md` (racine).

| Livrable du brief | Où le trouver |
|---|---|
| A. Parcours UX | Architecture §5 ; visionneuse du prototype, rangée « LOT UI-02 » |
| B. Maquettes des 9 écrans et de leurs états | `prototype/templates/*.html.twig`, rendus dans `dist/prototype/` |
| C. Responsive 1440 / 768 / 375 | Visionneuse (largeurs) ; tableau « Adaptation » ci-dessous |
| D. Composants | Fiches `docs/components/{EventHeader,ZoneCard,ValiditySlot,BulkBar,InvitationCard,CommuneStatus}.md` ; galerie `dist/index.html` |
| E. Interactions | Tableau « Interactions » ci-dessous ; contrôleurs `cp--validity` et `cp--bulk` |
| F. Prototype navigable | `npm run prototype` → `dist/prototype/index.html` (aucun serveur) |
| G. Livraison technique | Cette archive ; intégration : `docs/sections/integration-symfony.md` § LOT UI-02 |

## Contextes de démonstration (données fictives)

| Commune active | Statut | Rôles | Ce qu’elle montre |
|---|---|---|---|
| Le Temple | Abonnée | Administratrice, gestionnaire événement, agent instructeur | Organisatrice (incendie, brouillon, suspendu, clôturé) ; invitation reçue (crue) ; configuration en masse ; toutes les actions |
| Saumos | Abonnée | Agent instructeur | Consultation seule, y compris de son propre événement (Crue exceptionnelle) ; zones en lecture seule |
| Pinsac-de-Lande | **Non abonnée** | Administratrice, gestionnaire événement | Participation gratuite (scénario 3) ; pas de création ; gestion de ses zones et de sa participation |
| Arbousiers-sur-Lande | **Suspendue** | Administratrice, gestionnaire événement | Bandeau de suspension ; ni création ni acceptation d’invitation |

Événements fictifs :

- Incendie du massif forestier (Le Temple, actif ; Saumos, Lugaret et Pinsac-de-Lande participent ; Mareuil-des-Pins invitée) ;
- Crue exceptionnelle (Saumos, active ; Le Temple et Arbousiers invitées) ;
- Accident — Route départementale 5 (brouillon récent) ;
- Incendie de la décharge des Landes (suspendu) ;
- Inondation de la Berle (clôturé).

## Écrans et états

| Écran | Fichier(s) | États montrés |
|---|---|---|
| 01 Tableau de bord | `{commune}/tableau-de-bord.html` | Plusieurs événements en cours (Le Temple) ; un seul (Pinsac) ; aucun (`?etat=vide`, Arbousiers) ; invitation en attente ; plusieurs communes ; commune qui ne peut pas créer (Pinsac, Arbousiers) ; chargement |
| 02 Liste | `{commune}/evenements.html` | Résultats ; aucun résultat après filtre ; liste vide (`?etat=vide`) ; événement terminé ; commune participante ou invitée ; erreur technique (`?etat=erreur`) ; chargement ; pagination |
| 03 Créer / modifier | `le-temple/evenements/nouveau.html`, `…/modifier.html` | Erreurs de validation ; fin avant début ; protection de la saisie ; confirmation de création et de modification ; action interdite (`pinsac-de-lande/evenements/nouveau.html`) ; erreur technique |
| 04 Fiche | `…/{événement}/index.html` | Brouillon récemment créé (activation indisponible, puis possible après confirmation de zone) ; en cours ; suspendu ; clôturé ; lecture seule (Saumos) ; organisatrice ou participante |
| 05 Zones | `…/zones.html` | Plusieurs zones avec recherche ; une seule zone ; aucune zone (`?etat=vide`) ; lecture seule ; « Validité à configurer » ; « À confirmer » ; **sélection multiple, configuration en masse, activation et suspension groupées** |
| 06 Fiche zone | `…/zones/{zone}/index.html`, `modifier.html`, `…/zones/nouvelle.html` | Consultation ; création (choix de validité facultatif) ; modification ; suspension, réactivation, suppression ; confirmation ; lecture seule (zone d’une autre commune) |
| 06 b Validité | `…/zones/{zone}/validite.html` | Pendant toute la durée de l’événement ; périodes spécifiques ; validité à configurer ; chevauchement (avertissement) ; erreurs (fin avant début, début = fin, aucun jour) ; changement d’heure ; heure inexistante ou ambiguë |
| 07 Communes | `…/communes.html` | Organisatrice, participantes, invitée ; non abonnée ; détail (modale) ; suspendre, réactiver ou clôturer sa participation ; invitation envoyée |
| 08 Invitation | `…/inviter.html`, `crue-exceptionnelle/index.html`, `participation.html` | Inviter (commune non trouvée, déjà participante, invitation en cours, sans abonnement) ; invitation reçue ; acceptée ; refusée par rôle ; commune suspendue |

La visionneuse propose les états `chargement`, `vide` et `erreur`, ainsi que le thème sombre, sur tous les écrans.

### Adaptation

| Largeur | Ce qui change |
|---|---|
| 1440 px | Barre latérale ; fiche événement avec colonne de synthèse (cycle de vie, en bref, journal) ; zones en grille de 3 ; barre d’actions groupées collée sous la barre supérieure |
| 768 px | Rail ; la synthèse passe sous le contenu ; zones en 2 colonnes ; tableaux en cartes sous 640 px de conteneur |
| 375 px | Barre basse et feuille Menu ; en-tête d’événement sur 2 colonnes et actions pleine largeur ; onglets défilants ; zones en une colonne ; barre groupée non collée, actions pleine largeur ; type de créneau et jours empilés ; résumé de validité toujours visible |

## Interactions

| Situation | Comportement |
|---|---|
| Créer un événement | Formulaire court ; erreurs résumées et reliées aux champs ; saisies conservées ; arrivée sur la fiche du brouillon, avec confirmation |
| Activer | Liste de contrôle (zone confirmée) ; bouton indisponible avec sa raison ; sinon ConfirmPanel avec récapitulatif et conséquences (SCR-015) |
| Suspendre, réactiver, clôturer l’événement | Suspension : motif obligatoire. Réactivation : confirmation. Clôture : indisponible tant qu’une participation n’est pas clôturée (communes nommées). |
| Zones d’une autre commune | Lecture seule : « Zone gérée par … », sans case ni action |
| Créer une zone | Libellé\*, type\*, description, état, puis choix de validité facultatif. Sans choix : « Validité à configurer ». |
| Validité | Choix durée de l’événement ou périodes. Créneaux : continu, ponctuel ou récurrent. Résumé en direct. Changement d’heure, heures inexistantes ou ambiguës signalés. Chevauchements autorisés, avec un avertissement. |
| Passer de périodes à « toute la durée » | Confirmation : « Les N créneaux seront remplacés » |
| Configuration en masse | Cases sur les zones de sa commune ; compteur annoncé ; « Tout sélectionner » ; « Appliquer à toutes mes zones » ; panneau dans la page ; confirmation nominative avant de remplacer ; activation ou suspension groupée avec confirmation |
| Inviter | Recherche INSEE ; communes déjà participantes ou invitées non sélectionnables ; commune non abonnée signalée ; contact et message facultatifs |
| Accepter une invitation | Administrateur de la commune invitée ; confirmation ; participation active |
| Quitter un formulaire modifié | « Quitter sans enregistrer ? » |
| Erreur technique | « Rien n’a été enregistré », saisies conservées, nouvelle tentative possible |

## Contrat de données Twig (par écran)

Toutes les pages reçoivent les variables du layout `app.html.twig` (UI-001). S’y ajoutent :

- `cstatus` : `{ state, commune }`, ou null ;
- `create` : `{ href }` ou vide.

| Écran | Variables |
|---|---|
| 01 | `invitations[]` (InvitationCard), `current[]`, `drafts[]`, `closed[]` (EventCard), `show_drafts`, `empty_current`, `has_requests` (+ blocs UI-001) |
| 02 | `table_rows[]` (cellules name, status, type, start, end, org, communes), `type_filter[]`, `rows_count`, `empty_text` |
| 03 | `ev{ name, type, description, sd, st, ed, et }`, `type_options[]`, `is_new`, `errors[]` |
| 04 | `head` (EventHeader), `head_actions`, `tabs[]` (SubNav), `state_alert`, `invitation{ organizer, invited, message, consequences[], can_accept, unavailable }`, `mine{ pill, free, text }`, `activation{ checks[], unavailable, consequences[], recap[] }`, `lifecycle_actions[]`, `panels{ suspend, close }`, `context_links[]`, `lifecycle`, `brief[]`, `history[]` |
| 05 | `groups[{ commune, own, meta, empty_text, cards[] (ZoneCard) }]`, `total`, `add_href`, `bulk{ count, event_range }` |
| 06 | `facts[]`, `attach[]`, `scope`, `event_range`, `slots[]` (ValiditySlot), `state_actions[]`, `readonly` ; formulaire : `zone`, `type_options[]` |
| 06 b | `scope`, `event_range`, `slots[]`, `overlap` (avertissement calculé côté serveur), `edit_forms[{ index, value }]`, `can_edit` |
| 07 | `rows[]`, `details[]` (modales), `parts_meta`, `invite_href`, `mine{ pill, free, text, actions[], panels }` |
| 08 A | `candidates[{ search, choice }]` (Choice) |

## Hypothèses encore ouvertes

Q6 (« À confirmer »), Q7 (rubrique Zones de la navigation), Q8 (règle des heures inexistantes ou ambiguës), Q9 (avertissement à l’activation) : voir l’architecture, §8.

Les données sont fictives. `prototype/proto.js` simule le serveur et ne fait pas partie du design system. Les messages de succès précisent quand la simulation ne recalcule pas l’écran.
