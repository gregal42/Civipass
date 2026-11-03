# DM-008 — Aucune bibliothèque communale de zones en V1

- **Identifiant** : DM-008
- **Thème** : Zones
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée ; reprend UI-02 D2
- **Décisions remplacées** : Aucune.

## Contexte

UI-02 D2 : zones rattachées à un événement, plus précisément à la participation d'une commune ; pas d'écran de bibliothèque.

## Décision

Aucune bibliothèque communale de zones n'est prévue en V1.

## Conséquences fonctionnelles ou techniques

- Pas d'entité `Zone` communale ni de lien « source » ni d'instantané.
- Les zones se créent dans le cadre d'un événement.

## Documents concernés

- [UX-LOT-UI-02-architecture.md](../../ux/UX-LOT-UI-02-architecture.md) (§2 D2, §7 E1)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§1)
- [data-model.md](../../data-model.md)
- [architecture.md](../../architecture.md)
- [development-roadmap.md](../../development-roadmap.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- STD §1 (`MUNICIPALITY ||--o{ ZONE`, `ZONE o|--o{ EVENT_ZONE : snapshots`), `docs/data-model.md` (entités `Zone` et `EventZone` avec instantané), `docs/architecture.md` (« instantané par événement »), `docs/development-roadmap.md` (« zones et snapshots ») et `modele-donnees.md` contredisent cette décision. Documents à mettre à jour après validation ; ils ne sont pas modifiés silencieusement (écart S3/C4).
