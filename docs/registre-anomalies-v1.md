# Registre consolidé des anomalies V1.0 (état au 19 novembre 2026)

Consolide les constats des sous-lots LOT 11.1, 11.2, 11.3 et de la préparation du kit d'installation. **Aucune correction n'est engagée** : chaque correction fera l'objet d'un sous-lot cadré et validé. Les détails (preuves, correction recommandée) restent dans les documents sources, qui font foi ; ce tableau n'en est que l'index priorisé. Les constats de sécurité sont tenus dans [registre-securite.md](registre-securite.md).

**Priorités** : **P0** empêche une installation fonctionnelle ou expose des données sans contournement ; **P1** à lever avant le pilote ; **P2** amélioration ou dette, après le pilote sauf décision contraire ; **P3** information. Règle du périmètre figé : seuls P0 et P1 sont corrigés, sauf autorisation explicite.

Recoupements entre flux (dédoublonnés) : 11.2-F1 = SEC-006 ; 11.2-F2 = SEC-003 ; 11.2-F3 = SEC-002 ; 11.2-F13 = SEC-005 ; 11.3-F01 = SEC-004 ; C-06 complète SEC-004. Gravités retenues : SEC-004 et SEC-006 relevées à Élevée (voir le registre de sécurité).

## Correctif A (19 novembre 2026) — corrigés en développement

C-01, C-02, C-03, C-04, SEC-002, SEC-005, SEC-013 : corrigés et testés (voir les commits « correctif A »). Restent à **valider sur le serveur de recette** : en-têtes réels derrière nginx, `check-deployment.sh` sur un `.env` de production, `CONTROL_HOST` et échec fermé, installation depuis l'archive. Les lignes ci-dessous les conservent pour la traçabilité, marquées « corrigé ».

## Correctif B (19 novembre 2026)

Corrigés en développement : **SEC-003, SEC-004, SEC-014, C-09** (voir les commits « correctif B »). **SEC-016** et **SEC-020** : corrigés en développement après arbitrage A du 19 novembre 2026 (validation CWS du premier administrateur ; jetons sans clair). **SEC-006** : corrigé en développement (TOTP obligatoire, clé dédiée et rotation). **SEC-015** : 72 h conservées, vérification séparée documentée, risque résiduel accepté pour le pilote. Migrations locales (`civipass_test` et `civipass`) uniquement, aucune migration distante.

## P0

| ID | Constat | Source | Statut |
|---|---|---|---|
| C-01 | ✅ *corrigé en développement* — `assets/vendor/` est ignoré par Git : le scanneur jsQR du contrôle hors connexion n'est dans aucun commit ; un clone propre ne le contient pas | [constats-kit](exploitation/constats-kit.md) | Ouvert (contournement : `ops/install/build-release.sh` ; correction recommandée : versionner `assets/vendor/jsqr/`) |

## P1

