# Documentation Civipass — point d'entrée

## Organisation

| Emplacement | Contenu |
|---|---|
| [`references/`](references/MANIFEST.md) | Originaux centralisés (CDC, SFD, STD, modèle de données), formats Word/PDF d'origine, manifeste SHA-256 |
| [`references/SFD/`](references/SFD/) | SFD détaillé (référence principale), SFD courte, ancien document SF/RM, analyses |
| [`decisions/`](decisions/README.md) | Registre des décisions métier (`DM-`) et techniques (`DT-`) |
| [`lots/`](lots/README.md) | Documents par lot, dont l'audit [TECH-02.1](lots/tech-02.1/audit-coherence.md) |
| [`revisions/`](revisions/README.md) | Propositions de révision SFD V1.1 et STD V1.2 (non validées), contradictions, écarts UI-02 et UI-03 |
| [`lot-02-schema.md`](lot-02-schema.md) | **Schéma de données LOT 02 proposé (en attente de validation)** |
| [`lot-03-schema.md`](lot-03-schema.md) | **Schéma de données LOT 03 (demandes) proposé, en attente de validation** |
| [`ux/`](ux/README.md) | Référentiel UX, brief UI, architecture LOT UI-02 |
| [`archive/`](archive/README.md) | Vide : rien n'est archivé à ce jour |
| `tools/verify-docs.sh` | Vérifie les SHA-256 et les liens relatifs |

Documents techniques existants : [`architecture.md`](architecture.md), [`data-model.md`](data-model.md), [`security.md`](security.md), [`development-roadmap.md`](development-roadmap.md) (LOT 00), [`authentication.md`](authentication.md), [`testing.md`](testing.md), [`design-system.md`](design-system.md), [`backoffice.md`](backoffice.md), [`dev-workflow.md`](dev-workflow.md) (workflow économe : tests ciblés, sorties compactes), [`invitations-intercommunales.md`](invitations-intercommunales.md) (référentiel national, invitations par e-mail, délégation de l'administrateur communal).

## Documents de référence

| Document | Version / statut | Emplacement |
|---|---|---|
| **SFD V1.0 détaillé** | **Référence fonctionnelle principale** | [docx](references/SFD/SFD-V1.0-detaille.docx), [pdf](references/SFD/SFD-V1.0-detaille.pdf) (équivalence PDF non vérifiée) |
| SFD V1.0 courte | Sous-ensemble strict du détaillé ; conservée ([analyse](references/SFD/ANALYSE-version-courte-vs-detaillee.md)) | [docx](references/SFD/SFD-V1.0-courte.docx) |
| Ancien SF/RM V1.0 | **Non archivé**, en attente de validation ([matrice](references/SFD/ANALYSE-matrice-sfrm.md)) | [docx](references/SFD/SFD-historique-SF-RM-V1.0.docx) |
| CDC V1 | Document de travail, 1er nov. 2026 | [docx](references/CDC/CDC-V1.0-cahier-des-charges.docx) |
| STD v1.1 | Spécifications techniques | [docx](references/STD/STD-V1.1.docx), [md](references/STD/STD-V1.1.md) |
| STD v1.0 | Version antérieure | [md](references/STD/STD-V1.0.md) |
| Modèle de données V1.0 | Antérieur, partiellement contredit (S3, S4, S5) | [docx](references/STD/MODELE-DONNEES-V1.0.docx), [md](references/STD/MODELE-DONNEES-V1.0.md) |

SHA-256 de chaque original : [`references/MANIFEST.md`](references/MANIFEST.md).

## Hiérarchie en cas de contradiction métier

1. Décisions métier explicitement validées ([`decisions/`](decisions/README.md)) ;
2. SFD V1.0 détaillé ;
3. CDC V1 ;
4. STD v1.1 ;
5. Documents UX.

Les choix techniques respectent les décisions techniques validées, les contraintes du projet et la sécurité. Une contradiction est **signalée** (fiche de décision, audit), jamais corrigée silencieusement dans une spécification.

## Documents absents ou non centralisés

- Pas de PDF du CDC, de la STD ni du SFD courte.
- Documents du lot UI-002 d'origine : introuvables (remplacés par UI-02, décision D4).
- Prototype et charte `UI/old/` : hors périmètre, non centralisés.
- `cahier-des-charges-v1.pdf` (Downloads) : autre projet, exclu.
- Le document de lot UI-001 est dans [`../design-system/docs/lots/UI-001.md`](../design-system/docs/lots/UI-001.md).
