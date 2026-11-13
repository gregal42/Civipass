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
| [`lot-04-schema.md`](lot-04-schema.md) | Modèle de décision municipale LOT 04.1 (DM-106 à DM-111, DT-040 à DT-045) |
| [`runbooks/`](runbooks/README.md) | **Runbooks d'exploitation** (LOT 05.6.7) : sauvegarde, restauration et rejeu, cohérence, incidents, remise en service |
| [`lot-06-audit.md`](lot-06-audit.md) | Audit global et préparation de la V1 pilote (LOT 06) |
| [`lot-07-1-cadrage.md`](lot-07-1-cadrage.md) | Administration CWS dans Sonata (LOT 07.1) : cadrage validé et réalisation ; décisions [DM-121](decisions/metier/DM-121-administration-cws-des-communes-et-garde-fous.md), [DT-052](decisions/technique/DT-052-actions-nommees-d-ecriture-dans-le-back-office-cws.md), [DT-053](decisions/technique/DT-053-invitation-d-administrateur-communal-table-dediee.md) |
| [`lot-05-6-7-cadrage.md`](lot-05-6-7-cadrage.md) | Cadrage et réalisation du lot 05.6.7 (exploitation, restauration, rejeu, registre chaîné et ancrage) |
| [`exploitation-notifications.md`](exploitation-notifications.md) | Exploitation des notifications du bénéficiaire : clés, rotation, SMTP, distributeur, cron (exemple), supervision, purges, diagnostic, reprise, points ouverts avant production |
| [`../ops/nginx/README.md`](../ops/nginx/README.md) | nginx de production (V1 mono-instance) : journaux sans secret, rotation et filtrage, TLS/HSTS, installation et contrôles |
| [`exploitation-verification-publique.md`](exploitation-verification-publique.md) | Exploitation du contrôle public `/v/{jeton}` : interrupteur, pare-feu sans session, limitation de débit, panne du stockage, journaux, conditions d'activation |
| [`lot-05-schema.md`](lot-05-schema.md) | Laissez-passer LOT 05 : modèle, création atomique, droits calculés, scan public (DM-112 à DM-119, DT-046 à DT-051) |
| [`lot-05-5-2-cadrage.md`](lot-05-5-2-cadrage.md) | LOT 05.5.2 : périodes d'autorisation (prolongation, reprise, raccourcissement, B2), livré ; contrôle des notifications et validations préproduction |
| [`rgpd-politique-conservation.md`](rgpd-politique-conservation.md) | LOT 05.6.1 : politique RGPD et de conservation, responsabilités, registre des traitements, cycle de vie, AIPD, points à faire valider (PROPOSITION, durées = hypothèses) |
| [`rgpd-documentation-collectivites.md`](rgpd-documentation-collectivites.md) | LOT 05.6.1 : brouillon d'explication destiné aux collectivités (sans mention juridique définitive) |
| [`lot-05-6-4-cadrage.md`](lot-05-6-4-cadrage.md) | LOT 05.6.4 : cadrage (purge à deux niveaux, suppression de l'événement, registre, 404 après purge, décision C14) ; migrations M6 et M7 présentées, **non exécutées** ; **en attente de validation** |
| [`lot-05-6-3-cadrage.md`](lot-05-6-3-cadrage.md) | LOT 05.6.3 : cadrage (archives sécurisées, désignation des `EVENT_MANAGER`, gel de conservation, habilitation statistique) ; migrations M2 à M5 présentées, **non exécutées** ; **en attente de validation** |
| [`lot-05-6-2-m1-presentation.md`](lot-05-6-2-m1-presentation.md) | LOT 05.6.2 : migration M1 (`event_event.closure_statistics`) présentée pour arbitrage : classe Doctrine, SQL généré, entité, retour arrière ; **non exécutée** |
| [`lot-05-6-2-plan-implementation.md`](lot-05-6-2-plan-implementation.md) | LOT 05.6.2 : plan d'implémentation détaillé (barrière à trois couches, inventaire des clés d'idempotence et des distributeurs, protocole de concurrence des notifications, statistiques à deux niveaux, migration M1 préparée, ordre des développements et tests) ; **aucun code, aucune migration** |
| [`lot-05-6-2-cadrage.md`](lot-05-6-2-cadrage.md) | LOT 05.6.2 : cadrage technique de la clôture étendue (pré-inspection, barrière centrale, notifications, statistiques anonymes, migration M1 à valider) ; aucune implémentation |
| [`lot-05-6-cadrage.md`](lot-05-6-cadrage.md) | LOT 05.6 (version 2.2, 11 novembre 2026) : cycle de vie des événements, archive intermédiaire cloisonnée, purge par dossier et suppression, comptes ; arbitrages du commanditaire intégrés, arbitrages bloquants restants au §12 (bloquant avant la production) ; décision [DM-120](decisions/metier/DM-120-cycle-de-vie-des-evenements-archives-et-suppression.md) |
| [`lot-05-5-6-checklist-terrain.md`](lot-05-5-6-checklist-terrain.md) | LOT 05.5.6 : checklist courte des recettes de terrain (iPhone, Android, boîte e-mail réelle, PDF, deux communes, nginx natif) ; aucune recette marquée réussie |
| [`lot-05-5-6-recette.md`](lot-05-5-6-recette.md) | LOT 05.5.6 : recette transversale (matrice de couverture, anomalies, recettes manuelles restantes, proposition de clôture du LOT 05.5) |
| [`lot-05-git-decoupage.md`](lot-05-git-decoupage.md) | Plan de découpage en 4 commits (manifeste, script non exécuté, stratégie de vérification) : proposition, aucun commit réalisé |
| [`lot-05-git-inventaire.md`](lot-05-git-inventaire.md) | Inventaire des modifications non commitées depuis le LOT 05.4.1 et proposition de découpage en commits (aucun commit réalisé) |
| [`lot-05-5-5-cadrage.md`](lot-05-5-5-cadrage.md) | LOT 05.5.5 : remplacement du QR compromis : arbitrages validés le 10 novembre 2026, développé, sans migration |
| [`lot-05-5-4-cadrage.md`](lot-05-5-4-cadrage.md) | LOT 05.5.4 : véhicules d'un laissez-passer (ajout, retrait, réactivation, remplacement de plaques) : arbitrages validés le 10 novembre 2026, développé, sans migration |
| [`lot-05-5-3-cadrage.md`](lot-05-5-3-cadrage.md) | LOT 05.5.3 : zones d'une autorisation (ajout, retrait, réintégration, dernière zone, correction de libellé), livré (aucune migration) |
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
