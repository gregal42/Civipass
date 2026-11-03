# DM-062 — R9 — Communes d'outre-mer prises en charge dès la V1 ; fuseau IANA de l'événement

- **Identifiant** : DM-062
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée ; précisé par P2 (message « Validation des derniers arbitrages TECH-02.1 »)
- **Décisions remplacées** : Remplace DM-054.

## Contexte

Évolution de A5.1 / DM-054 ; STD §0.

## Décision

Civipass prend en charge les communes françaises d'outre-mer dès la V1 (R9), y compris les différents fuseaux de la Polynésie française. **Option A (P2)** : le fuseau est proposé automatiquement selon la commune organisatrice ; **un seul fuseau IANA par événement**, appliqué à toutes les zones et participations ; visible avant l'activation et **verrouillé après**. Les instants sont normalisés en UTC ; les horaires sont saisis et affichés dans le fuseau de l'événement ; les récurrences y sont calculées ; R3 et R4 s'appliquent. Un **référentiel territorial fiable** doit permettre de déterminer le fuseau applicable à une commune.

## Conséquences fonctionnelles ou techniques

- Résolveur de fuseau territorial (DT-026) fondé sur un référentiel versionné : fuseau par département/collectivité, avec exceptions au niveau commune pour la Polynésie française.
- Le fuseau proposé est modifiable avant activation parmi la liste blanche.
- Les tests couvrent les territoires ultramarins.
- Le contenu du référentiel (commune → fuseau pour la Polynésie) doit provenir d'une source officielle (COG INSEE) : voir `docs/lot-02-schema.md` §6.5.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§0)

## Écarts documentaires signalés

- Écart S8 définitivement levé. Point P2 levé : défaut = fuseau de la commune organisatrice ; événement multi-territoires : un seul fuseau (celui de l'événement).
