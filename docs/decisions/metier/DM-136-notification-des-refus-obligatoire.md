# DM-136 — Information du demandeur en cas de refus : obligatoire, asynchrone et fiable

- **Identifiant** : DM-136
- **Thème** : Notifications — LOT 08.5
- **Statut** : Validée (schéma et migration : à approuver avant exécution)
- **Validation** : décisions du commanditaire du 17 novembre 2026 (« E2 » et option A du LOT 08.5)
- **Décisions remplacées** : DM-110 (« Aucune notification au citoyen avant le LOT 07 »), en partie. Précise DM-117 et DT-049.

## Décision
1. **Le demandeur est informé du refus de chaque commune** : **notification obligatoire**.
2. Voie **A** : notification **asynchrone fiable** — suivi des échecs, nouvelles tentatives, jamais de perte silencieuse, jamais de blocage de la décision ; mêmes réessais que DT-049 (1, 5, 15, 60, 240 min ; abandon après 6 essais ou 24 h).
3. **E-mail** lorsque l'adresse existe. **Sans e-mail** : la situation est **visible des agents** (liste « refus à communiquer », fiche, compteur de l'accueil) avec une **procédure alternative** (appel, guichet, courrier) et la possibilité de marquer le demandeur comme informé. **Aucun SMS en V1.**
4. Contenu **minimal** : référence, événement, commune, libellé du motif du référentiel, invitation à contacter la mairie ; **jamais** le commentaire libre, un nom, un lien ni un QR. **Ne jamais exposer l'existence d'une autre demande** : aucun libellé de motif pour « Demande en doublon » (ni pour « Autre »).
5. Le **libellé juridique** du message reste à valider avant publication.

## Conséquences
Schéma proposé : [lot-08-5-schema.md](../../lot-08-5-schema.md) (une table `request_refusal_notice`) ; **aucune migration sans approbation du schéma**. Effectif seulement avec un SMTP réel (installation finale).

## Documents concernés
[lot-08-5-schema.md](../../lot-08-5-schema.md), [arbitrages-pilote-fiches.md](../../arbitrages-pilote-fiches.md) fiche 5, DM-110, DM-117, DT-049.
