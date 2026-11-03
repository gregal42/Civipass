# Responsive

Les références de conception sont 1440 px (desktop), 768 px (tablette) et 375 px (mobile). Ce ne sont pas des points de rupture : chaque composant s’adapte à la place dont il dispose, grâce aux *container queries*.

## Conteneurs et seuils

| Conteneur | Seuil | Changement |
|---|---|---|
| `.cp-app` (coquille municipale) | < 1024 px | Barre latérale → rail de `rail-width` (icônes + libellés, compteur en pastille) |
| `.cp-app` | < 640 px | Rail masqué ; barre supérieure compacte (logo, commune, avatar) ; bandeau de contexte ; barre basse ; fil d’Ariane → lien retour ; barre d’actions de décision fixée en bas |
| `.cp-kpis` | < 680 px | 4 KPI → 2 × 2 |
| `.cp-event` | < 560 px | Compteur et action passent sous le titre |
| `.cp-list` | < 880 px | Colonne « Territoire » masquée |
| `.cp-list` | < 560 px | Tableau → cartes (nom, statut / référence, heure) |
| `.cp-facts` | < 480 px | Libellé au-dessus de la valeur |
| `.cp-dossier` | < 960 px | Une colonne ; les panneaux latéraux se rangent en grille sous la demande |
| `.cp-period` | < 520 px | Début et fin empilés |
| `.cp-table-wrap` | < 880 px | Colonnes `wide` masquées |
| `.cp-table-wrap` | < 640 px | Tableau → cartes (titre + statut, référence + date, territoire) |
| `.cp-filters` | < 640 px | Filtres repliés derrière « Filtres (n) », recherche toujours visible |
| `.cp-pass` | < 460 px | QR au-dessus des informations, actions pleine largeur |
| `.cp-decisions`, `.cp-docs` | < 420 px | Statut et action passent sous le texte |
| `.cp-stepper` | < 560 px | « Étape n sur N » + intitulé, barre segmentée |
| `.cp-lifecycle` | < 480 px | Frise verticale |
| `.cp-subnav` | — | Défilement horizontal dans son conteneur quand les rubriques ne tiennent pas |
| `.cp-evhead` | < 720 px | En-tête d’événement : informations sur 2 colonnes, actions pleine largeur |
| `.cp-vform` | < 600 px | Choix de validité et types de créneau empilés ; date seule sur sa ligne |
| `.cp-bulk` | < 600 px | Actions groupées pleine largeur ; barre non collée sous 640 px d’application |
| `.cp-invite` | < 560 px | Action de l’invitation pleine largeur, sous le texte |
| `.cp-public` | < 720 px | Contrôle QR sur une colonne (verdict, liste à vérifier, titulaire, droits, véhicules) |

Une variable CSS ne peut pas servir dans une `@container` : les seuils sont écrits en dur dans `civipass.css` et documentés ici.

## Règles

- Concevez d’abord à 375 px, puis vérifiez à 768 et 1440 px. Rien ne défile horizontalement, sauf une liste d’onglets trop longue, dans son propre conteneur.
- Sur mobile, les priorités opérationnelles restent en haut : contexte (commune, événement), compteur d’attente, décision.
- Un tableau ne se réduit pas : il devient une liste de cartes lisibles.
- La barre d’actions mobile (Refuser / Approuver) se place au-dessus de la barre basse et disparaît quand le formulaire inline est ouvert.
- Le contrôle QR est conçu pour le téléphone. Son état desktop reprend les mêmes blocs sur deux colonnes.
- Les marges latérales tiennent compte des zones de sécurité (`env(safe-area-inset-*)`).
- Les arrondis restent nets à toutes les largeurs : les conteneurs à enfants pleine largeur sont découpés (`overflow: clip`, compatible avec `position: sticky`), et les barres collées au bord de l’écran (barre basse, barre d’actions) n’ont pas de rayon.
