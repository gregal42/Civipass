# Restauration, contrôle, rejeu et remise en service

**Principe** : restaurer **efface** tout ce qui a été écrit depuis la sauvegarde (demandes de droits, clôtures, gels, audit) et **réintroduit** les données purgées depuis. Aucun rejeu automatique : le rejeu est un acte explicite, approuvé par écrit.

## Étapes techniques
1. **Maintenance d'abord** : `php bin/console app:ops:maintenance --reason="restauration <ticket>"` puis `systemctl stop 'civipass-*.timer'`. Toute tâche planifiée est refusée (code 75).
2. **Conserver les fichiers courants** : le registre de purge (`RETENTION_REGISTER_PATH`) et le fichier d'ancrage (`RETENTION_ANCHOR_PATH`) **ne sont pas restaurés**. En faire une copie datée avant toute opération.
3. **Restaurer** la base (`db.sql.gz`) et, si nécessaire, le stockage. Ne pas restaurer les fichiers du registre.
4. **Contrôler** : `php bin/console app:retention:restore-check` (ajouter `--external-anchors=<copie externe>` avec les points d'ancrage reçus par courriel ou le collecteur distant). Lire :
   - `PROOF_PROBLEM` : preuve externe absente, chaîne rompue ou ancrage incohérent → **aucun rejeu**, voir [incident-securite.md](incident-securite.md) ;
   - `REINTRODUCED_DOSSIER` / `REINTRODUCED_SHARED` `REPLAYABLE` : à rejouer ; `BLOCKED_*` : voir ci-dessous ;
   - `REINTRODUCED_EVENT` : suppression d'événement à **refaire par l'administrateur de l'organisatrice dans l'écran** (acte humain) ;
   - `POSSIBLE_DATA_LOSS`, `EVENT_MISSING`, `MUNICIPALITY_MISSING` : **données postérieures à la sauvegarde perdues** ;
   - `FILES_MISSING` : pièces perdues (les fichiers purgés ne reviennent pas) ; `COUNT_MISMATCH`, `PURGED_BUT_PRESENT` : incident.
5. **Réparer le registre** (seulement ce qui est autorisé) : `app:retention:register-check --repair --adopt --operator=<id> --approval-ref=<réf>` (ajoute au fichier une ligne SQL manquante ; réinscrit en base une ligne du fichier dont les données sont absentes). Une chaîne rompue n'est **jamais** réparée.
6. **Simuler le rejeu** : `app:retention:replay --operator=<id> --approval-ref=<réf>` (simulation par défaut, aucune écriture). Vérifier les effectifs.
7. **Rejouer** (si approuvé) : `RETENTION_PURGE_ENABLED=1` dans l'environnement du service **pour la durée du rejeu seulement**, puis `app:retention:replay --operator=<id> --approval-ref=<réf> --report=<empreinte du rapport> --execute`. Chaque dossier est recontrôlé sous verrou (événement clos, gel, demande de droits). Relancer jusqu'à « rejoués = 0 » (idempotent). Remettre `RETENTION_PURGE_ENABLED=0`.
8. **Remise en service** : `app:ops:release --operator=<id> [--acknowledge-loss=<réf>]`. La commande **refuse** tant qu'une anomalie bloquante subsiste. Puis `systemctl start civipass-*.timer` (liste de [remise-en-service.md](remise-en-service.md)).

## Cas particuliers
- **`BLOCKED_EVENT_NOT_CLOSED`** : la restauration a rouvert l'événement. Le rejeu est refusé. L'organisateur refait la clôture par le circuit normal (c'est un acte opérationnel : aucun droit n'est réactivé par cette procédure), puis nouveau contrôle.
- **`BLOCKED_HOLD`** : un gel actif existe dans l'état restauré ; sa levée (antérieure à la purge) a pu être perdue. **Décision de la commune** (confirmer par écrit que le gel avait été levé) ; sans confirmation, le dossier est conservé.
- **`BLOCKED_RIGHTS_OPEN`** : une demande de droits ouverte concerne le dossier. Décision de la commune sur la demande.
- **`BLOCKED_DOSSIERS_REMAIN`** : un dossier d'une autre commune subsiste ; les données partagées sont conservées (règle multicommunale).

## Décisions juridiques / métier
- **Autoriser le rejeu** (instruction écrite et référence) : l'autorité compétente de chaque commune concernée / DPO ; **CWS exécute, ne décide pas**.
- Reconnaissance des pertes postérieures à la sauvegarde (`--acknowledge-loss`) : responsable de traitement ; informer les personnes si nécessaire (violation de données ? **DPO**).
- Gel perdu, demande de droits perdue ou retrouvée : commune.
