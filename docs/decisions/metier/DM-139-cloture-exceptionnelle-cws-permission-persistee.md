# DM-139 — Clôture exceptionnelle CWS : permission persistée

- **Identifiant** : DM-139
- **Thème** : Administration CWS — LOT 08.6
- **Statut** : Validée (schéma et migration : à approuver avant exécution)
- **Validation** : décisions du commanditaire du 17 novembre 2026 (option A du LOT 08.6)
- **Décisions remplacées** : Aucune (précise DM-065 et DM-074 : persistance des habilitations CWS).

## Décision
La permission `CWS_FORCE_CLOSE_EVENT` est **persistée** (attribution explicite et tracée, historique, révocation). La clôture exceptionnelle exige : **autorisation explicite**, **ressaisie du mot de passe**, **motif obligatoire**, **confirmation de l'opération**, **audit complet**, et une **gestion cohérente des conséquences** sur les autorisations et le contrôle terrain (laissez-passer inopérants, jeu hors connexion vide marqué clos à la synchronisation suivante). Même moteur que la clôture normale (DM-092) ; aucun accès aux dossiers (DM-005) ; sans impersonation.

## Conséquences
Schéma proposé : [lot-08-6-schema.md](../../lot-08-6-schema.md) (une table `identity_cws_permission`) ; **aucune migration sans approbation du schéma**.

## Documents concernés
[lot-08-6-schema.md](../../lot-08-6-schema.md), DM-005, DM-061, DM-065, DM-074, DM-092, DM-120.
