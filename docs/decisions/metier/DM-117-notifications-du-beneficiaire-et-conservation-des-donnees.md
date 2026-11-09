# DM-117 — Notifications du bénéficiaire et conservation des données

- **Identifiant** : DM-117
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

Le bénéficiaire n'a pas de compte : e-mail sans compte (ou remise en main propre). `DeferredMailQueue` (en mémoire, après la réponse) ne garantit ni durabilité ni dédoublonnage.

## Décision

1. **Notifications obligatoires** : première approbation (mise à disposition du laissez-passer, avec lien), nouvelle commune approuvée, prolongation, ajout ou retrait de zone, ajout, remplacement ou retrait de véhicule, révocation. La **suspension** ou la **clôture anticipée** entraînant une perte de droits est prise en compte dans la politique de notification ; la **clôture normale** d'un événement ne déclenche aucune notification individuelle en V1. Les modifications rapprochées sont **regroupées**. Jamais d'envoi avant validation de la transaction métier ; jamais plusieurs laissez-passer du fait d'une notification.
2. **Sans adresse e-mail exploitable** : aucun envoi ; le cas est tracé comme non distribuable et l'agent peut remettre le lien en main propre. Le SMS relève du LOT 07.
3. **Conservation : politique à finaliser avant la production.** Une matrice (demandes, laissez-passer, autorisations, jetons, révisions, notifications, audits) est préparée dans `lot-05-schema.md` §11 ; les **durées ne sont pas fixées** au LOT 05.1. **Aucune mise en production du LOT 05 n'est validée sans politique de conservation documentée et mécanisme de purge ou d'anonymisation adapté.**

## Conséquences fonctionnelles ou techniques

- Notifications : LOT 05.3 (outbox, DT-049) puis branchement des modifications au 05.5.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
