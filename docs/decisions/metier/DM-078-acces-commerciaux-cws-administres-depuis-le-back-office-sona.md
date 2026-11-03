# DM-078 — Accès commerciaux CWS administrés depuis le back-office Sonata

- **Identifiant** : DM-078
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (message « Étape 2 — entités Doctrine, mappings et contrats applicatifs ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

DM-076 ; DT-030.

## Décision

Les accès commerciaux sont administrés depuis le back-office Sonata CWS : attribution, révocation, consultation de l'historique, date d'expiration facultative. L'administration communale ne peut pas les attribuer. Les écrans Sonata ne sont pas développés à ce stade : ils relèvent du lot Administration CWS et devront être disponibles avant les premières démonstrations commerciales.

## Conséquences fonctionnelles ou techniques

- Aucun écran Sonata ni service d'attribution en TECH-02.1 : seuls la persistance, le contrat de droits et les actions d'audit (`COMMERCIAL_ACCESS_GRANTED`, `COMMERCIAL_ACCESS_REVOKED`) sont préparés.
- Le back-office reste en lecture seule (DT-018) jusqu'au lot Administration CWS.
- **Jalon produit** : lot Administration CWS livré avant les premières démonstrations commerciales.

## Documents concernés

- [backoffice.md](../../backoffice.md)
- [lot-02-schema.md](../../lot-02-schema.md)

## Écarts documentaires signalés

- Aucun écart documentaire relevé.
