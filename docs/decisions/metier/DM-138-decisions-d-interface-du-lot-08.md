# DM-138 — Décisions d'interface du LOT 08 : accueil, entrée Contrôle, sessions, doublons

- **Identifiant** : DM-138
- **Thème** : LOT 08 — Interface et sécurité des accès
- **Statut** : Validée
- **Validation** : décisions du commanditaire du 17 novembre 2026 (« LOT 08 : arbitrages et préparation du développement »)
- **Décisions remplacées** : Aucune.

## Décision
**08.1 — Accueil communal** : accueil opérationnel présentant les événements en cours et à venir, les demandes en attente, les demandes récemment traitées et les accès rapides aux principales actions ; **aucun indicateur nécessitant un calcul de validité coûteux**.
**08.2 — Contrôle** : entrée « Contrôle » dans l'interface communale présentant le contrôle en ligne, le contrôle hors connexion, les modes STANDARD et STRICT, les limites liées aux révocations et les consignes en cas de perte ou de vol d'un appareil ; **texte définitif à valider avant publication**.
**08.3 — Sessions** : agents 30 minutes d'inactivité et 12 heures absolues ; CWS 15 minutes et 8 heures ; avertissement 2 minutes avant l'expiration ; « Se souvenir de moi » désactivé ; **règles non appliquées aux brouillons publics**.
**08.4 — Doublons** : détection **informative**, sans refus automatique ; **mesure des performances sur 10 000 demandes avant toute proposition d'index** ; l'existence d'une autre demande n'est jamais exposée dans les messages publics.

## Conséquences
Valeurs de session configurables et bornées. Aucune migration dans ces quatre lots. Cadrage : [lot-08-cadrage.md](../../lot-08-cadrage.md).

## Documents concernés
[lot-08-cadrage.md](../../lot-08-cadrage.md), [audit-couverture-fonctionnelle.md](../../audit-couverture-fonctionnelle.md), DM-101, DM-102, DM-125, DM-128.
