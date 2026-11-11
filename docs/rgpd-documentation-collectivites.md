# Civipass — Documentation destinée aux collectivités (BROUILLON, LOT 05.6.1)

**Statut : brouillon de travail, sans valeur juridique.** Ce texte explique le fonctionnement prévu pour les données personnelles. Il ne contient **aucune mention légale définitive** : celles-ci doivent être rédigées et validées par chaque collectivité avec son DPO. Les **durées** indiquées sont des **hypothèses**, notées *(à valider)*. Référence : [politique RGPD et de conservation](rgpd-politique-conservation.md).

## 1. Quelles données Civipass collecte-t-il ?

| Données | Exemples | Origine |
|---|---|---|
| Demande | nom, prénom, e-mail, téléphone, adresse, motif, textes libres | le citoyen ou l'agent |
| Véhicules | plaques d'immatriculation | idem |
| Justificatifs | pièces téléversées | idem |
| Décisions | accord, refus, motif, périodes, zones | les agents de la commune |
| Laissez-passer | QR, historique des modifications | générés par Civipass |
| Notifications | adresse e-mail, type de message | générées par Civipass |
| Comptes des agents | nom, e-mail, rôles | la commune, l'éditeur |
| Journaux | qui a fait quoi, quand (sans le contenu personnel) | Civipass |

**Attention** : les champs de texte libre et les justificatifs peuvent contenir plus que prévu (par exemple des informations de santé). Il est conseillé de demander aux citoyens de n'y mettre que le nécessaire.

## 2. Pourquoi ces données sont-elles utilisées ?

Pour recevoir et instruire une demande de laissez-passer en situation de crise, délivrer l'autorisation, permettre son contrôle sur le terrain, informer le bénéficiaire et, plus tard, conserver la preuve des décisions pendant un délai limité. *(Bases légales à confirmer par chaque commune.)*

## 3. Qui peut y accéder ?

| Qui | Pendant l'événement | Après la clôture |
|---|---|---|
| Instructeur | demandes de **sa** commune | **aucun accès** |
| Organisateur (`EVENT_MANAGER`) | demandes de **sa** commune | accès à l'archive **uniquement s'il est désigné** pour cet événement et cette commune |
| Administrateur communal | demandes de **sa** commune | accès à l'archive de **sa** commune |
| Autres communes | **aucun accès** au dossier confidentiel d'une autre commune (lignes neutres seulement) | idem |
| Contrôleur (sans compte) | page du QR : nom, prénom, plaques | « Événement terminé » seulement |
| Bénéficiaire (sans compte) | son lien personnel | lien fermé |
| Éditeur (CWS) | exploitation technique, **aucun droit communal implicite** | idem |

Le QR est un lien opaque : toute personne qui le détient voit les informations de la page de contrôle (risque connu et assumé, à porter à l'information des bénéficiaires).

## 4. Que se passe-t-il à la clôture ?

- Les accès opérationnels sont **fermés immédiatement**, pour tous les rôles, ainsi que les liens des bénéficiaires.
- Les notifications pas encore envoyées sont annulées.
- La page publique du QR affiche seulement « Événement terminé ».
- Les données restent **identifiables** dans une **archive intermédiaire**. Elles ne sont **pas anonymisées**.
- Des statistiques anonymes sont calculées (compteurs seulement).
- Les demandes non traitées sont conservées avec leur statut réel.

## 5. Comment consulte-t-on les archives ?

- Écrans **séparés**, en **lecture seule**, par commune.
- Réservés à l'administrateur de la commune et à l'organisateur **explicitement désigné** pour cet événement et cette commune.
- Chaque consultation demande une **justification** et est **journalisée**.
- Si un **gel** est posé sur un dossier (contentieux), il se limite à **ce dossier** de **cette commune**, doit être motivé et **revu régulièrement**. Il n'ouvre rien aux autres communes.

## 6. Comment les données sont-elles supprimées ?

1. Après la durée d'archive *(hypothèse : 12 mois, à valider)* et sans gel, un **préavis** *(hypothèse : 30 jours)* est adressé aux administrateurs.
2. La **purge** s'effectue **par dossier communal** : données propres à la commune, puis données partagées quand plus aucune commune ne doit les conserver. Les fichiers sont supprimés.
3. Un **bordereau** et un **registre** (sans donnée personnelle) gardent la trace de la purge.
4. Le QR d'un dossier purgé répond comme un lien inconnu.
5. L'**événement** peut ensuite être supprimé sur demande explicite de l'administrateur de la commune organisatrice.
6. Les **comptes des agents** et les communes ne sont jamais supprimés automatiquement à cause d'un événement.
7. Les **sauvegardes** peuvent contenir les données pendant un délai borné *(politique à valider)* ; en cas de restauration, la purge est rejouée.

Les données d'**administration** (contrats, facturation) suivent d'**autres règles** et ne sont pas concernées par ce cycle.

## 7. Comment les personnes exercent-elles leurs droits ?

La personne s'adresse à la **commune** concernée *(point de contact à définir par chaque collectivité)*. La commune vérifie l'identité, répond *(délai à confirmer, un mois)* et trace la demande. Civipass permet à l'administrateur d'exporter le dossier de sa commune (jamais celui d'une autre) et de purger un dossier de façon anticipée si aucune finalité ne s'y oppose ; sinon la demande est refusée avec un motif.

## 8. À compléter par la collectivité (non rédigé ici)

Identité du responsable de traitement et du DPO · bases légales · destinataires et sous-traitants · durées validées · transferts éventuels · modalités exactes des droits · mentions légales de la notice d'information.
