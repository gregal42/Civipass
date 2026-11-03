# DM-025 — Confirmation avant écrasement des configurations existantes

- **Identifiant** : DM-025
- **Thème** : Opérations groupées
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

Protéger contre l'écrasement involontaire de créneaux déjà saisis.

## Décision

Une confirmation est exigée avant remplacement des configurations existantes.

## Conséquences fonctionnelles ou techniques

- L'opération refuse de remplacer une configuration existante sans confirmation explicite.
- L'état précédent est conservé dans l'audit.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
