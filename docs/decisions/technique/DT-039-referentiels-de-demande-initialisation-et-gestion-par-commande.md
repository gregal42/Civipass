# DT-039 — Référentiels de demande : persistance en base, initialisation idempotente ; administration ultérieure par Sonata

- **Identifiant** : DT-039
- **Thème** : Données
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages A1 à A7 de `DesignImport/UI-03/LOT-03.md`, 5 novembre 2026, et message « LOT 03 — Incrément 0 », 5 novembre 2026)
- **Décisions remplacées** : Aucune.

## Contexte

DM-097 et DM-098 exigent deux référentiels persistés, administrables et évolutifs. Sonata est en lecture seule (DT-018) ; le LOT 02 a fixé un précédent pour les types d'événement (DT-023).

## Décision

Les référentiels des motifs et des catégories de bénéficiaires sont **persistés en base**. Au LOT 03, seule leur **initialisation** existe : reproductible et idempotente (clé `code`), par la migration et par un service d'initialisation rejouable (`RequestReferentialInitializer`), à l'image de DT-023. **Aucune commande Symfony d'administration n'est développée.** Ils seront **administrables ultérieurement dans le back-office Sonata** ; aucune interface Sonata au LOT 03 et aucune personnalisation par commune en V1.

*Révision du 5 novembre 2026 (incrément 1) : la version initiale prévoyait une gestion technique par commande Symfony ; elle est remplacée par une administration future dans Sonata.*

## Conséquences fonctionnelles ou techniques

- Même principe que DT-023 : seules les entrées absentes sont créées à l'initialisation ; une entrée existante n'est jamais modifiée par l'initialisation.
- Une entrée utilisée n'est jamais supprimée (désactivation seule) ; les anciennes valeurs restent référencées par les dossiers existants.
- DT-018 (Sonata en lecture seule) devra être aménagé, par décision, lors de l'administration future.
- Les formulaires listent les entrées actives triées ; les dossiers conservent le libellé utilisé.

## Documents concernés

- [lot-03-schema.md](../../lot-03-schema.md) §10

## Écarts documentaires signalés

- Aucun écart.
