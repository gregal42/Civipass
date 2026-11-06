# STD — proposition de mise à jour V1.2 (LOT 03)

> **Statut : proposition non validée.** Complète [STD v1.1](../references/STD/STD-V1.1.docx) et [STD-V1.2-proposition-lot02.md](STD-V1.2-proposition-lot02.md) (non modifiés). Détail : [lot-03-schema.md](../lot-03-schema.md).

| Élément STD v1.1 / modèle de données | Proposition |
|---|---|
| `application` | Devient `request_permit_request` ; ajout `origin`, `entered_by_user_id`, `entered_by_municipality_id`, `address` (facultative), `category_id` + libellé copié, `motif_id` + libellé copié + `motif_detail`, `travel_mode` (`FOOT_BIKE` / `VEHICLE`), `privacy_attested_at`, `privacy_notice_version` ; suppression de `reason_code`, `requested_pedestrian/bicycle/car`, `status` global |
| `isProfessional`, `hasVehicle` (modèle) | Supprimés (DM-098, DM-103) |
| `application_municipality` | `request_permit_request_municipality` ; ajout `municipality_id` (dérivée de la participation) et `status` (`PENDING` / `APPROVED` / `REFUSED`, seul `PENDING` écrit, S2) |
| `application_requested_zone` | `request_permit_request_zone` ; ajout libellé copié |
| `application_vehicle` | `request_permit_request_vehicle` : plaque normalisée, format SIV/FNI, position, 1 à 10 par dossier en mode véhicule (S3) ; copie figée des plaques autorisées : LOT 04/05 |
| `stored_file` + `application_attachment` | Une table `request_permit_request_attachment` (nom, MIME, taille, clé opaque ; DOCX exclu, S4) |
| `application_idempotency` | `request_idempotency_key` : **incrément 2** (non créée à l'incrément 1) |
| Compteur de référence | `request_reference_counter` : une ligne par année civile (fuseau de l'événement), repart à 1 chaque année (`DEM-AAAA-NNNN`, DM-105, S1) |
| Nouvelles tables | `request_motif`, `request_beneficiary_category` (DM-097, DM-098, DT-039) |
| `ApplicationInstruction` (états STD §5) | `inReview` et `additionalInfoRequested` écartés ; matrice de transitions : LOT 04 |
| `POST /api/v1/public/events/{eventRef}/applications` | Route de dépôt publique par référence publique d'événement ; schéma JSON à formaliser avant l'incrément 3 |
| `AuditOrigin` | Ajout `PUBLIC` ; `AuditAction` : `REQUEST_SUBMITTED`, `REQUEST_REFERENTIAL_CHANGED` |
