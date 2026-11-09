# DM-118 — Liens d'accès bénéficiaire : cycle de vie, expiration et remise par l'agent

- **Identifiant** : DM-118
- **Thème** : LOT 05 — Laissez-passer
- **Statut** : Validée
- **Validation** : Précise DM-114 ; brief du LOT 05.2 validé par le commanditaire (9 novembre 2026). Fin effective arbitrée le 9 novembre 2026 (LOT 05.2 bis) ; point d'interprétation L5-8 en CONTRADICTIONS.
- **Décisions remplacées** : Aucune (complète DM-114 : la « révocation globale » devient une révocation **individuelle, explicite et auditée**).

## Contexte

DM-114 a posé le principe d'un lien bénéficiaire distinct du QR. Le LOT 05.2 le met en œuvre : page, PDF, remise par un agent.

## Décision

1. **Lien** : `/laissez-passer/{secret}`, secret de 256 bits (43 caractères base64url), **seule l'empreinte SHA-256 est stockée** (`permit_access_token`). Plusieurs liens actifs coexistent ; émettre un lien ne révoque pas les précédents ; chaque lien se révoque **individuellement**, explicitement, avec audit.
2. **Aucune récupération** : un secret n'est affiché **qu'une fois**, à l'agent qui l'émet. En cas de perte, l'agent en émet un nouveau.
3. **Réponse unique** : lien mal formé, inconnu, révoqué ou expiré = même page, même code 404 ; la révocation prime sur l'expiration.
4. **Expiration** = fin effective de l'événement + 30 jours, **calculée à l'usage** depuis l'événement (la date enregistrée à l'émission n'est qu'un instantané informatif). **Fin effective = le plus tôt de la fin planifiée et de la clôture** (arbitrage du 9 novembre 2026) : clôture anticipée → clôture ; clôture **tardive** → fin planifiée (une clôture administrative tardive ne prolonge jamais) ; fin planifiée explicitement repoussée avant la clôture (`Event::reschedule`) → la nouvelle fin planifiée. Sans fin planifiée, la clôture fait la fin ; sans fin planifiée ni clôture, pas encore d'expiration. C'est la même période opérationnelle que celle de `GrantWindowCalculator` (aucune contradiction avec le module Event). Impossible d'émettre un lien dont l'expiration est déjà dépassée.
5. **Remise par l'agent** : INSTRUCTOR et MUNICIPALITY_ADMIN de toute commune titulaire d'une **autorisation** du laissez-passer ; EVENT_MANAGER **uniquement** pour le laissez-passer qu'il a lui-même délivré par délivrance directe. Adhésion effective et ACTIVE exigée ; émission et révocation exigent en plus une **participation ACTIVE** (relue sous verrou). Commune non concernée, demande ou lien inconnu : refus uniforme 404.
6. **Indépendance** : le lien bénéficiaire n'ouvre jamais la page de contrôle et le jeton du QR n'ouvre jamais la page bénéficiaire ni le PDF ; émettre ou révoquer un lien ne touche jamais le QR.
7. **PDF** : généré à la demande, jamais stocké, sans le lien ni son secret ; il est une photographie des droits à l'instant de génération, le QR imprimé renvoyant aux droits courants (mention imprimée).

## Conséquences fonctionnelles ou techniques

- Aucune notification au LOT 05.2 : la remise est manuelle (copie du lien). L'envoi automatique relève du LOT 05.3 (DT-049) et réutilisera `PermitAccessService::issue()`.
- Audit : `PERMIT_ACCESS_LINK_ISSUED` / `PERMIT_ACCESS_LINK_REVOKED` (agent, commune, laissez-passer, lien) ; jamais le secret, l'empreinte ni l'URL.

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md), [security.md](../../security.md), [DT-051](../technique/DT-051-page-beneficiaire-pdf-et-protection-des-secrets.md)

## Écarts documentaires signalés

DM-114 §2 parlait d'une « révocation globale » (voir CONTRADICTIONS L5-8) ; la formule « clôture si elle précède la fin prévue » est précisée par la règle du plus tôt (L5-9, résolu).
