# DT-030 — Accès commerciaux CWS : table `municipality_commercial_access`

- **Identifiant** : DT-030
- **Thème** : Plateforme
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Étape 2 — entités Doctrine, mappings et contrats applicatifs ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-076 exige un accès commercial traçable, révocable, par commune, sans abonnement payant.

## Décision

Persistance minimale validée : table `municipality_commercial_access` (`id` UUID v7, `municipality_id`, `granted_at`, `expires_at` nullable, `revoked_at` nullable, `granted_by` (utilisateur CWS), `reason`, `created_at`). Plusieurs accès successifs par commune ; historique conservé ; aucune suppression physique. Valide si ni révoqué ni expiré et commune administrativement active. Attribution et révocation administrées depuis Sonata (DM-078), jamais par l'administration communale. **Ajout de l'étape 2 (traçabilité)** : `revoked_by` (nullable) enregistre l'auteur de la révocation ; à confirmer.

## Conséquences fonctionnelles ou techniques

- Entité `MunicipalityCommercialAccess` (module Municipality), repository sans suppression, source `CommercialAccessEntitlementSource` branchée sur `ChainMunicipalityEntitlementProvider` (étiquette `app.municipality_entitlement_source`).
- Le statut administratif de la commune reste contrôlé par `EventCreationPolicy`.
- Migration : étape 3.
- `revoked_by` validé (DM-079).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
