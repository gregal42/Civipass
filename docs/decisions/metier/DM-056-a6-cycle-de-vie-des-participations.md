# DM-056 — A6 — Cycle de vie des participations

- **Identifiant** : DM-056
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §2 et §6.2 ne couvraient pas ces cas ; UI-02 D7 : invitation non déclinable.

## Décision

A6.1, option A : une participation `INVITED` ne bloque pas la clôture globale ; à la clôture de l'événement elle passe automatiquement à `CLOSED`. A6.2, option A : une participation suspendue peut être réactivée tant que l'événement n'est pas clôturé ; une participation active dans un événement suspendu ne rend pas ses zones opérationnelles. A6.3, option B : la participation de la commune organisatrice est clôturée automatiquement lors de la clôture globale ; les autres participations actives ou suspendues doivent avoir été clôturées au préalable ; les invitations non acceptées ne bloquent pas ; participation organisatrice et événement sont clôturés dans la même transaction ; l'opération est auditée ; l'organisatrice ne clôture pas arbitrairement les participations actives des autres communes.

## Options examinées

- A6.1 (a) — **retenue**
- A6.2 (a) — **retenue**
- A6.3 (b) — **retenue**

## Conséquences fonctionnelles ou techniques

- Gardes de la transition `ACTIVE/SUSPENDED → CLOSED` de l'événement.
- Clôture globale : verrou sur l'événement, vérification des participations, passage des INVITED et de l'organisatrice en CLOSED, audit, une transaction.
- Les droits opérationnels d'une zone exigent : événement ACTIVE et participation ACTIVE.
- La transition `DRAFT → CLOSED` est autorisée (DM-077).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§6.2, SCR-013)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (D7)

## Écarts documentaires signalés

- Écart N1 : le SFD donne la clôture globale normale à l'organisatrice (retenu) ; le CDC §4.1 et l'ancien SF-08 la réservent à CWS. Résolu : clôture normale = organisatrice (A6.3), clôture exceptionnelle forcée = CWS depuis Sonata (DM-065). CDC §4.1 à corriger dans la révision.
