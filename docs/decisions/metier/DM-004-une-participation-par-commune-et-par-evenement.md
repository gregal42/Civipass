# DM-004 — Une participation par commune et par événement

- **Identifiant** : DM-004
- **Thème** : Événements intercommunaux
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

STD §4 : `UNIQUE(event_id, municipality_id)` sur `event_participation`.

## Décision

Une commune ne peut posséder qu'une participation par événement.

## Conséquences fonctionnelles ou techniques

- Contrainte d'unicité `(event_id, municipality_id)` en base, doublée d'un contrôle applicatif.
- Test de concurrence sur l'invitation simultanée de la même commune.

## Documents concernés

- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§4)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
