# DT-051 — Page bénéficiaire, PDF, rendu du QR et protection des secrets

- **Identifiant** : DT-051
- **Thème** : LOT 05 — Architecture
- **Statut** : Validée
- **Validation** : Brief du LOT 05.2 (9 novembre 2026) ; installation de `endroid/qr-code` ^6.1 et `dompdf/dompdf` ^3.1 autorisée par le commanditaire
- **Décisions remplacées** : Aucune (met en œuvre DT-048 et DT-050).

## Décision

1. **Routes** : `GET /laissez-passer/{secret}` et `/pdf` (publiques, sans compte ni session) ; écran agent `/communes/{c}/demandes/{r}/laissez-passer` (+ POST `…/liens`, `…/liens/{id}/revoquer`, CSRF, `no-store`). Le contrôle public `/v/{jeton}` n'existe pas encore (LOT 05.4) : le QR imprimé pointe vers une route à venir.
2. **QR** : contenu = `{DEFAULT_URI}/v/{jeton de vérification}` — domaine **uniquement issu de la configuration**, jamais de l'en-tête Host ; SVG en ligne pour la page, PNG pour le PDF ; correction d'erreurs moyenne, zone calme 24 px. Aucune donnée personnelle, aucun secret, jeton jamais régénéré au rendu. `VerificationToken::extract()` retrouve ce jeton sans réseau (PWA).
3. **PDF** : dompdf, ressources distantes, PHP et JavaScript désactivés, accès fichiers limité au dossier des gabarits, QR en data-URI, gabarit Twig à échappement automatique ; A4, accents (DejaVu Sans).
4. **Page** : gabarit **sans JavaScript ni ressource tierce** ; QR en plein écran par CSS `:target` ; politique `default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'none'; frame-ancestors 'none'`.
5. **En-têtes** (succès comme refus) : `Cache-Control: no-store, private`, `Pragma: no-cache`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` ; aucun cookie.
6. **Limitation de débit** par IP : `pass_access` 120 requêtes / 10 min ; `pass_failure` 20 **échecs** / 15 min, contrôlé **avant toute recherche** (429 + `Retry-After`).
7. **Journaux** : `SecretScrubbingLogger` décore le journal du framework et masque `/laissez-passer/…`, `/v/…` et les clés `secret`/`token` dans messages et contextes (par ex. « Matched route »). Les refus ne lèvent aucune exception. Journaux d'accès du serveur web : proposition nginx dans `lot-05-schema.md` § 10, **non appliquée**.
8. **Lecture** : `BeneficiaryPassReader` réutilise `PermitRightsEvaluator` (aucun second moteur) ; état par zone, jamais un statut global.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md), [security.md](../../security.md)

## Écarts documentaires signalés

Aucun.
