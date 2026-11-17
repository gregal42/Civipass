# DM-137 — Verrou visible d'instruction de 15 minutes : décision ouverte

- **Identifiant** : DM-137
- **Thème** : Instruction — arbitrages fonctionnels
- **Statut** : **Proposée** (décision ouverte, à étudier)
- **Validation** : non validée ; le commanditaire a demandé le 17 novembre 2026 de comparer la spécification initiale et les protections actuelles.
- **Décisions remplacées** : Aucune. Rapport avec DT-041 (« aucun verrou fonctionnel de 15 minutes », validée) et SFD §6.1.

## Contexte et analyse
[analyse-verrou-instruction.md](../../analyse-verrou-instruction.md) : l'**intégrité** est garantie par la base (verrou de participation, contrainte unique de décision, version optimiste de l'identité) ; seul le **confort** manque (indicateur, perte de saisie).

## Recommandation de l'agent (non validée)
**Option A** (état actuel, SFD aligné sur DT-041) pour le pilote, **avec B'** (indicateur de présence informatif en cache, sans migration ni blocage) si la recette montre des collisions ; **pas de verrou fort** (option C).

## Décision
**Ouverte.** Choix attendu : A, ou A + B'.

## Documents concernés
[analyse-verrou-instruction.md](../../analyse-verrou-instruction.md), [arbitrages-pilote-fiches.md](../../arbitrages-pilote-fiches.md) fiche 2, DT-024, DT-041, DT-045.
