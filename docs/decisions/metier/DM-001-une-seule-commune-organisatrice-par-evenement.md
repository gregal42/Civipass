# DM-001 — Une seule commune organisatrice par événement

- **Identifiant** : DM-001
- **Thème** : Événements intercommunaux
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §2.1 : la commune organisatrice invite les autres communes, gère l'événement et peut le suspendre globalement. Le STD modélise `event.organizer_municipality_id`.

## Décision

Un événement possède une seule commune organisatrice. Cette commune crée l'événement, gère ses informations générales, gère les invitations et pilote son cycle de vie conformément au SFD. Les communes participantes ne peuvent pas modifier les informations générales de l'événement.

## Conséquences fonctionnelles ou techniques

- `Event` porte une commune organisatrice obligatoire.
- Les écritures sur les informations générales sont réservées à l'organisatrice (rôle exact : voir arbitrage A7).
- Le caractère immuable de l'organisatrice après création est une **recommandation technique non validée**.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§2.1, §3, SCR-011 à SCR-015)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§4.1)
- [STD-V1.1.docx](../../references/STD/STD-V1.1.docx) (§1, §5)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Cycle de vie : le SFD (§6.2) confie la clôture globale à l'organisatrice, le CDC (§4.1) et l'ancien document SF-08 au superadmin CWS. La décision renvoie « au SFD » : le SFD prime (écart S1/N1 de l'audit).
