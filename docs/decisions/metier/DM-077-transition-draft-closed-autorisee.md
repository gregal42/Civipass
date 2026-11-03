# DM-077 — Transition DRAFT → CLOSED autorisée

- **Identifiant** : DM-077
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Validation du schéma et lancement contrôlé du LOT TECH-02.1 ») ; date de validation non renseignée
- **Décisions remplacées** : Complète DM-056.

## Contexte

Le schéma de la révision 2 laissait la transition non définie.

## Décision

Un événement en préparation (`DRAFT`) peut être clôturé sans avoir été activé. La clôture est définitive ; aucune réactivation n'est possible ; l'événement et son historique sont conservés. La participation organisatrice et les éventuelles participations invitées sont clôturées dans la même transaction ; l'audit est obligatoire. Aucun statut supplémentaire n'est introduit.

## Conséquences fonctionnelles ou techniques

- Transition `DRAFT → CLOSED` ajoutée au workflow de l'événement ; le service de clôture normale la couvre (sans prérequis de zones).
- Les zones restent conservées (suppression logique non requise) ; elles n'ouvrent aucun droit (événement non `ACTIVE`).

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Point levé (transition non définie).
- Précisé par DM-085 (D1) : toutes les participations d'un brouillon sont clôturées, y compris celles déjà acceptées.
- Voir DM-092 : clôture globale automatique de toutes les participations ouvertes.
