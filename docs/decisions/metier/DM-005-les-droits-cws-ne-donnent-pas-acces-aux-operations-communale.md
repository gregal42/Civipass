# DM-005 — Les droits CWS ne donnent pas accès aux opérations communales

- **Identifiant** : DM-005
- **Thème** : Événements intercommunaux
- **Statut** : Validée
- **Validation** : Cadrage produit — décision du commanditaire (message de lancement du LOT TECH-02.1) ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD §3 : le Super Admin CWS possède des pouvoirs exceptionnels, tracés ; Annexe B : support en lecture audité, impersonation via le contexte de la commune concernée.

## Décision

Les droits CWS ne donnent pas automatiquement accès aux opérations métier communales (absence de droits communaux implicites pour CWS).

## Conséquences fonctionnelles ou techniques

- Aucune permission communale n'est accordée à `ROLE_SUPER_ADMIN` pour les événements, participations et zones.
- Tout accès CWS à une opération communale passe par un mécanisme explicite et tracé (impersonation SCR-050, non conçue à ce stade).
- Les trois permissions de supervision du LOT 01 sont conservées (voir DM-031).
- Trois mécanismes distincts, jamais confondus : (1) droits d'administration technique CWS (Sonata, lecture et actions techniques définies) ; (2) impersonation auditée (DM-061, lot dédié) ; (3) clôture forcée exceptionnelle (DM-065). Aucun ne donne un droit communal métier implicite.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (§3, Annexe B)
- [security.md](../../security.md)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- `docs/security.md` (matrice) accorde à CWS une « Supervision » sur la création d'événement, l'invitation et l'activation d'appareils : à réconcilier avec cette décision (écart N5).
- SFD Annexe B : « CWS impersonation : Oui » sur plusieurs actions ; la décision n'interdit pas l'impersonation explicite et tracée, seulement l'accès **automatique**.
