# LOT 05.6.7 — Exploitation, restauration et reprise après incident : cadrage (12 novembre 2026)

**Statut : VALIDÉ le 12 novembre 2026 (A1 à A4, avec précautions) ; RÉALISÉ en local, non poussé (voir §11).** Aucune migration, aucune tâche installée. Références : [rgpd-politique-conservation.md](rgpd-politique-conservation.md) §4.5 et §8, [lot-05-6-4-cadrage.md](lot-05-6-4-cadrage.md), [lot-05-6-6-cadrage.md](lot-05-6-6-cadrage.md), [exploitation-notifications.md](exploitation-notifications.md), `ops/nginx/`. Cible de production : **VPS, nginx natif** (pas de Traefik) ; le dépôt **ne configure rien sur l'hôte** (comme pour le distributeur de notifications). Les durées et politiques sont des **hypothèses**, jamais des validations juridiques. Interrupteurs inchangés : `PUBLIC_VERIFICATION_ENABLED=0`, `RIGHTS_ERASURE_EXECUTION_ENABLED=0`, `RETENTION_PURGE_ENABLED=0`.

## 1. Architecture opérationnelle retenue (sans infrastructure nouvelle)

- **Commandes Symfony** (déjà là ou à compléter) lancées par des **timers systemd** du VPS, via un **enveloppe unique** `ops/bin/run-job` (verrou `flock` par tâche, délai maximal, journal `journald`, code de sortie, **battement de cœur** `var/ops/heartbeat/<tâche>.json` écrit seulement en cas de succès). Fichiers fournis dans `ops/systemd/` et `ops/backup/` **mais jamais installés par le dépôt** ; les tâches destructives sont livrées dans `ops/systemd/optional/`, **non activées**.
- **Supervision** : `OnFailure=` d'une unité d'alerte qui envoie un courriel à `OPS_ALERT_EMAIL` (compteurs et identifiants, jamais de donnée personnelle) ; `app:ops:status` signale les tâches dont le battement de cœur est trop ancien (à lancer par un contrôle externe ou un timer). Pas de nouveau service, pas de base supplémentaire.
- **Mode maintenance** : un fichier `var/ops/MAINTENANCE` fait refuser toute tâche par l'enveloppe (utilisé pendant une restauration : aucune purge ni alerte ne s'exécute sur une base en cours de remise en état).
- **Registre externe** (`var/retention/purge-register.jsonl`) : source de vérité **postérieure** aux sauvegardes ; **jamais restauré depuis une sauvegarde** ; copies horaires hors serveur en ajout seul ; recommandation d'attribut ajout seul (`chattr +a`).

## 2. Restauration et rejeu des effacements

**Principe** (rgpd §4.5) : toute restauration est suivie d'un contrôle, puis d'un **rejeu explicite** des purges déjà exécutées ; **aucun rejeu automatique ni planifié**.

1. **Détection** (`app:retention:restore-check`, lecture seule) : compare le registre externe, les enregistrements SQL et l'état réel, et classe chaque écart :

