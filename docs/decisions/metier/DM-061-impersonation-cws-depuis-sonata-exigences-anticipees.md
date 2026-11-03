# DM-061 — Impersonation CWS depuis Sonata : exigences anticipées

- **Identifiant** : DM-061
- **Thème** : Droits CWS
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Consolidation documentaire et technique du LOT 02 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD Annexe B et SCR-050 (impersonation CWS) ; DM-005 ; A15 de l'audit.

## Décision

Le super-administrateur CWS doit pouvoir démarrer depuis Sonata une session d'impersonation pour assister les communes : choisir un utilisateur, agir avec ses droits dans l'application municipale, quitter explicitement l'impersonation. Garanties : réservé aux super-administrateurs habilités ; réauthentification récente et MFA ; bandeau permanent ; traçabilité de l'opérateur réel et du compte impersoné ; audit des opérations métier ; respect des permissions du compte impersoné ; confirmation renforcée des opérations irréversibles ; aucun accès aux mots de passe ; aucune élévation implicite. Ce n'est pas un partage d'écran. Développement dans un lot dédié ; les exigences d'audit et d'identification de l'opérateur réel sont anticipées dès maintenant.

## Conséquences fonctionnelles ou techniques

- Aucun développement au LOT 02 ni modification de Sonata : développement reporté à un lot **Administration CWS** dédié (DM-074).
- Permission distincte `CWS_IMPERSONATE`, non attribuée automatiquement à tous les super-administrateurs (DM-074).
- Les services du LOT 02 doivent pouvoir recevoir un acteur ET un opérateur réel : colonnes d'audit `actor_id`, `operator_id`, `batch_id` (DT-022, validée).
- Le back-office Sonata reste en lecture seule (DT-018) tant que le lot dédié n'est pas lancé.

## Documents concernés

- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (Annexe B, SCR-050)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)
- [backoffice.md](../../backoffice.md)

## Écarts documentaires signalés

- Le projet n'a pas de MFA aujourd'hui (authentification LOT 01.2) : prérequis du lot dédié.
- Complète DM-005 : l'accès CWS à une opération communale passe par l'impersonation explicite, jamais par un droit implicite.
