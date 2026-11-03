# DM-031 — Supervision CWS limitée aux trois permissions du LOT 01

- **Identifiant** : DM-031
- **Thème** : Droits CWS
- **Statut** : Validée
- **Validation** : Validée par le commanditaire (back-office Sonata) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Validation du commanditaire lors de la mise en place du back-office Sonata en lecture seule.

## Décision

Les trois permissions de supervision de `MembershipAccessPolicy` (gérer les membres d'une commune, modifier son contact, lire son audit) sont conservées. Elles ne sont pas étendues aux événements, zones, demandes, décisions ou laissez-passer.

## Conséquences fonctionnelles ou techniques

- Sonata n'utilise pas ces permissions ; elles servent aux services applicatifs audités.
- Aucune extension aux modules du LOT 02.

## Documents concernés

- [backoffice.md](../../backoffice.md)
- [lot-01-schema.md](../../lot-01-schema.md)

## Écarts documentaires signalés

- Complète DM-005. `docs/security.md` accorde plus largement la « Supervision » (écart N5).
