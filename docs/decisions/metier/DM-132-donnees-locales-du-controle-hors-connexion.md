# DM-132 — Données locales du contrôle hors connexion : minimisation, conservation, absence de journal

- **Identifiant** : DM-132
- **Thème** : LOT 09 — Contrôle hors connexion
- **Statut** : Validée (sous réserve de l'AIPD)
- **Validation** : arbitrages du commanditaire du 17 novembre 2026 (A2, A7, A11, A14, A16, A19)
- **Décisions remplacées** : Aucune (précise DM-127 et DM-115 §6 ; sous réserve de DM-117).

## Contexte
[DM-129](DM-129-controle-hors-connexion-obligatoire-en-v1.md), [DM-130](DM-130-modele-de-confiance-hors-connexion.md) ; DM-115 §3, DM-127.

## Décision
1. **Données limitées à la commune ayant activé l'appareil** ; jamais celles d'une autre commune.
2. **Stockage minimal** : empreinte du jeton (jamais le jeton), nom et prénom, plaques, zones et fenêtres de validité de la commune, état ; laissez-passer valides ou à venir uniquement. **Jamais** : adresse postale, e-mail, téléphone, pièces, motif, informations de la demande.
3. **Aucun journal individuel de scan**, ni sur l'appareil ni sur le serveur (DM-127 étendu) ; seuls des journaux techniques d'activation et de synchronisation, sans donnée personnelle.
4. **Pas d'indicateur « vérification en ligne obligatoire » par zone** en V1 (le mode `STRICT` en tient lieu).
5. **Empreintes révoquées ou remplacées** : conservation **bornée** (cadrage §27.1) ; suppression des données identifiantes au-delà de `hard` ; suppression totale à l'expiration de l'appareil, à la clôture, à la désactivation.
6. **Limites reconnues** : aucune suppression garantie sur un appareil qui ne se reconnecte jamais ; **aucun chiffrement applicatif n'est annoncé** (clé locale non défendable) ; la protection repose sur la minimisation, la durée, l'isolation et le verrouillage du terminal.
7. **Jeux de données** vérifiés avant adoption (condensat, version, nombre) ; absence de jeu valide = « indéterminé ».

## Conséquences fonctionnelles ou techniques
- Information des personnes et base légale à étendre aux copies locales (DM-117) ; effacement/rectification effectifs à la synchronisation suivante (≤ `hard`).
- Service de purge d'événement à étendre aux tables de contrôle (code, pas de migration supplémentaire).

## Documents concernés
[lot-09-controle-hors-connexion-cadrage.md](../../lot-09-controle-hors-connexion-cadrage.md) §15, §27 ; [aipd-controle-hors-connexion-technique.md](../../aipd-controle-hors-connexion-technique.md).

## Écarts documentaires signalés
- CDC §9.3 (journal de contrôle minimal) : non retenu (DM-127).
