# Fondations

Les valeurs ci-dessous sont celles de `tokens.json`. Cette page dit lesquelles utiliser, et pour quoi.

## Couleurs

| Rôle | Tokens | Usage |
|---|---|---|
| Principale | `brand`, `brand-hover`, `on-brand`, `brand-soft` | Action principale, liens, sélection, élément actif |
| Secondaire (signal) | `signal`, `signal-fill`, `signal-soft` | Uniquement ce qui attend une action : demandes en attente, événement actif |
| Surfaces | `paper`, `surface`, `surface-2` | Fond de page, cartes et panneaux, zones en retrait |
| Textes | `ink`, `ink-2`, `ink-3` | Principal, secondaire, aide et horodatage |
| Bordures | `line` (décorative), `line-strong` (contrôles, ≥ 3:1) | Filets ; contour des champs, options, boutons secondaires |
| Succès | `ok`, `ok-soft`, `ok-band` | Approuvée, droit valide, confirmation |
| Alerte | `warn`, `warn-soft`, `warn-band` | Suspension, incertitude, hors ligne |
| Erreur | `bad`, `bad-soft`, `bad-band` | Refus, révocation, échec, saisie invalide |
| Information | `brand-soft` + `brand` | Information neutre, 403, changement de contexte |
| Neutre | `neutral-band`, `surface-2` + `ink-2` | Impossibilité de vérifier, brouillon, clôturé |

### États métier

Chaque état combine une couleur, une forme et un libellé : il reste lisible sans voir les couleurs.

| Domaine | État | Composant | Couleur | Forme | Libellé |
|---|---|---|---|---|---|
| Instruction | En attente | StatusPill `pending` | signal | point plein | En attente |
| | Approuvée | `approved` | ok | point plein | Approuvée |
| | Refusée | `refused` | bad | point plein | Refusée |
| Événement | Actif | `active` | signal | point plein avec halo + rubalise sur la carte | Actif |
| | Brouillon | `draft` | neutre | point creux | Brouillon |
| | Suspendu | `suspended` | warn | point carré | Suspendu |
| | Clôturé | `closed` | neutre | point carré gris | Clôturé |
| Participation communale | Concernée / Invitée | `concerned` / `invited` | neutre | point creux | Concernée / Invitée |
| | Active / Suspendue / Clôturée | `active` / `suspended` / `closed` + `label` | idem événement | idem | accordé au féminin |
| Laissez-passer | Valide | Validity `valid` | ok | coche | Valide |
| | Partiellement valide | `partial` | warn | triangle | Partiellement valide |
| | Pas encore valide | `upcoming` | neutre | horloge | Pas encore valide |
| | Expiré | `expired` | neutre | horloge | Expiré |
| | Révoqué | `revoked` | bad | croix | Révoqué |
| | Suspendu | `suspended` | warn | triangle | Suspendu |
| Contrôle | Valide en ligne | Verdict `valid` | ok-band | coche, aplat | Autorisation en cours de validité |
| | Aucun droit valide | `invalid` | bad-band | croix | Aucun droit valide |
| | Hors ligne < 24 h | `uncertain` | warn-band | triangle | Vérification incertaine — hors ligne |
| | Hors ligne > 24 h | `blocked` | bad-band | cadenas | Validation hors ligne impossible |
| | Serveur injoignable | `impossible` | neutral-band | point d’interrogation | Vérification impossible |

Le vert et le rouge ne se distinguent jamais par la teinte seule : l’icône, la forme et le mot portent l’information.

## Typographie

| Style | Taille | Interligne | Graisse | Usage |
|---|---|---|---|---|
| `cp-display` | 52 px | 1 | 800 | Compteur d’attente de l’événement actif (un par écran) |
| `cp-h1` | 36 px (26 px en compact) | 1,2 | 800 | Titre de page |
| `cp-h2` | 26 px | 1,2 | 800 | Titre de carte d’événement, verdict, nom du demandeur en compact |
| `cp-h3` | 20 px | 1,2 | 800 | Titre de section, de panneau, de modale |
| `cp-body` | 16 px | 1,5 | 400 | Texte courant, valeurs saisies |
| `cp-body-strong` | 16 px | 1,5 | 700 | Valeur d’une donnée, nom, libellé d’option |
| `cp-small` | 15 px | 1,5 | 400 | Méta, aide, navigation, boutons, cellules de tableau |
| `cp-caption` | 13 px | 1,4 | 400 | Horodatage, pied de carte, légende de tableau |
| `cp-eyebrow` | 13 px capitales | 1,3 | 700 | Sur-titre, en-tête de colonne, libellé de donnée en compact |
| `cp-ref` | 15 px mono | 1,4 | 400 | Références DEM / LP |
| `cp-plate`, `cp-plate-lg` | 17 / 24 px mono | 1 | 700 | Immatriculations |

**Tableaux** : en-têtes de colonne en `cp-eyebrow` couleur `ink-3` sur `surface-2` ; cellules en `cp-small` ; colonne de lien (nom) en 700 ; références en `cp-ref` ; dates en chiffres tabulaires ; légende (portée, tri) en `cp-caption` sous le tableau.

**Formulaires** : libellé en `cp-small` 700 au-dessus du champ ; valeur en `cp-body` ; aide en `cp-small` `ink-3` ; erreur en `cp-small` 700 `bad` avec icône ; légende de groupe en `cp-body` 800. La mention « (facultatif) » est en 400.

## Espacements et grilles

- Échelle : `space-1` 4 · `space-2` 8 · `space-3` 12 · `space-4` 16 · `space-5` 20 · `space-6` 24 · `space-7` 28 · `space-8` 32 · `space-10` 40 · `space-14` 56 px.
- Espacements internes : 20 px dans les panneaux en desktop, 16 px en compact ; 10 à 14 px dans les options et les lignes.
- Marges de page : 24 px en desktop et en rail, 16 px en compact ; 28 px entre sections, 22 px en compact.
- Grilles :
  - contenu limité à `content-max` (1 240 px), en une colonne fluide ;
  - fiche dossier en 1,75 / 1, passée à une colonne sous 960 px ;
  - KPI en 4 colonnes, puis 2 × 2 sous 680 px ;
  - options en grille automatique de 220 px minimum ;
  - contrôle QR en 2 colonnes, puis une seule sous 720 px.

## Rayons, ombres, dimensions

- Rayons : `radius-l` 14 px (panneaux, cartes, tableaux, formulaires inline, modales) › `radius-m` 10 px (options, KPI, alertes, menus, fichiers) › `radius-s` 6 px (boutons, champs, plaques, étiquettes) ; `radius-pill` pour pastilles et puces. Un enfant n’a jamais un rayon supérieur à son parent, et un conteneur dont un enfant touche le bord avec un fond le découpe (`overflow: clip`).
- Ombre : `shadow-raised` seulement pour la carte d’événement actif, les menus, les toasts, les modales et la feuille mobile.
- Dimensions :

  | Élément | Valeur |
  |---|---|
  | Cible tactile minimale | 44 px (`tap-min`) |
  | Bouton de décision, option | 52 px (`tap-comfort`) |
  | Champ, bouton, bouton icône | 44 px |
  | Barre supérieure | 64 px (56 px en compact) |
  | Barre basse | 58 px + zone de sécurité |
  | Barre latérale | 248 px ; rail 84 px |
  | QR | 72 / 120 / 200 px |
