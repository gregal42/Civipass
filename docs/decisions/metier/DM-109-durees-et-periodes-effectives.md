# DM-109 — Durées d'autorisation et périodes d'accès effectives

- **Identifiant** : DM-109
- **Thème** : LOT 04 — Instruction
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages de l'incrément 0 du LOT 04 et message « LOT 04 — Incrément 04.1 », 8 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-011 à DM-021 (validité des zones), DM-020 (fuseau), DM-082 ; roadmap LOT 05 (24/48/72 h, date personnalisée, fin d'événement).

## Décision

1. Modes : `H24`, `H48`, `H72`, `CUSTOM`, `UNTIL_CLOSURE`. Début global **immédiat** : `valid_from = decided_at` ; aucun début futur en V1.
2. Durées fixes : fin = décision + N heures **absolues** UTC (jamais en jours calendaires). Elles peuvent dépasser la fin de l'événement : le plafonnement se fait à l'évaluation.
3. `CUSTOM` : date et heure locales de l'événement, converties par `LocalTimeResolver` ; refus si antérieure ou égale à la décision, si elle dépasse la fin prévue de l'événement, ou si l'heure est inexistante ou ambiguë sans choix.
4. `UNTIL_CLOSURE` : `valid_until` reste nul ; la fin effective dépend de l'état courant de l'événement, jamais copiée.
5. Droits effectifs d'une zone = période globale ∩ période opérationnelle de l'événement (clôture comprise) ∩ périodes de la zone ∩ participation opérationnelle. Calculés à la demande, jamais copiés ; aucune durée fixe n'est prolongée pour compenser les heures où la zone est fermée.
6. **Approbation refusée** si une zone demandée à la commune est suspendue, supprimée, non confirmée ou sans validité configurée, **ou si une seule zone n'a aucune période d'accès effective non vide DANS la fenêtre globale choisie** (fenêtre ∩ événement ∩ périodes de la zone, intervalles semi-ouverts : un créneau qui commence à la fin de la fenêtre ou se termine à son début est incompatible). La vérification est faite pour **chaque** zone et pour chacun des cinq modes de durée ; les créneaux de zones différentes n'ont pas à se chevaucher. Échec intégral, jamais de décision partielle. Le **refus** reste possible sans ces conditions.
7. Distinction : date de décision, période globale, périodes d'accès par zone.

## Conséquences fonctionnelles ou techniques

- Fin de l'événement inconnue : aucune limite supérieure pour `CUSTOM`.
- Exemple : décision le 8 novembre à 10 h, durée 24 h ; zone A accessible le 8 de 14 h à 18 h (compatible), zone B accessible seulement le 10 (incompatible) : l'approbation est refusée en entier.
- Limite : la compatibilité est évaluée à la décision ; une modification ultérieure des créneaux d'une zone n'affecte pas la décision, mais les droits effectifs sont recalculés à l'évaluation (DT-042).

## Documents concernés

- [lot-04-schema.md](../../lot-04-schema.md)
- DM-011 à DM-021, DM-083

## Écarts documentaires signalés

- Roadmap : durées et fin d'événement rattachées au LOT 05 ; leur calcul est livré au LOT 04, le cycle de vie du laissez-passer (prolongation, révocation) reste au LOT 05.
