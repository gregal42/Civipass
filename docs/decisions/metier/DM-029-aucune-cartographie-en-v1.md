# DM-029 — Aucune cartographie en V1

- **Identifiant** : DM-029
- **Thème** : Périmètre V1
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §1.3 « Exclus V1 : cartographie » ; CDC §5.1 et §15 ; UI-02 D4 (ZoneMap obsolète).

## Décision

Aucune cartographie n'est introduite en V1.

## Conséquences fonctionnelles ou techniques

- Aucun champ de géométrie, aucune bibliothèque cartographique, aucun composant de carte.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§1.3)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.1, §15)
- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (D4)
- [CLAUDE.md](../../../CLAUDE.md)

## Écarts documentaires signalés

- `modele-donnees.md` prévoit des champs GeoJSON pour `zone` et `event_zone` : contraire à cette décision (écart S4).
