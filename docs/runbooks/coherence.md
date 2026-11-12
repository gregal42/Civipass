# Vérification de cohérence (routine)

**Objectif** : détecter tôt une altération, une perte ou une suppression non enregistrée. Tout est en **lecture seule**.

## Étapes techniques (planifiées, voir `ops/systemd/`)
- `app:retention:anchor` (horaire) : inscrit l'empreinte de tête du registre dans le fichier d'ancrage, la journalise et l'envoie à `OPS_ALERT_EMAIL`. Il **refuse** d'ancrer une chaîne rompue (alerte).
- `app:retention:register-check` (quotidien) : lignes SQL sans fichier, lignes de fichier sans SQL, rupture de chaîne, ancrage incohérent. Code non nul en cas d'écart.
- `app:ops:integrity-check` (quotidien) : même contrôle que `restore-check` en routine (preuve, fichiers manquants, compteurs de clôture, pertes).
- `app:ops:status` (15 min) : battements de cœur ; maintenance oubliée (> 24 h).
- `app:rights:overdue-report` (quotidien) : demandes de droits en retard.

## Interprétation
- **`SQL_ONLY` / `FILE_ONLY`** : écriture du fichier non atomique ; réparable sur approbation (`--repair`, `--adopt`).
- **`CHAIN_BROKEN`, `ANCHOR_MISMATCH`, `CHAIN_SHORTER_THAN_ANCHOR`** : **incident de sécurité**, ne rien réparer, voir [incident-securite.md](incident-securite.md).
- **`COUNT_MISMATCH`** : un dossier manque sans trace de purge : incident de purge ([incident-purge.md](incident-purge.md)).

## Décisions
Aucune anomalie n'est effacée pour la masquer ; toute réparation porte une référence d'approbation. Qualification juridique d'un écart : DPO.
