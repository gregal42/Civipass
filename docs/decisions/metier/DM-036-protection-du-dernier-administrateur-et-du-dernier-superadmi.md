# DM-036 — Protection du dernier administrateur et du dernier superadmin

- **Identifiant** : DM-036
- **Thème** : Comptes
- **Statut** : Validée
- **Validation** : Validée par le commanditaire pendant le LOT 01 et consignée dans docs/lot-01-schema.md ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

D15, D18 et D19 de `docs/lot-01-schema.md` ; SFD §3 : « ne peut désactiver le dernier admin actif ».

## Décision

Aucun compte ne peut être désactivé s'il laisse une commune ACTIVE sans administrateur effectif. Le dernier superadmin actif ne peut être ni désactivé ni rétrogradé, console comprise ; la récupération exceptionnelle est une désignation par la console, auditée.

## Conséquences fonctionnelles ou techniques

- `LastMunicipalityAdminGuard`, `UserManager`.

## Documents concernés

- [lot-01-schema.md](../../lot-01-schema.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§3)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§11.1, §14)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
