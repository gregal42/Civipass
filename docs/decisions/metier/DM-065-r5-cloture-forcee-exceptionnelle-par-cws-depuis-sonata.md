# DM-065 — R5 — Clôture forcée exceptionnelle par CWS depuis Sonata

- **Identifiant** : DM-065
- **Thème** : Arbitrage TECH-02.1 (complément)
- **Statut** : Validée
- **Validation** : Arbitrage validé par le commanditaire (message « Validation des arbitrages R1 à R9 et A10 à A14 ») ; date de validation non renseignée
- **Décisions remplacées** : Aucune.

## Contexte

SFD SCR-052 (CWS peut forcer la clôture) ; CDC §4.1.

## Décision

Une fonction exceptionnelle de clôture forcée est conservée, depuis Sonata, réservée aux super-administrateurs CWS habilités. Exigences : justification obligatoire ; confirmation explicite ; affichage des participations encore ouvertes ; clôture transactionnelle de l'événement et des participations restantes ; invalidation immédiate des accès opérationnels ; audit complet (opérateur réel, justification, actions). Permission exceptionnelle distincte des permissions métier communales ; sans impersonation. La clôture normale reste régie par A6.3.

## Conséquences fonctionnelles ou techniques

- Le développement de la clôture forcée est **reporté au lot Administration CWS** (DM-074) ; TECH-02.1 ne livre que la clôture normale et prépare les mécanismes d'audit.
- Permission distincte `CWS_FORCE_CLOSE_EVENT`, non attribuée automatiquement aux super-administrateurs.
- Audit complet : opérateur réel, justification, participations clôturées avec leur statut précédent.

## Documents concernés

- [lot-02-schema.md](../../lot-02-schema.md)
- [SFD-V1.0-detaille.docx](../../references/SFD/SFD-V1.0-detaille.docx) (SCR-052)
- [CDC-V1.0-cahier-des-charges.docx](../../references/CDC/CDC-V1.0-cahier-des-charges.docx) (§4.1)
- [audit-coherence.md](../../lots/tech-02.1/audit-coherence.md)

## Écarts documentaires signalés

- Écart N1 / S1 résolu.
- Point P4 levé : lot Administration CWS ; habilitation par permission dédiée (DM-074).
- La clôture normale clôt désormais aussi toutes les participations ouvertes (DM-092) ; la clôture forcée CWS (lot dédié) se distingue par l'opérateur, la justification et la permission, non par ce comportement.
