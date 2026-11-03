# États et arbitrages

## États transverses : quel composant utiliser

| État | Composant | Règle |
|---|---|---|
| Chargement | Skeleton (zone `aria-busy`) ; Verdict `loading` au contrôle ; bouton en cours d’envoi | Le squelette reprend la forme du contenu attendu. |
| Vide | EmptyState | Dire ce qui apparaîtra ici et comment. |
| Succès | Alert `success` avec `focus: true` | On reste sur l’écran ; le statut est mis à jour à côté. |
| Erreur de saisie | ErrorSummary + erreurs de champ | Les valeurs saisies sont conservées. |
| Erreur réseau | Alert `error` dans le formulaire | « … n’a pas été enregistré(e) », valeurs conservées, nouvelle tentative possible. |
| Accès refusé (403) | Alert `info` titrée « Accès refusé » | Expliquer le rôle manquant et à qui s’adresser. |
| Ressource introuvable (404) | Alert `info` | Peut masquer l’existence d’une ressource d’une autre commune. |
| Confirmation | Formulaire inline + récapitulatif ; ConfirmDialog pour sortie ou action irréversible | La conséquence est écrite avant le bouton. |
| Données indisponibles | EmptyState avec icône `wifioff` | Ne jamais afficher de valeur par défaut. |
| Connexion dégradée | PublicHeader `offline` + Freshness + Verdict `uncertain` / `blocked` | Jamais de vert hors ligne. |
| Événement suspendu ou clos, zone indisponible | StatusPill `suspended` / `closed`, Choice désactivée avec Tag `Suspendue`, Alert `warning` | L’option reste visible, désactivée, avec la raison. |

## Corrections apportées au prototype V1

