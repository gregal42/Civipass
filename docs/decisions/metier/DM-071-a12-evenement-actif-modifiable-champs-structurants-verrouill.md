# DM-071 — A12 — Événement actif modifiable ; champs structurants verrouillés

- **Identifiant** : DM-071
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-001 ; DM-053 ; DM-062 ; DM-072.

## Décision

A12 précisé par P3 (option B). Un événement `ACTIVE` reste modifiable. Ajout du champ `operationalInstructions` sur `Event` ; les consignes restent modifiables pendant l'événement. Date de début **verrouillée** après activation ; date de fin **modifiable**. Les zones « Pendant toute la durée de l'événement » suivent les modifications de durée ; les créneaux spécifiques ne sont pas prolongés automatiquement ; les réductions de durée prennent immédiatement effet dans les calculs d'accès. Verrouillés après activation : commune organisatrice, type d'événement, fuseau horaire, référence publique, date de début. Toutes les modifications sont auditées. Modifiables : nom, description, consignes, date de fin, invitations, zones, validités, participations selon leurs règles.

## Conséquences fonctionnelles ou techniques

- `Event.operationalInstructions` (text, nullable) ajouté ; modifiable tant que l'événement n'est pas `CLOSED`.
- Droits calculés à la demande : suivi des durées et effet immédiat structurels ; les bornes d'un créneau spécifique ne sont jamais élargies.
- Audit avant/après de chaque modification.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-012)

## Écarts documentaires signalés

- Point P3 levé. Résiduel T2 : une date de fin située dans le passé (équivalente à une fin immédiate des accès sans clôture) n'est pas traitée.
