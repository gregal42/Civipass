# DM-020 — Horaires en Europe/Paris, instants en UTC

- **Identifiant** : DM-020
- **Thème** : Validité temporelle
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD Annexe D : règles de fuseau et d'heure d'été à confirmer ; STD §0 : dates techniques en UTC, règles horaires locales évaluées dans le fuseau de l'événement.

## Décision

Les horaires sont présentés en heure de Paris. Les instants sont stockés en UTC.

## Conséquences fonctionnelles ou techniques

- Instants (début et fin d'événement) en UTC ; créneaux en horaires locaux Europe/Paris (recommandation technique).
- **Évolué par DM-062 (R9)** : le fuseau est l'identifiant IANA de l'événement (outre-mer pris en charge) ; « heure de Paris » reste le cas de la métropole. DM-054 est remplacée.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-018, Annexe D)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§0)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- STD §0 : règles horaires évaluées « dans le fuseau de l'événement » (variable) ; la décision fixe « heure de Paris ». Les communes d'outre-mer ne sont pas à l'heure de Paris : point à acter (écart S8).
