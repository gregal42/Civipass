# DM-137 — Pas de verrou visible d'instruction de 15 minutes (option A, pilote)

- **Identifiant** : DM-137
- **Thème** : Instruction — arbitrages fonctionnels
- **Statut** : **Validée** (le 18 novembre 2026)
- **Validation** : commanditaire, LOT 08 (« Option A validée pour le pilote »).
- **Décisions remplacées** : Aucune. Confirme DT-041 (« aucun verrou fonctionnel de 15 minutes ») ; le SFD §6.1 (verrou visible) est **non retenu pour le pilote**.

## Décision
- **Option A** : conserver les protections transactionnelles et concurrentes existantes (verrou de participation, contrainte unique de décision, version optimiste de l'identité) ; **aucun verrou visible de 15 minutes**, aucun indicateur de présence en V1.
- **Alignement des spécifications** : le SFD §3/§4.3/§6.1 (SCR-028/029, REC-011) décrit un verrou visible de 15 minutes ; pour le pilote, **cette exigence est écartée par la présente décision** (les documents d'origine de `docs/references/` restent inchangés, la hiérarchie des sources donnant priorité aux décisions validées).
- **Pendant la recette (LOT 10)** : mesurer la fréquence des collisions (conflits de décision), vérifier la compréhension du message de conflit, et le débit des décisions simultanées d'une même commune. Un indicateur de présence informatif (option B') ne sera rouvert que si ces mesures le justifient, par une nouvelle décision.

## Documents concernés
[analyse-verrou-instruction.md](../../analyse-verrou-instruction.md), [arbitrages-pilote-fiches.md](../../arbitrages-pilote-fiches.md) fiche 2, DT-024, DT-041, DT-045.
