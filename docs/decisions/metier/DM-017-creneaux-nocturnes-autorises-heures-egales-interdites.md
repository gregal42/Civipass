# DM-017 — Créneaux nocturnes autorisés, heures égales interdites

- **Identifiant** : DM-017
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-018 : « fin strictement après début ». UI-02 Q2 : « les créneaux qui passent minuit sont-ils permis, alors que la fin doit être strictement après le début ? ».

## Décision

Les créneaux nocturnes traversant minuit sont autorisés. Les heures de début et de fin identiques sont interdites.

## Conséquences fonctionnelles ou techniques

- Pour une récurrence, une heure de fin antérieure à l'heure de début signifie que la fin tombe le lendemain.
- Début = fin : refusé.
- L'instant de fin résolu doit être strictement postérieur à l'instant de début.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-018)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§8 Q2)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Écart apparent avec SFD SCR-018 (« fin strictement après début ») : levé en lisant la règle sur les instants résolus, non sur les heures du jour. À acter dans le SFD.