| ID | Constat | Gravité | Source |
|---|---|---|---|
| SEC-006 | ✅ *corrigé en développement* — Pas de second facteur pour les super-administrateurs CWS (décision S3) | Élevée | registre sécurité ; 11.2 F1 |
| SEC-004 | ✅ *corrigé en développement* — Sauvegardes non chiffrées avant copie hors serveur (+ C-05 : clé `NOTIFICATION_KEYRING` et environnement hors sauvegarde ; C-06 : copie hors serveur à outiller, outil proposé `offsite-sync.sh`) | Élevée | registre sécurité ; 11.3 F01 ; constats-kit |
| SEC-002 | ✅ *corrigé en développement* — En-têtes de sécurité absents sur l'origine de gestion | Moyenne | 11.1 ; 11.2 F3 |
| SEC-003 | ✅ *corrigé en développement* — Aucune limitation de débit à la soumission du dépôt public (accusé de réception envoyé à une adresse arbitraire) | Moyenne | 11.1 ; 11.2 F2 |
| SEC-005 | ✅ *corrigé en développement* — `check-deployment.sh` ne contrôle ni `APP_SECRET`, ni base, ni SMTP réel, ni trousseau | Moyenne | 11.1 ; 11.2 F13 |
| SEC-013 | ✅ *corrigé en développement* — Garde-fous G1–G3 inactifs sans `CONTROL_HOST` ; activation de la PWA possible sur l'origine de gestion | Moyenne | 11.2 F4 |
| SEC-014 | ✅ *corrigé en développement* — Limiteurs : échec d'écriture du cache ignoré silencieusement | Moyenne | 11.2 F5 |
| SEC-015 | Secret d'appareil volé utilisable partout, durée par défaut 72 h | Moyenne | 11.2 F6 |
| SEC-016 | Lien d'invitation « contact » : prise de contrôle d'une commune sans administrateur (arbitrage à rendre) | Moyenne | 11.2 F7 |
| SEC-020 | Jeton de contrôle `permit_verification_token.token` stocké en clair | Moyenne | 11.3 F06 |
| C-02 | ✅ *corrigé en développement* — `composer install` télécharge Stimulus/Turbo depuis un CDN (installation non reproductible hors ligne) | Élevée (kit) | constats-kit |
| C-03 | ✅ *corrigé en développement* — Aucun réglage PHP des tailles d'envoi dans `ops/` (défauts 2 Mo / 8 Mo, pièces de 10 Mo en échec) | Moyenne | constats-kit |
| C-04 | ✅ *corrigé en développement* — Chemins incohérents `/opt/civipass` / `/var/www/civipass/current/public` | Moyenne | constats-kit |
| C-07 | Supervision non indépendante de l'application (même SMTP ; pas de surveillance du certificat ni du disque) | Moyenne | constats-kit |
| SEC-001 | Correctif à valider sur le serveur HTTPS cible | Moyenne | registre sécurité |
| RGPD | Durées de conservation à valider (toutes provisoires) ; décision d'activer ou non `RETENTION_PURGE_ENABLED` ; absence d'exécution de suppression des comptes agents inactifs ; autres écarts F02–F11 | Moyenne | [lot-11-3-dossier-rgpd.md](lot-11-3-dossier-rgpd.md) §2 et §7 |
| TXT | Libellé juridique du refus (T4) et consigne « Contrôle » (T5) à valider | — | lot-10-textes-a-valider.md |
| REC | Recettes non faites : SMTP réel, 09.7 smartphones, charge, restauration réelle, Nginx réel | — | [plan SMTP](recette/plan-recette-smtp.md), [plan 09.7](recette/plan-recette-09-7.md) |

## P2

| ID | Constat | Source |
|---|---|---|
| SEC-007 | `no-store` absent du formulaire public et de pages d'instruction | 11.1 |
| SEC-008 | CSRF vérifié avant l'autorisation sur « refus informé » (exception nommée dans le test de parcours à retirer à la correction) | 11.1 |
| SEC-009 | Route d'annulation d'un code d'activation sans test HTTP dédié | 11.1 |
| SEC-017 | Références d'événement séquentielles (énumérables) | 11.1 ; 11.2 F8 |
| SEC-018 | Pas d'antivirus ; format DOC accepté | 11.2 F9 |
| SEC-019 | Masquage des journaux (`Stringable`), rotation du journal PHP hors kit, mot de passe oublié non mesuré | 11.2 F10–F11 |
| C-05 / C-08 | Séquestre du trousseau ; volume des sauvegardes (archive complète, 35 j) | constats-kit |
| C-09 | ✅ *corrigé en développement* — `JobRegistry` figé : la copie hors serveur (`ops/backup/systemd/`) ne passe pas par `run-job`, donc ni verrou ni battement supervisé ; les tests `OpsUnitsTest` l'interdisent dans `ops/systemd/` | constats-kit |
| C-13 | Dimensionnement non mesuré sous charge | constats-kit |
| TEST | Tests intermittents `RevocationConcurrencyTest` et `RestoreReplayTest` | ecarts-techniques-connus.md |

## P3

SEC-010 (règle « mémorisé » résiduelle), SEC-011 (18 mises à jour, jsQR non maintenu, aucun avis de sécurité), SEC-012 (sauvegardes de développement lisibles localement), C-10 à C-12 (Docker limité au développement, MySQL 8.4 hors distributions, nginx ≥ 1.25.1 sur Debian 13 seulement).

## Limites de ces constats

Mesures d'en-têtes faites en HTTP local : HSTS et TLS à vérifier en 11.4. Recherche de secrets par motifs (`gitleaks` et `trufflehog` indisponibles). Scripts du kit vérifiés par `bash -n` seulement, jamais exécutés. Les constats RGPD « sans purge constatée » reposent sur une recherche textuelle et sont à confirmer par test.
