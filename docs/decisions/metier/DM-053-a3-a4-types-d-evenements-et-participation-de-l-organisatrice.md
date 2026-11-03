# DM-053 — A3 / A4 — Types d'événements et participation de l'organisatrice

- **Identifiant** : DM-053
- **Thème** : Arbitrage TECH-02.1
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-053 (référentiel des types) ; SFD §2 (cycle de vie).

## Décision

A3, option A : entité de référence `EventType` (identifiant, code unique, libellé, ordre d'affichage, actif/inactif), évolutive, non limitée aux crises (incendies, inondations, accidents, événements sportifs, manifestations, autres). Aucune interface d'administration des types dans le LOT 02. La liste initiale doit être **proposée pour validation avant initialisation définitive**. A4, option A : à la création, l'événement est `DRAFT` et une participation `ACTIVE` est créée automatiquement pour la commune organisatrice ; elle permet de préparer ses zones ; aucun accès opérationnel tant que l'événement n'est pas actif ; une seule participation par couple événement/commune. **Liste initiale validée (neuf types)** : `fire` Incendie, `flood` Inondation, `storm` Tempête / événement climatique, `accident` Accident / incident majeur, `security` Sécurité publique, `sport` Manifestation sportive, `cultural` Manifestation culturelle, `works` Travaux / intervention technique, `other` Autre événement.

## Options examinées

- A3 (a) entité `EventType` — **retenue** ; (b) enum ; (c) chaîne libre — écartées
- A4 (a) ACTIVE à la création — **retenue** ; (b) non active jusqu'à l'activation — écartée

## Conséquences fonctionnelles ou techniques

- `EventType` : cinquième entité ; pas d'écran d'administration au LOT 02.
- Initialisation reproductible et idempotente des neuf types (DT-023) ; les types restent des données de référence évolutives.
- Création d'événement = événement + participation organisatrice ACTIVE + zone « Commune entière » proposée (A8), dans une seule transaction.
- A12 (DM-071) : le type d'événement est verrouillé après activation.

## Documents concernés

- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [modele-doctrine.md](../../lots/tech-02.1/modele-doctrine.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-012, SCR-053)

## Écarts documentaires signalés

- Écart N3 levé : l'ancien SF-05 (« activer sa participation » à l'activation de l'événement) est supplanté.
- Le SFD ne décrit pas de participation « ACTIVE » pour l'organisatrice avant activation de l'événement : précision à intégrer.
