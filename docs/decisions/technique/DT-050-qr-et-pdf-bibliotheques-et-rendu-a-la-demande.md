# DT-050 — QR code et PDF : bibliothèques et rendu à la demande

- **Identifiant** : DT-050
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-114 ; l'environnement PHP 8.4 dispose de `gd`, `imagick`, `mbstring`, `dom` et des polices DejaVu, sans moteur PDF externe.

## Décision

1. **QR** : `endroid/qr-code` utilisé **directement** (interface métier `QrCodeRenderer`), sans bundle ; rendu SVG de préférence. **PDF** : `dompdf` derrière une interface métier `PermitPdfRenderer`, **ressources distantes désactivées**, polices intégrées pour les caractères français.
2. Le PDF est **généré à la demande, jamais stocké**, et mentionne que **les droits effectifs sont vérifiés lors du scan**.
3. Compatibilité PHP 8.4 / Symfony 8.1 **à vérifier avant installation** (`composer require --dry-run`) ; installation au **LOT 05.2** seulement, avec accord.

## Conséquences fonctionnelles ou techniques

- Aucune dépendance ajoutée au LOT 05.1.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)

## Écarts documentaires signalés

Aucun écart.
