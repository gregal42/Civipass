# DM-116 — Modifications ultérieures des droits : plaques partagées, révocation communale irréversible, isolation des agents

- **Identifiant** : DM-116
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-112 (droits courants distincts des décisions), DM-111 (correction globale d'identité), DM-107 (instruction), règle de participation ACTIVE du 04.5.

## Décision

1. **Plaques partagées au niveau du laissez-passer.** Une modification de plaque concerne tout le laissez-passer et peut affecter le contrôle de plusieurs communes. Autorisée à **INSTRUCTOR** et **MUNICIPALITY_ADMIN** : adhésion habilitée, **participation ACTIVE**, au moins une autorisation communale de la commune qui agit, **confirmation explicite de l'effet global**, audit dans toutes les communes concernées ; formats et nombre maximal de plaques (DM-103) respectés. Les décisions initiales et les plaques déclarées de la demande ne sont jamais modifiées.
2. **Révocation communale irréversible.** INSTRUCTOR et MUNICIPALITY_ADMIN révoquent une autorisation de **leur propre commune** (participation ACTIVE) : **motif obligatoire**, horodatée, auditée ; la décision initiale n'est pas modifiée ; les droits de cette autorisation deviennent immédiatement inopérants ; les autorisations des autres communes subsistent. Aucune annulation par simple changement de statut : une nouvelle autorisation relève d'un nouveau processus métier explicite, à définir si le besoin apparaît.
3. **Prolongations, ajout et retrait de zones** : révisions immuables de l'autorisation (la décision n'est jamais modifiée) ; le QR ne change pas.
4. **Agents connectés : isolation communale conservée.** Un agent connecté ne consulte les détails d'autorisation que dans sa commune. L'habilitation d'appareils contrôleurs est **abandonnée** (DM-115) : le scan public suffit au contrôle.

## Conséquences fonctionnelles ou techniques

- Implémentation au LOT 05.5 ; aucune écriture de modification n'existe au 05.1.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
