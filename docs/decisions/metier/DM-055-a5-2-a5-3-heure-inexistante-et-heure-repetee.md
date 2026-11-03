# DM-055 — A5.2 / A5.3 — Heure inexistante et heure répétée

- **Identifiant** : DM-055
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

CDC §5.2 ; SFD Annexe D (règle à confirmer) ; DM-021.

## Décision

A5.2, option C : un horaire local inexistant (passage à l'heure d'été) est **refusé** avec une explication compréhensible ; l'utilisateur choisit un horaire valide ; aucune correction silencieuse. A5.3, option C : un horaire local correspondant à deux instants (retour à l'heure d'hiver) est **signalé** ; l'utilisateur choisit l'occurrence souhaitée ; une représentation non ambiguë de l'instant retenu est conservée. Ces règles s'appliquent aussi aux créneaux récurrents concernés par une transition. Le comportement implicite de PHP ne doit pas être utilisé pour résoudre ces cas.

## Options examinées

- A5.2 (c) refuser — **retenue**
- A5.3 (c) signaler et demander le choix — **retenue**

## Conséquences fonctionnelles ou techniques

- Validation du domaine à la saisie (service dédié, voir DT-021), valable pour tout fuseau IANA autorisé (DM-062).
- Récurrences : voir DM-063 (heure inexistante) et DM-064 (heure ambiguë).
- Structure de stockage du choix d'occurrence : proposition DT-020 (Proposée, à valider).

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§5.2)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (Annexe D)

## Écarts documentaires signalés

- Points R3 et R4 levés par DM-063 et DM-064.
