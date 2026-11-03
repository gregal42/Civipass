# DM-022 — Configuration de plusieurs zones simultanément

- **Identifiant** : DM-022
- **Thème** : Opérations groupées
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Besoin exprimé par le commanditaire pour accélérer la configuration des zones.

## Décision

Une commune doit pouvoir appliquer rapidement une même configuration à plusieurs zones, par sélection multiple.

## Conséquences fonctionnelles ou techniques

- Opération transactionnelle sur une sélection de zones d'une même participation.
- Un audit par zone modifiée.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Fonctionnalité **absente du SFD V1.0** (ni inscrite en « Inclus V1 » §1.2, ni en « Exclus V1 » §1.3) : à reporter dans les spécifications après validation.
