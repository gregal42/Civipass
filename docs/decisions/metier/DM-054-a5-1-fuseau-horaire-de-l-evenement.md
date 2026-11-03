# DM-054 — A5.1 — Fuseau horaire de l'événement

- **Identifiant** : DM-054
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Remplacée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Remplacée par DM-062 (R9 : fuseaux ultramarins, `Europe/Paris` n'est plus le seul fuseau autorisé).

## Contexte

STD §0 : règles locales évaluées dans le fuseau de l'événement ; DM-020 : heure de Paris.

## Décision

Option A. Champ `timezone` sur l'événement ; valeur autorisée en V1 : `Europe/Paris`. Les instants sont traités sans ambiguïté, avec stockage UTC lorsque pertinent.

## Options examinées

- (a) colonne limitée à Europe/Paris — **retenue**

## Conséquences fonctionnelles ou techniques

- `Event.timezone` (VARCHAR) contraint à `Europe/Paris` par le domaine.
- Bornes de l'événement en UTC ; horaires de créneaux locaux (voir DT-020).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§0)

## Écarts documentaires signalés

- Écart S8 levé (fuseau variable du STD vs « heure de Paris » : la colonne existe, la valeur est bornée). Les communes d'outre-mer restent hors périmètre V1 : à confirmer explicitement.
