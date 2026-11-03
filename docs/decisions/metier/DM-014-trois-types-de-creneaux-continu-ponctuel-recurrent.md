# DM-014 — Trois types de créneaux : continu, ponctuel, récurrent

- **Identifiant** : DM-014
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

CDC §5.2 : accès ponctuel, plage continue entre deux dates, ou récurrence journalière/hebdomadaire. SFD SCR-018 : mode continu, ponctuel ou récurrent.

## Décision

Les créneaux sont de trois types : continu, ponctuel, récurrent.

## Conséquences fonctionnelles ou techniques

- Une entité de créneau avec un type discriminant.
- **Les champs exacts du type « continu » ne sont pas définis** (dates seules ou dates et heures) : point A10 toujours ouvert.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-018)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.2)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- CDC §5.2 : « récurrence journalière/hebdomadaire » ; la décision DM-016 la généralise par « jours sélectionnés » (couvre les deux).
