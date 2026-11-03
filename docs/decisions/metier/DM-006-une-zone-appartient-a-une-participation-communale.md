# DM-006 — Une zone appartient à une participation communale

- **Identifiant** : DM-006
- **Thème** : Zones
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée ; reprend UI-02 D2
- **Décisions remplacées** : Aucune.

## Contexte

STD : `EVENT_PARTICIPATION ||--o{ EVENT_ZONE`. SFD SCR-017 : « La zone créée reste attachée à une commune et un événement ».

## Décision

Une zone appartient à une participation communale. Elle est donc rattachée à une commune et à un événement.

## Conséquences fonctionnelles ou techniques

- `EventZone` n'a qu'un rattachement : sa participation. Commune et événement s'en déduisent : aucune incohérence possible par construction (recommandation technique).

## Documents concernés

- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§1)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-017)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§7)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- STD §1 et `modele-donnees.md` prévoient aussi une table `zone` possédée par la commune et liée aux zones d'événement (bibliothèque) : écarté par DM-008.
