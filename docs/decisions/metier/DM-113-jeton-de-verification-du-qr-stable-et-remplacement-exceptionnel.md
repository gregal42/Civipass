# DM-113 — Jeton de vérification du QR : opaque, stable, remplaçable exceptionnellement

- **Identifiant** : DM-113
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

STD et `security.md` : QR = URL HTTPS avec jeton opaque fort, sans donnée en clair. DM-112 : un laissez-passer, un QR.

## Décision

1. Le QR encode **uniquement** une URL HTTPS portant un **jeton opaque** : au moins **160 bits** d'entropie, générateur cryptographiquement sûr, aucune donnée personnelle, aucune référence `DEM-` ou `LP-` comme secret. Le jeton est **stocké en clair** dans une table dédiée (régénération du QR et du PDF), avec date de création et date de retrait ; **un seul jeton actif** par laissez-passer.
2. Le jeton **ne figure jamais** dans les journaux applicatifs, les messages d'erreur ni l'audit ; les journaux d'accès HTTP le masquent (DT-048).
3. **QR stable par défaut.** Ne changent jamais le QR : nouvelle approbation, prolongation, modification de zones ou de véhicules, correction d'identité, révocation d'une autorisation communale.
4. **Remplacement exceptionnel** (LOT 05.5, pas 05.1) en cas de compromission : demandé par un MUNICIPALITY_ADMIN d'une commune ayant une autorisation sur le laissez-passer, participation ACTIVE, audité dans les communes concernées ; l'ancien jeton est retiré et indique « QR remplacé » **sans donnée supplémentaire** ; un nouveau jeton est généré. À ne pas confondre avec la révocation d'une autorisation communale.

## Conséquences fonctionnelles ou techniques

- Le modèle de données prévoit dès le LOT 05.1 la table des jetons et le retrait ; le mécanisme de remplacement vient au 05.5.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