| Code | Constat | Qualification |
|---|---|---|
| `REINTRODUCED_DOSSIER` | ligne `DOSSIER` du registre dont le dossier existe de nouveau | `REPLAYABLE` si événement clos, aucun gel actif, aucune demande de droits ouverte (sauf celle qui l'a ordonné, retrouvée par `batch_id`), commune concordante ; sinon `BLOCKED_EVENT_NOT_CLOSED`, `BLOCKED_HOLD`, `BLOCKED_RIGHTS_OPEN`, `BLOCKED_MISMATCH` |
| `REINTRODUCED_SHARED` | ligne `SHARED` dont la demande existe de nouveau | rejouable seulement quand plus aucun dossier ne reste (après les dossiers) ; sinon `BLOCKED_DOSSIERS_REMAIN` |
| `REINTRODUCED_EVENT` | ligne `EVENT_DELETED` dont l'événement existe | **manuel** : suppression refaite dans l'écran par l'administrateur de l'organisatrice (acte humain, référence saisie) |
| `FILE_ONLY` | ligne du fichier absente du SQL, données absentes | `ADOPTABLE` après vérification (la ligne est réinscrite en base, jamais supprimée du fichier) |
| `SQL_ONLY` | ligne SQL absente du fichier | réparable (`--repair`, ajout) |
| `PURGED_BUT_PRESENT` | enregistrement SQL de purge mais dossier présent | **incident** (jamais réparé automatiquement) |
| `CHAIN_BROKEN` | chaîne d'empreintes du registre rompue (ligne supprimée, modifiée, réordonnée) | **incident de sécurité**, aucune réparation |
| `FILES_MISSING` / `ORPHAN_FILES` | pièce référencée sans fichier / fichier sans pièce | perte de données (incident) / mise en quarantaine manuelle, jamais de suppression automatique |
| `COUNT_MISMATCH` | pour un événement clos aux compteurs exacts : `dossiers présents + purgés enregistrés ≠ dossiers à la clôture` | suppression non enregistrée possible (incident) |

2. **Rejeu** (`app:retention:replay`, simulation par défaut) : n'agit que sur les éléments `REPLAYABLE` du rapport **présenté**, identifié par son empreinte (`--report=<empreinte>` : un état qui a changé depuis est refusé) ; exige `--execute`, `--approval-ref=<référence d'approbation>` (tracée dans l'audit) et `RETENTION_PURGE_ENABLED=1`. Il passe par le **service de purge existant en mode anticipé** : mêmes contrôles sous verrou (clôture, gel, autre demande de droits ouverte), **une transaction par dossier**, dossiers d'abord puis données partagées **seulement quand plus aucun dossier ne reste** (règles multicommunales du 05.6.4 inchangées), idempotent, reprenable, écriture d'une ligne de rejeu (l'enregistrement d'origine est conservé). Une demande d'effacement d'origine encore ouverte est marquée exécutée.
3. **Garanties** : aucune donnée n'est supprimée sans ligne de registre ; aucune ligne n'est supprimée pour masquer un écart ; une restauration antérieure à une **clôture** laisse l'événement ouvert : le rejeu est refusé tant que la clôture n'est pas refaite par le circuit normal.

## 3. Réconciliation des registres

`app:retention:register-check` (existant) est étendu : vérification de la **chaîne d'empreintes** (format v2 : numéro de séquence et empreinte de la ligne précédente), qualification des écarts du tableau ci-dessus, **code de sortie non nul** et alerte si un écart n'est pas explicitement réparable. **Réparations autorisées** : ajouter au fichier une ligne SQL manquante ; réinscrire en base une ligne du fichier après vérification (`ADOPTABLE`). **Interdit** : supprimer ou réécrire une ligne, « normaliser » une anomalie, rejouer sans rapport.

## 4. Commandes et scripts à livrer

`app:retention:restore-check`, `app:retention:replay`, `app:retention:register-check` (étendue), `app:ops:integrity-check` (fichiers/base, compteurs de clôture, lecture seule), `app:ops:status`, `app:ops:heartbeat`, `app:ops:notify-failure` ; `ops/bin/run-job`, `ops/bin/backup.sh` (base `mysqldump --single-transaction`, stockage, copie du registre, sommes de contrôle, rotation paramétrée), `ops/bin/verify-backup.sh` (restauration dans une base temporaire et comparaison, comme pour les migrations) ; `ops/systemd/*.service|*.timer`. **Aucune migration** : battements de cœur en fichiers, chaîne dans le fichier du registre (aucune donnée de production à convertir).

## 5. Fréquences proposées

| Tâche | Fréquence | À la mise en service |
|---|---|---|
| Sauvegarde (base, stockage, registre) · vérification de sauvegarde | quotidienne · hebdomadaire | **activées** |
| Copie hors serveur du registre | horaire | **activée** |
| `register-check` · `integrity-check` · `ops:status` | quotidienne · quotidienne · 15 min | **activées** |
| Distributeur de notifications (existant) · rapport de retards de droits | 1 min · quotidienne | **activés** |
| Simulation de purge (`app:retention:purge` sans `--execute`) | hebdomadaire | **activée** (aucune écriture) |
| Alerte de dormance · avis de conservation | quotidienne | **non activés** avant validation DPO et recette |
| Rapport de comptes candidats | mensuel, **manuel** (opérateur nommé) | aucun timer |
| Purge réelle · exécution d'effacement · rejeu | **jamais planifiés** | `optional/`, interrupteurs à `0` |

## 6. Runbooks (`docs/runbooks/`)

Sauvegarde · restauration (mode maintenance, restauration, **vérification**, rejeu, levée de la maintenance) · contrôle de cohérence · incident de purge · incident de notification · incident de sécurité (chaîne rompue, accès suspect) · **liste de vérification avant remise en service**. Chaque runbook sépare les **étapes techniques** (commandes, contrôles, critères de réussite) des **décisions juridiques ou métier** (qui valide un rejeu, un gel perdu, une demande de droits retrouvée) et nomme le décideur.

## 7. Recette opérationnelle et tests

Scénarios (état antérieur restauré + registre postérieur, simulé en test ; **exercice réel** `mysqldump` → base temporaire en recette manuelle) : sauvegarde ancienne puis purges ; registre SQL absent ou incomplet ; registre JSONL incomplet, modifié, tronqué (chaîne) ; panne au milieu du rejeu puis reprise ; relance et idempotence ; données partagées entre deux communes (ordres A puis B, B puis A) ; demande de droits ouverte ; gel actif perdu ou présent ; événement rouvert par la restauration ; reprise **sans modification de droits** (aucune autorisation, aucun statut ni rôle changé) ; courses réelles (deux rejeux, deux lancements d'une même tâche, tâche pendant la maintenance) ; alertes sans donnée personnelle ; unités fournies : aucune tâche destructive hors `optional/`.

## 8. Limites et risques résiduels

Une restauration **efface** ce qui a été écrit depuis la sauvegarde : **demandes de droits, exports, clôtures, gels et audit postérieurs sont perdus** (fréquence de sauvegarde = perte maximale tolérée, à fixer) ; les **fichiers purgés ne reviennent pas** (le rejeu supprime des références, jamais des fichiers perdus) ; une perte simultanée du registre externe **et** du registre SQL est indétectable sans copies hors serveur ; une suppression hors application n'est détectée que par `COUNT_MISMATCH` (événements aux compteurs exacts) ; les copies de sauvegarde conservent les données purgées jusqu'à rotation (§4.5) ; la queue du fichier tronquée n'est détectée que par comparaison avec le SQL et les copies.

## 9. Validations préalables à la production

**Juridiques (DPO, collectivités)** : politique de sauvegarde (§10 A1) et durée de conservation des copies ; **qualification du rejeu par CWS** (sous-traitant, exécution d'effacements déjà décidés par les communes) et clause correspondante ; traitement d'un gel ou d'une demande de droits perdus par restauration ; durée de conservation du registre des purges et des demandes. **Opérationnelles** : installation des timers sur le VPS, relais d'envoi et adresse `OPS_ALERT_EMAIL`, destination hors serveur des copies, attribut ajout seul, **exercice de restauration réel** sur copie de production, seuils d'alerte, astreinte. Aucune activation destructive avant ces validations.

## 10. Arbitrages nécessaires (défaut si aucune réponse : recommandation)

1. **A1 — Politique de sauvegarde** (rgpd §4.5) : **(a)** rotation courte (paramètre, 35 jours) **et (b)** rejeu obligatoire après toute restauration (**recommandé**, défense en profondeur) ; ou (a) seule, ou (b) avec conservation longue.
2. **A2 — Registre inviolable** : format v2 avec **chaîne d'empreintes** (**recommandé**, aucune migration, aucune donnée de production à convertir) ; ou format actuel sans détection d'altération.
3. **A3 — Qui exécute et approuve un rejeu** : **opérateur CWS** (super-administrateur) avec **référence d'approbation** écrite de la collectivité ou du DPO, tracée (**recommandé**) ; ou action de l'administrateur de chaque commune dans l'application (plus lourd, dossiers partagés). La suppression d'un événement rejouée reste **manuelle** dans les deux cas.
4. **A4 — Tâches activées à la mise en service** : celles du tableau §5 (**recommandé**) ; ou activer aussi l'alerte de dormance et l'avis de conservation dès la recette.

Confirmations implicites : aucune migration ; aucune tâche destructive activée ; interrupteurs à `0` ; aucun push ni déploiement.

## 11. Réalisation et précisions validées (12 novembre 2026)

- **A1** : rotation de **35 jours** (`BACKUP_RETENTION_DAYS`, hypothèse à valider), vérification hebdomadaire par restauration dans une base temporaire, rejeu obligatoire après toute restauration.
- **A2 — registre v2 chaîné** : chaque ligne porte `v`, `seq` et `prev` (SHA-256 de la ligne précédente) ; écriture sous verrou, jamais au-dessus d'une queue illisible. **Ancrage** : `app:retention:anchor` inscrit l'empreinte de tête (et des **repères monotones** de la base : dernier audit, comptes, communes, demandes de droits) dans `RETENTION_ANCHOR_PATH` (lui-même chaîné), la journalise et l'envoie à `OPS_ALERT_EMAIL` ; il refuse d'ancrer une chaîne rompue. `restore-check` sait confronter une **copie externe** des points. **Le registre n'est PAS qualifié d'« inviolable »** : l'indépendance de l'ancrage dépend de l'exploitation (fichier hors de portée du registre, collecteur distant, boîte courriel conservée hors serveur) et ne peut pas être garantie par l'application ; sans copie externe, une réécriture complète du registre ET du fichier d'ancrage local n'est pas détectée (testé et documenté). Aucune nouvelle infrastructure lourde : fichiers, journal, courriel.
- **A3 — rejeu** : `app:retention:replay` réservé à un **opérateur CWS** (super-administrateur actif), **référence d'approbation obligatoire** (instruction écrite de l'autorité compétente, tracée dans l'audit) ; CWS ne prend aucune décision juridique. Simulation par défaut ; exécution avec l'**empreinte du rapport présenté**, une **preuve externe saine** (registre présent et chaîné, ancrages concordants, copie externe si fournie), `RETENTION_PURGE_ENABLED=1`, un verrou d'exécution unique, et le recontrôle sous verrou de chaque dossier (clôture, gel, autre demande de droits ouverte, concordance commune/demande) par le service de purge en mode anticipé. Idempotent, reprenable, une transaction par dossier, données partagées seulement après le dernier dossier. **Aucune suppression d'événement** (qualifiée `MANUAL`). Les enregistrements d'origine sont réinscrits en base avec leur identifiant ; les lignes de rejeu s'ajoutent au registre.
- **A4 — planification** : unités dans `ops/systemd/` (non destructives : sauvegarde, vérification, ancrage, cohérence, intégrité, retards de droits, **simulation** de purge, distributeur, supervision) ; avis de conservation, alerte de dormance et purge réelle dans `ops/systemd/optional/`, **non activés** ; rejeu et effacement planifiés **nulle part**. **Livrées, jamais installées** ; `systemd-analyze verify` passe ; tests statiques des unités.
- **Restauration** : `restore-check` (lecture seule) qualifie réintroductions, preuve, pertes possibles (repères monotones en régression, événements et communes cités mais absents, opérations du registre postérieures au dernier audit), fichiers manquants, compteurs de clôture, enregistrements incohérents. **Les constats de perte sont consignés** (`var/ops/loss-findings.json`) et survivent au rejeu ; `app:ops:release` ne lève la maintenance (`var/ops/MAINTENANCE`, qui bloque `run-job`) que sans anomalie bloquante et avec la reconnaissance écrite des pertes. Aucun droit, statut ni autorisation n'est réactivé (testé).
- **Enveloppe `ops/bin/run-job`** : maintenance (code 75), verrou `flock` par tâche, délai maximal, battement de cœur seulement en cas de succès ; supervision `app:ops:status` (battements en retard, jamais exécutés, maintenance oubliée > 24 h) et alerte `OnFailure` sans donnée personnelle.
- **Runbooks** : [`docs/runbooks/`](runbooks/README.md) (sauvegarde, restauration, cohérence, incidents de purge, de notification et de sécurité, remise en service), étapes techniques séparées des décisions juridiques ou métier.
- **Aucune migration SQL.** Interrupteurs inchangés (`0`).
- **Limites supplémentaires** : la détection d'orphelins du stockage (fichier sans pièce) reste manuelle (`find`, jamais de suppression automatique) ; les repères de perte ne détectent que ce qui a été ancré avant l'incident (fréquence horaire) ; une restauration plus récente que le dernier ancrage et sans purge postérieure peut ne laisser aucun signe ; la mise à jour du registre après un purge manuelle d'événement ou de droits dépend de l'ancrage horaire.