- Les bordures des options et des contrôles passent de `line` à `line-strong` (#7c889b) pour atteindre 3:1 (WCAG 1.4.11). Le prototype utilisait un gris à 2,7:1.
- La coquille municipale gagne un mode rail entre 640 et 1023 px. Le prototype passait directement de la barre latérale à la barre basse, ce qui donnait une tablette en mise en page mobile.
- Les arrondis sont imbriqués et les conteneurs découpés (`overflow: clip`), y compris pour les segments de la jauge de fraîcheur.
- Les classes sont préfixées `cp-` pour éviter les collisions (Sonata Admin, bibliothèques tierces).

## Points à arbitrer (non tranchés, non inventés)

1. **Disposition tablette** : le rail de 640 à 1023 px est une proposition. Le référentiel ne fixe que le desktop (barre latérale) et le mobile (barre basse).
2. **Format des plaques** (françaises, étrangères) et nombre maximal de véhicules : le champ ne contrôle pas le format.
3. **Motif de refus** : obligatoire ou facultatif ? Il est facultatif dans les exemples ; seule la révocation exige un motif.
4. **Affichage d’un droit partiellement valide** (plusieurs communes) : la ligne barrée avec sa raison est une proposition (référentiel §14).
5. **Contenu public d’un titre sans droit valide** (nom du titulaire, motif détaillé) : non tranché (cahier des charges §17).
6. **Journal des scans** : le référentiel §10.1 l’exclut, le cahier des charges §9.3 prévoit un journal minimal. Aucun composant n’en parle.
7. **Libellés des seuils hors ligne** (« vigilance », « vigilance renforcée ») : les formulations sont proposées, les seuils de 12 h et 24 h sont conformes.
8. **Consultation du laissez-passer par l’administré** : prévue par UI-000 et le cahier des charges, exclue (« aucune page de suivi ») par le référentiel. Aucun composant dédié tant que ce n’est pas tranché.
9. **Thème sombre** : il suit le système par défaut. Faut-il un choix explicite dans les paramètres utilisateur ?

## Questions ouvertes du lot UI-001

10. **Rubriques par rôle** : le référentiel dit « entrées visibles selon permissions » sans fixer la matrice. Le prototype suppose que l’instructeur voit Tableau de bord, Demandes et Laissez-passer, et l’administrateur communal les 7 rubriques. Matrice rôle × rubrique × action à valider.
11. **Événement : sélecteur et filtre** : la liste des demandes a un filtre « Événement » alors que la barre supérieure porte déjà l’événement actif. Faut-il garder les deux (filtre pré-réglé sur l’événement actif) ou seulement le sélecteur ?
12. **Statut affiché pour une demande multi-communes** : la liste montre le statut pour la commune active seulement. Faut-il aussi un état global (ex. « 1 commune sur 2 a décidé ») ?
13. **Vue des décisions des autres communes** : la fiche montre le territoire et la décision de chaque autre commune, en lecture seule (« vue coordonnée limitée », CDC §7.1). Périmètre exact à confirmer avec la règle de séparation des données.
14. **Ordre de la liste « En attente d’instruction »** : les plus récentes d’abord, faute de règle de priorité (le CDC exclut une priorité automatique). Les plus anciennes d’abord serait-il plus juste pour les administrés ?
15. **Écart « 126 approuvées / 103 pass valides »** : le prototype l’explique par des laissez-passer approuvés dont la période commence plus tard (« Pas encore valide »). C’est une donnée fictive, pas une règle.
16. **Taille de page de la liste** : 20 demandes par page dans le prototype.
17. **Consultation des justificatifs** : aperçu dans une modale ou téléchargement seul ? Cela engage la sécurité des fichiers (CDC §11.2).
18. **Formats et tailles des justificatifs** : le référentiel fixe 5 fichiers, 10 Mo, PDF/JPG/JPEG/PNG/DOC ; le CDC §17 les liste encore à arbitrer. Le composant Upload applique le référentiel.
19. **Sélection des zones côté public** : le composant ZoneSelect (commune d’abord, puis « Commune entière » ou « Certaines zones ») est une proposition pour le lot du formulaire public.
20. **Changement de commune** : rechargement complet et bannière de confirmation. Faut-il mémoriser la dernière commune utilisée à la connexion ?
21. **Menu du compte** : seul « Se déconnecter » est proposé ; aucun écran de profil n’est prévu en V1.
22. **Actions rapides du tableau de bord** : reprises du CDC §10.1 (rechercher, instruire, créer un laissez-passer, gérer les zones). La création directe d’un laissez-passer au guichet relève d’un lot ultérieur.

## LOT UI-02 (remplace les points 23 à 41 du lot UI-002)

Décisions du commanditaire (2 novembre 2026), appliquées :

- **Zones** : pas de bibliothèque communale ; elles sont créées dans l’événement et rattachées à la participation (E1). Seul l’administrateur communal les gère, pour sa commune (E5). La clôture normale de l’événement relève de l’organisatrice (E4).
- **Validité** : pour chaque zone, « Pendant toute la durée de l’événement » ou « Sur des périodes spécifiques ». Sans configuration : « Validité à configurer », qui n’est pas un statut et n’ouvre aucun droit.
- **Accès ponctuel** : une date, une heure de début et une heure de fin.
- **Récurrence** :
  - deux dates, des jours, une heure de début et une heure de fin ;
  - passage de minuit possible ; le jour coché est le jour de début ;
  - début identique à la fin refusé.
- **Chevauchements** : autorisés, union des créneaux, avertissement non bloquant.
- **Heure** : Europe/Paris, calculs en UTC, heures inexistantes ou ambiguës signalées, aucun réglage de fuseau.
- **Configuration en masse** : sélection, « Configurer les zones sélectionnées », « Appliquer à toutes mes zones », activation ou suspension groupée, confirmation avant remplacement. Pas de modèles de configuration.

Questions encore ouvertes :

23. **Libellé « À confirmer »** pour « Commune entière » non confirmée (hypothèse Q6).
24. **Rubrique « Zones »** de la navigation principale, sans bibliothèque : elle ouvre les zones de l’événement en cours de la commune (Q7).
25. **Règle des heures inexistantes ou ambiguës** : proposition « instant valide suivant » et « première occurrence » (Q8, STD).
26. **Activation d’un événement** dont toutes les zones confirmées ont « Validité à configurer » : faut-il un avertissement non bloquant (Q9) ?
27. **Panneau de configuration en masse** : une seule validité (durée de l’événement ou un créneau) ; d’autres créneaux s’ajoutent zone par zone. À confirmer à l’usage.
